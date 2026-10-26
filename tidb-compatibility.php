<?php
/*
 * Plugin Name:       TiDB Compatibility
 * Description:       Optimize slow queries in WordPress.
 * Version:           1.0.3
 * Requires at least: 4.7
 * Requires PHP:      6.8.1
 * Author:            it2911
 * Author URI:        https://github.com/it2911
 * License:           GPL-3.0 license
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       tidb-compatibility
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WTC_FIX_WP_SLOW_QUERY {
        public static function wtc_init() {
                /**
                 * WP_Query
                 */
                add_filter( 'found_posts_query', [ __CLASS__, 'wtc_add_found_rows_query' ], 999, 2 );
                add_filter( 'posts_request_ids', [ __CLASS__, 'wtc_remove_found_rows_query' ], 999 );
                add_filter( 'posts_pre_query', function ( $posts, \WP_Query $query ) {
                        $query->request = self::wtc_remove_found_rows_query( $query->request );
                        return $posts;
                }, 999, 2 );
        }
        public static function wtc_remove_found_rows_query( $sql ) {
                return str_replace( ' SQL_CALC_FOUND_ROWS ', '', $sql );
        }
        /**
         * Count the rows of the query that was actually executed, instead of
         * rebuilding it from clauses captured by a filter. Rebuilt clauses can
         * be missing (suppress_filters skips 'posts_clauses') or incomplete
         * ('posts_where_request', 'posts_clauses_request' and 'posts_request'
         * run later), which made found_posts count posts the user cannot see.
         */
        public static function wtc_add_found_rows_query( $sql, WP_Query $query ) {
                $request = self::wtc_remove_found_rows_query( (string) $query->request );
                $request = preg_replace(
                        '/\s+LIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*;?\s*$/i',
                        '',
                        $request,
                        1,
                        $replaced
                );
                if ( 1 !== $replaced ) {
                        // Unknown request shape: keep the core query rather than guess.
                        return $sql;
                }
                return "SELECT COUNT(*) FROM ( $request ) AS wtc_found_rows";
        }
}
WTC_FIX_WP_SLOW_QUERY::wtc_init();
