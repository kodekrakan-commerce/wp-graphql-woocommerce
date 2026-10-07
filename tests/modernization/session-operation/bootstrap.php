<?php
namespace WPGraphQL { class AppContext {} }
namespace {
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return $text; }
function get_current_user_id() { return $GLOBALS['op_user']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function get_option( $name ) { return $GLOBALS['op_guest_checkout'] ? 'yes' : 'no'; }
function add_filter( $name, $callback, $priority = 10, $count = 1 ) { $GLOBALS['op_hooks'][$name][$priority][] = [ $callback, $count ]; }
function add_action( $name, $callback, $priority = 10, $count = 1 ) { add_filter( $name, $callback, $priority, $count ); }
function apply_filters( $name, $value, ...$args ) {
	$groups = $GLOBALS['op_hooks'][$name] ?? []; ksort( $groups );
	foreach ( $groups as $callbacks ) { foreach ( $callbacks as [ $callback, $count ] ) { $value = $callback( ...array_slice( [ $value, ...$args ], 0, $count ) ); } }
	return $value;
}
function do_action( $name, ...$args ) {
	$groups = $GLOBALS['op_hooks'][$name] ?? []; ksort( $groups );
	foreach ( $groups as $callbacks ) { foreach ( $callbacks as [ $callback, $count ] ) { $callback( ...array_slice( $args, 0, $count ) ); } }
}
}
