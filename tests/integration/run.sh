#!/usr/bin/env bash
# Integration test: runs the plugin on WordPress + TiDB and checks that
# pagination totals match what an anonymous visitor can actually see.
#
#   tests/integration/run.sh            # run and tear down
#   KEEP=1 tests/integration/run.sh     # leave the environment running
#
# Images and port can be overridden with TIDB_IMAGE, WP_IMAGE, WP_CLI_IMAGE
# and WP_PORT.
set -euo pipefail

cd "$(dirname "$0")"
export WP_PORT="${WP_PORT:-18090}"
BASE="http://localhost:${WP_PORT}"
FAILED=0

compose() { docker compose "$@"; }
wp() { compose run --rm -T cli "$@" 2>&1 | grep -v -e '^ *Container ' -e '^time=' || true; }

cleanup() {
	if [ "${KEEP:-0}" != "1" ]; then
		compose down -v --remove-orphans >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

check() { # name expected actual
	if [ "$2" == "$3" ]; then
		echo "PASS $1: $3"
	else
		echo "FAIL $1: expected '$2', got '$3'"
		FAILED=1
	fi
}

echo "== Starting TiDB and WordPress"
compose down -v --remove-orphans >/dev/null 2>&1 || true
compose up -d tidb wordpress

for i in $(seq 1 60); do
	if compose run --rm -T --entrypoint mariadb cli -h tidb -P 4000 -uroot --skip-ssl \
		-e 'CREATE DATABASE IF NOT EXISTS wordpress' >/dev/null 2>&1; then
		break
	fi
	[ "$i" == 60 ] && { echo "TiDB did not become ready"; exit 1; }
	sleep 2
done
for i in $(seq 1 60); do
	compose exec -T wordpress test -f /var/www/html/wp-config.php && break
	[ "$i" == 60 ] && { echo "WordPress files were not created"; exit 1; }
	sleep 2
done

echo "== Installing WordPress"
wp core install --url="$BASE" --title=wtc --admin_user=admin \
	--admin_password="wtc-$RANDOM-$RANDOM" --admin_email=admin@example.com --skip-email
ACTIVATE_LOG="$(wp --user=admin plugin activate tidb-compatibility)"
echo "$ACTIVATE_LOG"

wp eval '
$a = wp_insert_term( "cat-a", "category" )["term_id"];
$b = wp_insert_term( "cat-b", "category" )["term_id"];
$posts = [
	"visible-1"                 => "publish",
	"visible-2"                 => "publish",
	"hidden-by-where-request"   => "publish",
	"hidden-by-clauses-request" => "publish",
	"literal"                   => "publish",
	"draft-1"                   => "draft",
	"draft-2"                   => "draft",
	"private-1"                 => "private",
];
foreach ( $posts as $title => $status ) {
	$ids[ $title ] = wp_insert_post( [ "post_title" => $title, "post_status" => $status, "post_author" => 1 ] );
}
wp_set_post_categories( $ids["visible-1"], [ $a ] );
wp_set_post_categories( $ids["visible-2"], [ $a, $b ] );
update_post_meta( $ids["visible-1"], "color", "red" );
update_post_meta( $ids["literal"], "tag", "x SQL_CALC_FOUND_ROWS y" );
update_post_meta( 1, "tag", "xy" );
update_option( "posts_per_page", 1 );
echo "seeded\n";'

for i in $(seq 1 30); do
	curl -sf -o /dev/null "$BASE/" && break
	[ "$i" == 30 ] && { echo "WordPress is not serving requests"; exit 1; }
	sleep 2
done

echo "== Anonymous REST requests"
# Visible: Hello world, visible-1, visible-2, literal.
total="$(curl -sI "$BASE/?rest_route=/wp/v2/posts&per_page=1" | tr -d '\r' | awk -F': ' 'tolower($1)=="x-wp-total"{print $2}')"
listed="$(curl -s "$BASE/?rest_route=/wp/v2/posts&per_page=100&_fields=id" | grep -o '"id":' | wc -l | tr -d ' ')"
check "X-WP-Total excludes posts hidden by *_request filters" 4 "$total"
check "X-WP-Total matches the posts returned" "$listed" "$total"

published="$(wp db query "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'post' AND post_status = 'publish'" --skip-column-names | grep -E '^[0-9]+$')"
suppressed="$(curl -s "$BASE/?rest_route=/wtc-test/v1/suppressed" | grep -o '"found_posts":[0-9]*' | cut -d: -f2)"
check "suppress_filters found_posts counts only published posts" "$published" "$suppressed"

echo "== Queries inside WordPress"
CHECKS="$(compose run --rm -T -v "$PWD/checks.php:/tmp/checks.php:ro" cli eval-file /tmp/checks.php 2>&1 | grep -E '^(PASS|FAIL|CHECKS)' || true)"
echo "$CHECKS"
grep -q '^CHECKS PASSED$' <<<"$CHECKS" || FAILED=1

echo "== Front-end pages"
for path in "/" "/?paged=2" "/?paged=4" "/?s=visible" "/?rest_route=/wp/v2/posts&per_page=2&page=2"; do
	check "GET $path" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$path")"
done

echo "== Switches and logging"
HOOKED='echo has_filter( "found_posts_query", [ "WTC_FIX_WP_SLOW_QUERY", "wtc_add_found_rows_query" ] ) ? "on" : "off";'
check "rewrite registered on TiDB" on "$(wp eval "$HOOKED")"
check "WTC_DISABLE switches the rewrite off" off "$(wp --exec='define( "WTC_DISABLE", true );' eval "$HOOKED" | tail -n 1)"
grep -q '\[TiDB Compatibility\] activated by user 1\.' <<<"$ACTIVATE_LOG" \
	&& echo "PASS activation is logged" || { echo "FAIL activation is not logged"; FAILED=1; }

db_errors="$(compose logs wordpress 2>&1 | grep -ci 'database error' || true)"
check "no WordPress database errors" 0 "$db_errors"

if [ "$FAILED" != 0 ]; then
	echo "INTEGRATION TESTS FAILED"
	exit 1
fi
echo "INTEGRATION TESTS PASSED"
