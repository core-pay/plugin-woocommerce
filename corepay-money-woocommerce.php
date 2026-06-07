<?php
/**
 * Plugin Name: CorePay Money for WooCommerce
 * Plugin URI: https://github.com/core-pay/plugin-woocommerce
 * Description: Accept WooCommerce payments through the CorePay Money hosted widget using custom JSON payloads and webhook confirmations.
 * Version: 0.1.0
 * Author: CorePay
 * Author URI: https://corepay.money
 * Text Domain: corepay-money-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.4
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package CorePayMoneyWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COREPAY_MONEY_WC_VERSION', '0.1.0' );
define( 'COREPAY_MONEY_WC_FILE', __FILE__ );
define( 'COREPAY_MONEY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'COREPAY_MONEY_WC_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', 'corepay_money_wc_declare_features' );
add_action( 'plugins_loaded', 'corepay_money_wc_init', 11 );

/**
 * Declare WooCommerce feature compatibility.
 */
function corepay_money_wc_declare_features() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}

/**
 * Initialize plugin once WooCommerce is ready.
 */
function corepay_money_wc_init() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'corepay_money_wc_missing_woocommerce_notice' );
		return;
	}

	require_once COREPAY_MONEY_WC_PATH . 'includes/class-corepay-money-gateway.php';
	require_once COREPAY_MONEY_WC_PATH . 'includes/class-corepay-money-webhook.php';

	add_filter( 'woocommerce_payment_gateways', 'corepay_money_wc_register_gateway' );
	add_action( 'woocommerce_api_corepay_money', array( 'CorePay_Money_Webhook', 'handle' ) );

	if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once COREPAY_MONEY_WC_PATH . 'includes/class-corepay-money-blocks.php';
		add_action( 'woocommerce_blocks_payment_method_type_registration', 'corepay_money_wc_register_blocks_payment_method' );
	}
}

/**
 * Register gateway with WooCommerce.
 *
 * @param array $gateways Registered gateways.
 * @return array
 */
function corepay_money_wc_register_gateway( $gateways ) {
	$gateways[] = 'CorePay_Money_Gateway';
	return $gateways;
}

/**
 * Register gateway with WooCommerce Blocks checkout.
 *
 * @param object $payment_method_registry Blocks payment method registry.
 */
function corepay_money_wc_register_blocks_payment_method( $payment_method_registry ) {
	$payment_method_registry->register( new CorePay_Money_Blocks() );
}

/**
 * Show WooCommerce dependency notice.
 */
function corepay_money_wc_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__( 'CorePay Money for WooCommerce requires WooCommerce to be installed and active.', 'corepay-money-woocommerce' ) . '</p></div>';
}
