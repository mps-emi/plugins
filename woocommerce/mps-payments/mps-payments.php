<?php
/**
 * Plugin Name:          MPS Payments for WooCommerce
 * Plugin URI:           https://www.mps-emi.com
 * Description:          Take payments in your WooCommerce shop on the MPS-hosted checkout page.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Mobile Payment Solutions
 * Author URI:           https://www.mps-emi.com
 * Developer:            Mobile Payment Solutions
 * Developer URI:        https://www.mps-emi.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          mps-payments
 * Domain Path:          /languages
 * WC requires at least: 8.3
 * WC tested up to:      11.2
 *
 * @package MpsPayments
 */

defined( 'ABSPATH' ) || exit;

define( 'MPS_PAYMENTS_VERSION', '0.1.0' );
define( 'MPS_PAYMENTS_FILE', __FILE__ );
define( 'MPS_PAYMENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPS_PAYMENTS_URL', plugin_dir_url( __FILE__ ) );

/*
 * Where this copy looks for new versions. Stamped by build.sh from PLUGIN_BASE_URL; empty in a
 * source checkout, which switches the update check off rather than pointing it somewhere made up.
 * The WooCommerce Marketplace copy ships with this empty and without the updater file: Woo.com
 * does the updating there, and a second update source would compete with it.
 */
if ( ! defined( 'MPS_PAYMENTS_UPDATE_URL' ) ) {
	define( 'MPS_PAYMENTS_UPDATE_URL', '' );
}

if ( '' !== MPS_PAYMENTS_UPDATE_URL && file_exists( MPS_PAYMENTS_DIR . 'includes/class-mps-payments-updater.php' ) ) {
	require_once MPS_PAYMENTS_DIR . 'includes/class-mps-payments-updater.php';

	( new Mps_Payments_Updater( MPS_PAYMENTS_UPDATE_URL, plugin_basename( __FILE__ ), MPS_PAYMENTS_VERSION ) )->register();
}

// The order tables and the block checkout are both WooCommerce's defaults now; say this plugin works with them.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MPS_PAYMENTS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MPS_PAYMENTS_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'mps-payments', false, dirname( plugin_basename( MPS_PAYMENTS_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once MPS_PAYMENTS_DIR . 'includes/class-mps-payments-api.php';
		require_once MPS_PAYMENTS_DIR . 'includes/class-mps-payments-gateway.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = Mps_Payments_Gateway::class;
				return $gateways;
			}
		);

		// Scheduled re-reads run outside a request that loaded the gateways, so fetch the configured instance.
		add_action(
			Mps_Payments_Gateway::CHECK_HOOK,
			static function ( $order_id, $attempt ) {
				$gateways = WC()->payment_gateways()->payment_gateways();
				if ( isset( $gateways[ Mps_Payments_Gateway::ID ] ) ) {
					$gateways[ Mps_Payments_Gateway::ID ]->run_check( (int) $order_id, (int) $attempt );
				}
			},
			10,
			2
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( MPS_PAYMENTS_FILE ),
			static function ( $links ) {
				$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Mps_Payments_Gateway::ID );
				array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'mps-payments' ) . '</a>' );
				return $links;
			}
		);
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once MPS_PAYMENTS_DIR . 'includes/class-mps-payments-blocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Mps_Payments_Blocks() );
			}
		);
	}
);
