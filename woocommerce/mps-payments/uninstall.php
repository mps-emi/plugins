<?php
/**
 * Removes the plugin's settings and caches when it is deleted. Orders and their MPS references stay:
 * they are the shop's records, not the plugin's.
 *
 * @package MpsPayments
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_mps_payments_settings' );
delete_site_transient( 'mps_payments_update_info' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mps\\_payments\\_token\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mps\\_payments\\_token\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'mps_payments_check', array(), 'mps-payments' );
}
