<?php
/**
 * MPS Payments in the block checkout, WooCommerce's default checkout since 8.3.
 *
 * @package MpsPayments
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the payment method with the block checkout. The payment itself still runs through
 * Mps_Payments_Gateway::process_payment, so both checkouts behave the same.
 */
final class Mps_Payments_Blocks extends AbstractPaymentMethodType {

	/**
	 * The payment method's id, the same as the gateway's.
	 *
	 * @var string
	 */
	protected $name = Mps_Payments_Gateway::ID;

	/**
	 * The configured gateway, null when WooCommerce has not loaded it.
	 *
	 * @var Mps_Payments_Gateway|null
	 */
	private $gateway;

	/**
	 * Finds the configured gateway.
	 */
	public function initialize() {
		$gateways      = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * Offered exactly when the classic checkout would offer it.
	 *
	 * @return bool
	 */
	public function is_active() {
		return null !== $this->gateway && $this->gateway->is_available();
	}

	/**
	 * The script that registers the payment method with the block checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'mps-payments-blocks',
			MPS_PAYMENTS_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			MPS_PAYMENTS_VERSION,
			true
		);

		return array( 'mps-payments-blocks' );
	}

	/**
	 * What the script shows: the title and description from the settings.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => null !== $this->gateway ? $this->gateway->get_title() : '',
			'description' => null !== $this->gateway ? $this->gateway->get_description() : '',
			'supports'    => null !== $this->gateway ? array_values( $this->gateway->supports ) : array(),
		);
	}
}
