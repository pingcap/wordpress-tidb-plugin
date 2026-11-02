<?php
/**
 * Minimal WordPress stubs so the plugin can be loaded without WordPress.
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_DEBUG', true );

$GLOBALS['wtc_test_filters'] = [];

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['wtc_test_filters'][ $tag ][] = $callback;
	return true;
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted_args );
}

function has_filter( $tag ) {
	return ! empty( $GLOBALS['wtc_test_filters'][ $tag ] );
}

function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['wtc_test_filters'][ $tag ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function register_activation_hook( $file, $callback ) {
	add_action( 'activate_tidb-compatibility', $callback );
}

function register_deactivation_hook( $file, $callback ) {
	add_action( 'deactivate_tidb-compatibility', $callback );
}

function __return_true() {
	return true;
}

function __return_false() {
	return false;
}

function get_current_user_id() {
	return 7;
}

class WP_Query {
	public $request = '';
}

class WTC_Test_WPDB {
	public $server_info = '8.0.11-TiDB-v8.5.1';
	public $last_error  = '';
	public $last_query  = '';

	public function db_server_info() {
		return $this->server_info;
	}
}

$GLOBALS['wpdb'] = new WTC_Test_WPDB();

require dirname( __DIR__, 2 ) . '/tidb-compatibility.php';

// Hooks the plugin registers when it is loaded, before any test resets them.
$GLOBALS['wtc_test_boot_filters'] = $GLOBALS['wtc_test_filters'];
