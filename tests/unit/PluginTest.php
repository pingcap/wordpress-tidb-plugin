<?php

use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase {
	private $log;

	protected function setUp(): void {
		$GLOBALS['wtc_test_filters'] = [];
		$GLOBALS['wpdb']             = new WTC_Test_WPDB();
		$this->log                   = tempnam( sys_get_temp_dir(), 'wtc' );
		ini_set( 'error_log', $this->log );
	}

	protected function tearDown(): void {
		@unlink( $this->log );
	}

	private function log_contents() {
		return (string) file_get_contents( $this->log );
	}

	private function count_query( $request ) {
		$query          = new WP_Query();
		$query->request = $request;
		return WTC_FIX_WP_SLOW_QUERY::wtc_add_found_rows_query( 'SELECT FOUND_ROWS()', $query );
	}

	/**
	 * @dataProvider strip_keyword_cases
	 */
	public function test_strips_only_leading_keyword( $sql, $expected ) {
		$this->assertSame( $expected, WTC_FIX_WP_SLOW_QUERY::wtc_remove_found_rows_query( $sql ) );
	}

	public function strip_keyword_cases() {
		return [
			'core format'             => [ "SELECT SQL_CALC_FOUND_ROWS  wp_posts.ID FROM wp_posts", 'SELECT wp_posts.ID FROM wp_posts' ],
			'lower case'              => [ 'select sql_calc_found_rows wp_posts.* FROM wp_posts', 'select wp_posts.* FROM wp_posts' ],
			'tabs and newlines'       => [ "\n SELECT\t\nSQL_CALC_FOUND_ROWS\n\tDISTINCT wp_posts.ID", "\n SELECT\t\nDISTINCT wp_posts.ID" ],
			'no keyword'              => [ 'SELECT wp_posts.ID FROM wp_posts', 'SELECT wp_posts.ID FROM wp_posts' ],
			'literal is not modified' => [
				"SELECT wp_posts.ID FROM wp_posts WHERE meta_value = 'x SQL_CALC_FOUND_ROWS y'",
				"SELECT wp_posts.ID FROM wp_posts WHERE meta_value = 'x SQL_CALC_FOUND_ROWS y'",
			],
			'only leading keyword'    => [
				"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE meta_value = 'x SQL_CALC_FOUND_ROWS y'",
				"SELECT wp_posts.ID FROM wp_posts WHERE meta_value = 'x SQL_CALC_FOUND_ROWS y'",
			],
			'keyword prefix'          => [ 'SELECT SQL_CALC_FOUND_ROWSX FROM t', 'SELECT SQL_CALC_FOUND_ROWSX FROM t' ],
		];
	}

	/**
	 * @dataProvider count_query_cases
	 */
	public function test_counts_the_executed_query_without_limit( $request, $inner ) {
		$this->assertSame( "SELECT COUNT(*) FROM ( $inner ) AS wtc_found_rows", $this->count_query( $request ) );
	}

	public function count_query_cases() {
		$base = "SELECT  wp_posts.ID\n FROM wp_posts \n WHERE 1=1 AND wp_posts.post_status = 'publish'\n GROUP BY wp_posts.ID\n ORDER BY wp_posts.post_date DESC";
		return [
			'LIMIT offset, count'       => [ "$base\n LIMIT 0, 10", $base ],
			'LIMIT count OFFSET offset' => [ "$base LIMIT 10 OFFSET 20", $base ],
			'LIMIT count'               => [ "$base LIMIT 5", $base ],
			'trailing semicolon'        => [ "$base LIMIT 0, 10;", $base ],
			'keyword is stripped'       => [ "SELECT SQL_CALC_FOUND_ROWS  wp_posts.* FROM wp_posts WHERE 1=1 LIMIT 0, 10", 'SELECT wp_posts.* FROM wp_posts WHERE 1=1' ],
			'LIMIT inside a literal'    => [
				"SELECT wp_posts.ID FROM wp_posts WHERE post_title = 'LIMIT 5' LIMIT 0, 10",
				"SELECT wp_posts.ID FROM wp_posts WHERE post_title = 'LIMIT 5'",
			],
		];
	}

	public function test_keeps_core_query_and_logs_without_trailing_limit() {
		$this->assertSame( 'SELECT FOUND_ROWS()', $this->count_query( 'SELECT wp_posts.ID FROM wp_posts WHERE 1=1' ) );
		$this->assertStringContainsString( 'kept core found_posts_query', $this->log_contents() );
	}

	public function test_count_query_never_drops_the_where_clause() {
		$sql = $this->count_query( "SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND wp_posts.post_status = 'publish' LIMIT 0, 10" );
		$this->assertStringContainsString( "wp_posts.post_status = 'publish'", $sql );
	}

	public function test_logs_failed_count_query() {
		global $wpdb;
		$wpdb->last_query = 'SELECT COUNT(*) FROM ( SELECT 1 ) AS wtc_found_rows';
		$wpdb->last_error = 'boom';
		$this->assertSame( 4, WTC_FIX_WP_SLOW_QUERY::wtc_check_found_rows_query( 4, new WP_Query() ) );
		$this->assertStringContainsString( 'count query failed: boom', $this->log_contents() );
	}

	public function test_ignores_errors_from_other_queries() {
		global $wpdb;
		$wpdb->last_query = 'SELECT FOUND_ROWS()';
		$wpdb->last_error = 'boom';
		WTC_FIX_WP_SLOW_QUERY::wtc_check_found_rows_query( 4, new WP_Query() );
		$this->assertSame( '', $this->log_contents() );
	}

	public function test_enabled_on_tidb() {
		$this->assertTrue( WTC_FIX_WP_SLOW_QUERY::wtc_is_enabled() );
	}

	public function test_disabled_on_mysql() {
		$GLOBALS['wpdb']->server_info = '8.0.36';
		$this->assertFalse( WTC_FIX_WP_SLOW_QUERY::wtc_is_enabled() );
		$this->assertStringContainsString( "inactive, database server is '8.0.36'", $this->log_contents() );
	}

	public function test_detects_tidb_without_db_server_info() {
		$GLOBALS['wpdb'] = new class() {
			public function get_var( $sql ) {
				return 'SELECT VERSION()' === $sql ? '8.0.11-TiDB-v7.5.0-serverless' : null;
			}
		};
		$this->assertTrue( WTC_FIX_WP_SLOW_QUERY::wtc_is_enabled() );
	}

	public function test_filter_overrides_detection() {
		add_filter( 'tidb_compatibility_enabled', '__return_false' );
		$this->assertFalse( WTC_FIX_WP_SLOW_QUERY::wtc_is_enabled() );

		$GLOBALS['wtc_test_filters']  = [];
		$GLOBALS['wpdb']->server_info = '8.0.36';
		add_filter( 'tidb_compatibility_enabled', '__return_true' );
		$this->assertTrue( WTC_FIX_WP_SLOW_QUERY::wtc_is_enabled() );
	}

	public function test_init_registers_filters_on_tidb() {
		WTC_FIX_WP_SLOW_QUERY::wtc_init();
		foreach ( [ 'found_posts_query', 'found_posts', 'posts_request_ids', 'posts_pre_query' ] as $tag ) {
			$this->assertTrue( has_filter( $tag ), $tag );
		}
	}

	public function test_init_registers_nothing_on_mysql() {
		$GLOBALS['wpdb']->server_info = '8.0.36';
		WTC_FIX_WP_SLOW_QUERY::wtc_init();
		$this->assertSame( [], $GLOBALS['wtc_test_filters'] );
	}

	public function test_posts_pre_query_strips_keyword_and_keeps_posts() {
		WTC_FIX_WP_SLOW_QUERY::wtc_init();
		$query          = new WP_Query();
		$query->request = 'SELECT SQL_CALC_FOUND_ROWS  wp_posts.ID FROM wp_posts LIMIT 0, 10';
		$this->assertNull( apply_filters( 'posts_pre_query', null, $query ) );
		$this->assertSame( 'SELECT wp_posts.ID FROM wp_posts LIMIT 0, 10', $query->request );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_constant() {
		$GLOBALS['wtc_test_filters'] = [];
		define( 'WTC_DISABLE', true );
		WTC_FIX_WP_SLOW_QUERY::wtc_init();
		$this->assertSame( [], $GLOBALS['wtc_test_filters'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_registers_init_and_logs_activation() {
		$GLOBALS['wtc_test_filters'] = $GLOBALS['wtc_test_boot_filters'];
		$this->assertTrue( has_filter( 'plugins_loaded' ) );
		$log = tempnam( sys_get_temp_dir(), 'wtc' );
		ini_set( 'error_log', $log );
		apply_filters( 'activate_tidb-compatibility', null );
		apply_filters( 'deactivate_tidb-compatibility', null );
		$contents = file_get_contents( $log );
		unlink( $log );
		$this->assertStringContainsString( 'activated by user 7.', $contents );
		$this->assertStringContainsString( 'deactivated by user 7.', $contents );
	}
}
