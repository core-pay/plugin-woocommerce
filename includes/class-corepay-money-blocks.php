<?php
/**
 * CorePay Money WooCommerce Blocks integration.
 *
 * @package CorePayMoneyWooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers CorePay Money with the block-based checkout.
 */
final class CorePay_Money_Blocks extends AbstractPaymentMethodType {
	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = 'corepay_money';

	/**
	 * Gateway instance.
	 *
	 * @var CorePay_Money_Gateway|null
	 */
	private $gateway;

	/**
	 * Initialize block integration settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_corepay_money_settings', array() );
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * Determine whether the payment method is available in Blocks checkout.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway instanceof CorePay_Money_Gateway && $this->gateway->is_available();
	}

	/**
	 * Register frontend script handles for Blocks checkout.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'corepay-money-blocks',
			COREPAY_MONEY_WC_URL . 'assets/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			COREPAY_MONEY_WC_VERSION,
			true
		);

		return array( 'corepay-money-blocks' );
	}

	/**
	 * Register admin editor script handles for Blocks checkout.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

	/**
	 * Expose gateway data to the Blocks frontend script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway ? $this->gateway->title : $this->get_setting( 'title', __( 'CorePay Money', 'corepay-gateway-for-woocommerce' ) ),
			'description' => $this->gateway ? $this->gateway->description : $this->get_setting( 'description', __( 'Pay securely with CorePay Money.', 'corepay-gateway-for-woocommerce' ) ),
			'icon'        => COREPAY_MONEY_WC_URL . 'assets/corepay.svg',
			'supports'    => $this->gateway ? $this->gateway->supports : array( 'products' ),
		);
	}
}
