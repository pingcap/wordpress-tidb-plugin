<?php
/**
 * Runs inside WordPress via `wp eval-file`. For each query, found_posts of a
 * paginated run must equal the number of posts of an unpaginated run.
 */

$cats = get_terms( [ 'taxonomy' => 'category', 'slug' => [ 'cat-a', 'cat-b' ], 'hide_empty' => false, 'fields' => 'ids' ] );
$cases = [
	'basic'             => [],
	'two categories'    => [ 'category__in' => $cats ],
	'tax_query AND'     => [ 'tax_query' => [ 'relation' => 'AND', [ 'taxonomy' => 'category', 'terms' => $cats[0] ], [ 'taxonomy' => 'category', 'terms' => $cats[1] ] ] ],
	'search'            => [ 's' => 'visible' ],
	'meta_query'        => [ 'meta_key' => 'color', 'meta_value' => 'red' ],
	'page 2'            => [ 'paged' => 2 ],
	'suppress_filters'  => [ 'suppress_filters' => true ],
];
$failed = false;
foreach ( $cases as $name => $args ) {
	$args    += [ 'post_type' => 'post', 'post_status' => 'publish' ];
	$paged    = new WP_Query( $args + [ 'posts_per_page' => 1 ] );
	$expected = count( ( new WP_Query( $args + [ 'nopaging' => true ] ) )->posts );
	$ok       = $paged->found_posts === $expected && $expected > 0;
	$failed   = $failed || ! $ok;
	printf( "%s found_posts: %-18s got %d, expected %d\n", $ok ? 'PASS' : 'FAIL', $name, $paged->found_posts, $expected );
}

$literal = new WP_Query( [ 'meta_key' => 'tag', 'meta_value' => 'x SQL_CALC_FOUND_ROWS y', 'posts_per_page' => 5 ] );
$titles  = implode( ',', wp_list_pluck( $literal->posts, 'post_title' ) );
$ok      = 'literal' === $titles;
$failed  = $failed || ! $ok;
printf( "%s literal meta_value is not rewritten: got '%s'\n", $ok ? 'PASS' : 'FAIL', $titles );

echo $failed ? "CHECKS FAILED\n" : "CHECKS PASSED\n";
