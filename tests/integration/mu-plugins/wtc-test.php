<?php
/**
 * Test fixtures: two posts hidden by late WP_Query filters, the way
 * membership plugins restrict content, and a REST route that runs a
 * paginated query with suppress_filters.
 */

add_filter( 'posts_where_request', function ( $where ) {
	global $wpdb;
	return $where . " AND {$wpdb->posts}.post_title <> 'hidden-by-where-request'";
} );

add_filter( 'posts_clauses_request', function ( $clauses ) {
	global $wpdb;
	$clauses['where'] .= " AND {$wpdb->posts}.post_title <> 'hidden-by-clauses-request'";
	return $clauses;
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'wtc-test/v1', '/suppressed', [
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$query = new WP_Query( [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'suppress_filters' => true ] );
			return [ 'found_posts' => $query->found_posts ];
		},
	] );
} );
