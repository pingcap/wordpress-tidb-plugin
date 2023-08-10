<?php
/*
Plugin Name: TiDB Compatibility
Description: Optimize slow queries in WordPress.
Version: 0.1
Author: Cheng Chen
 */
class FIX_WP_SLOW_QUERY {
        public static function init() {
                /**
                 * WP_Query
                 */
                add_filter( 'found_posts_query', [ __CLASS__, 'add_found_rows_query' ], 999, 2 );
                add_filter( 'posts_request_ids', [ __CLASS__, 'remove_found_rows_query' ], 999 );
                add_filter( 'posts_pre_query', function ( $posts, \WP_Query $query ) {
                        $query->request = self::remove_found_rows_query( $query->request );
                        return $posts;
                }, 999, 2 );
                add_filter( 'posts_clauses', function ( $clauses, \WP_Query $wp_query ) {
                        $wp_query->fw_clauses = $clauses;
                        return $clauses;
                }, 999, 2 );
        }
        public static function remove_found_rows_query( $sql ) {
                return str_replace( ' SQL_CALC_FOUND_ROWS ', '', $sql );
        }
        public static function add_found_rows_query( $sql, WP_Query $query ) {
                global $wpdb;
                $distinct = $query->fw_clauses['distinct'] ?? '';
                $join     = $query->fw_clauses['join'] ?? '';
                $where    = $query->fw_clauses['where'] ?? '';
                $groupby  = $query->fw_clauses['groupby'] ?? '';
                $count = 'COUNT(*)';
                if ( ! empty( $groupby ) ) {
                        $count = "COUNT( distinct $groupby )";
                }
                return "
                        SELECT $distinct $count
                        FROM {$wpdb->posts} $join
                        WHERE 1=1 $where
                ";
        }
}
FIX_WP_SLOW_QUERY::init();