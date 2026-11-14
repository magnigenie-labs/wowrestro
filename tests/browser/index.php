<?php
/** Local browser fixture; run with PHP's development server, never WordPress. */
if ( 'cli-server' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'WOWRESTRO_VERSION', 'browser-fixture' );
define( 'WOWRESTRO_URL', '/' );
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $value, $domain = '' ) { echo htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $value, $domain = '' ) { esc_attr_e( $value, $domain ); }
function admin_url() { return '/tests/browser/'; }
function current_user_can( $capability ) { return true; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>WowRestro local browser fixture</title>
	<link rel="icon" href="data:,">
	<link rel="stylesheet" href="/assets/css/wowrestro-app.css">
	<style>body { margin: 0; } .fixture-notice { padding: 8px 16px; font: 12px/1.5 sans-serif; background: #fff4cb; color: #403400; }</style>
</head>
<body>
	<div class="fixture-notice">Local UI fixture: real plugin template, JavaScript and CSS; REST and database are mocked in localStorage. No WordPress/WooCommerce integration is exercised. <a href="?reset=1">Reset fixture data</a></div>
	<div id="wowrestro-app"></div>
	<template id="wowrestro-app-template"><?php require ABSPATH . 'includes/views/app-template.php'; ?></template>
	<script src="/tests/browser/fixture.js"></script>
	<script src="/assets/js/lib/qrcode-generator/qrcode.js"></script>
	<script src="/assets/js/wowrestro-app.js"></script>
</body>
</html>
