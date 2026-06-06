<?php
/**
 * CorePay Money WooCommerce gateway.
 *
 * @package CorePayMoneyWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hosted widget payment gateway for CorePay Money.
 */
class CorePay_Money_Gateway extends WC_Payment_Gateway {
	/**
	 * CorePay hosted widget URL.
	 *
	 * @var string
	 */
	public $widget_url;

	/**
	 * Currency mode.
	 *
	 * @var string
	 */
	public $currency_mode;

	/**
	 * Custom currency override.
	 *
	 * @var string
	 */
	public $custom_currency;

	/**
	 * Whether digitize is enabled.
	 *
	 * @var bool
	 */
	public $digitize;

	/**
	 * Preferred operators.
	 *
	 * @var array
	 */
	public $operators;

	/**
	 * CorePay webhook signature key ID.
	 *
	 * @var string
	 */
	public $signature_key_id;

	/**
	 * Whether debug logging is enabled.
	 *
	 * @var bool
	 */
	public $debug;

	/**
	 * Gateway constructor.
	 */
	public function __construct() {
		$this->id                 = 'corepay_money';
		$this->icon               = 'https://corecdn.info/mark/64/corepay.svg';
		$this->has_fields         = false;
		$this->method_title       = __( 'CorePay Money', 'corepay-money-woocommerce' );
		$this->method_description = __( 'Process product payments through the CorePay Money hosted widget.', 'corepay-money-woocommerce' );
		$this->supports           = array(
			'products',
			'subscriptions',
			'multiple_subscriptions',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled        = $this->get_option( 'enabled', 'no' );
		$this->title          = $this->get_option( 'title', __( 'CorePay Money', 'corepay-money-woocommerce' ) );
		$this->description    = $this->get_option( 'description', __( 'Pay securely with CorePay Money.', 'corepay-money-woocommerce' ) );
		$this->widget_url     = $this->get_option( 'widget_url', 'https://corepay.money/widget' );
		$this->currency_mode  = $this->get_option( 'currency_mode', 'system' );
		$this->custom_currency = $this->get_option( 'custom_currency', '' );
		$this->digitize       = 'yes' === $this->get_option( 'digitize', 'yes' );
		$this->operators      = $this->get_operators();
		$this->signature_key_id = $this->get_option( 'signature_key_id', 'corepay-key-1' );
		$this->debug          = 'yes' === $this->get_option( 'debug', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_scheduled_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_subscriptions_changed_failing_payment_method_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );
	}

	/**
	 * Define admin settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'corepay-money-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CorePay Money', 'corepay-money-woocommerce' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'corepay-money-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'corepay-money-woocommerce' ),
				'default'     => __( 'CorePay Money', 'corepay-money-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'corepay-money-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown at checkout.', 'corepay-money-woocommerce' ),
				'default'     => __( 'Pay securely with CorePay Money.', 'corepay-money-woocommerce' ),
				'desc_tip'    => true,
			),
			'widget_url'      => array(
				'title'       => __( 'Widget URL', 'corepay-money-woocommerce' ),
				'type'        => 'url',
				'description' => __( 'CorePay Money hosted widget endpoint.', 'corepay-money-woocommerce' ),
				'default'     => 'https://corepay.money/widget',
			),
			'operators'       => array(
				'title'       => __( 'Providers', 'corepay-money-woocommerce' ),
				'type'        => 'operators',
				'description' => __( 'Add one or more providers. Drag rows to control preference order.', 'corepay-money-woocommerce' ),
			),
			'currency_mode'   => array(
				'title'       => __( 'Currency', 'corepay-money-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Use the WooCommerce store currency or override it for CorePay.', 'corepay-money-woocommerce' ),
				'default'     => 'system',
				'options'     => array(
					'system' => __( 'Use WooCommerce store currency', 'corepay-money-woocommerce' ),
					'custom' => __( 'Use custom currency', 'corepay-money-woocommerce' ),
				),
			),
			'custom_currency' => array(
				'title'       => __( 'Custom Currency', 'corepay-money-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Three-letter ISO currency code, used only when Currency is set to custom.', 'corepay-money-woocommerce' ),
				'default'     => '',
			),
			'digitize'        => array(
				'title'       => __( 'Digitize', 'corepay-money-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Keep digitize option enabled for CorePay payloads', 'corepay-money-woocommerce' ),
				'description' => __( 'Checked by default. The value is sent as digitize: true in custom JSON.', 'corepay-money-woocommerce' ),
				'default'     => 'yes',
			),
			'signature_key_id' => array(
				'title'       => __( 'Signature Key ID', 'corepay-money-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'CorePay JWKS key ID used to verify webhook signatures from https://corepay.money/.well-known/jwks.json.', 'corepay-money-woocommerce' ),
				'default'     => 'corepay-key-1',
			),
			'debug'           => array(
				'title'       => __( 'Debug Log', 'corepay-money-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable WooCommerce logger entries', 'corepay-money-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Enqueue admin assets on gateway settings.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		if ( ! isset( $_GET['section'] ) || $this->id !== sanitize_text_field( wp_unslash( $_GET['section'] ) ) ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_style( 'corepay-money-admin', COREPAY_MONEY_WC_URL . 'assets/admin.css', array(), COREPAY_MONEY_WC_VERSION );
		wp_enqueue_script( 'corepay-money-admin', COREPAY_MONEY_WC_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), COREPAY_MONEY_WC_VERSION, true );
	}

	/**
	 * Check whether the gateway can be used at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! $this->has_configured_operator() ) {
			return false;
		}

		if ( 'custom' === $this->currency_mode && 3 !== strlen( $this->custom_currency ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Render gateway admin settings.
	 */
	public function admin_options() {
		parent::admin_options();

		echo '<h3>' . esc_html__( 'Webhook', 'corepay-money-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'Configure CorePay Money to send payment events to this URL:', 'corepay-money-woocommerce' ) . '</p>';
		echo '<code>' . esc_html( WC()->api_request_url( 'corepay_money' ) ) . '</code>';
	}

	/**
	 * Render custom operators settings field.
	 *
	 * @return string
	 */
	public function generate_operators_html() {
		$field_key = $this->get_field_key( 'operators' );
		$operators = $this->get_operators();

		if ( empty( $operators ) ) {
			$operators = array(
				array(
					'id'       => '',
					'operator' => 'ping',
				),
			);
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php esc_html_e( 'Providers', 'corepay-money-woocommerce' ); ?></label>
			</th>
			<td class="forminp">
				<table class="widefat corepay-money-operators" data-field-key="<?php echo esc_attr( $field_key ); ?>">
					<thead>
						<tr>
							<th class="corepay-money-operator-handle"><?php esc_html_e( 'Order', 'corepay-money-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'ID / CORE ID', 'corepay-money-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Provider', 'corepay-money-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'corepay-money-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $operators as $operator ) : ?>
							<tr>
								<td class="corepay-money-operator-handle">☰</td>
								<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[id][]" value="<?php echo esc_attr( $operator['id'] ); ?>" placeholder="<?php esc_attr_e( 'CB…', 'corepay-money-woocommerce' ); ?>" /></td>
								<td><input type="text" name="<?php echo esc_attr( $field_key ); ?>[operator][]" value="<?php echo esc_attr( $operator['operator'] ); ?>" placeholder="<?php esc_attr_e( 'Provider ID, e.g. ping', 'corepay-money-woocommerce' ); ?>" /></td>
								<td><button type="button" class="button corepay-money-remove-operator"><?php esc_html_e( 'Delete', 'corepay-money-woocommerce' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button corepay-money-add-operator"><?php esc_html_e( 'Add provider', 'corepay-money-woocommerce' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Add one or more providers. Drag rows to reorder or delete rows you no longer use.', 'corepay-money-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Save operators field.
	 *
	 * @param string $key Field key.
	 * @param array  $value Field value.
	 * @return string
	 */
	public function validate_operators_field( $key, $value ) {
		unset( $key );

		$operators = array();
		$ids = isset( $value['id'] ) && is_array( $value['id'] ) ? $value['id'] : array();
		$names = isset( $value['operator'] ) && is_array( $value['operator'] ) ? $value['operator'] : array();
		$count = max( count( $ids ), count( $names ) );

		for ( $index = 0; $index < $count; $index++ ) {
			$id = isset( $ids[ $index ] ) ? sanitize_text_field( wp_unslash( $ids[ $index ] ) ) : '';
			$name = isset( $names[ $index ] ) ? sanitize_text_field( wp_unslash( $names[ $index ] ) ) : '';

			if ( '' === $id && '' === $name ) {
				continue;
			}

			$operators[] = array(
				'id'       => $id,
				'operator' => $name,
			);
		}

		return wp_json_encode( $operators );
	}

	/**
	 * Sanitize custom currency value.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_custom_currency_field( $key, $value ) {
		unset( $key );
		return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $value ), 0, 3 ) );
	}

	/**
	 * Sanitize widget URL.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_widget_url_field( $key, $value ) {
		unset( $key );
		$url = esc_url_raw( trim( (string) $value ) );

		return $url ? $url : 'https://corepay.money/widget';
	}

	/**
	 * Sanitize CorePay signature key ID.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_signature_key_id_field( $key, $value ) {
		unset( $key );
		$key_id = sanitize_key( (string) $value );

		return $key_id ? $key_id : 'corepay-key-1';
	}

	/**
	 * Process checkout payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Unable to create CorePay payment for this order.', 'corepay-money-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( $this->is_subscription_payment_method_change( $order_id ) ) {
			$order->set_payment_method( $this->id );
			$order->set_payment_method_title( $this->title );
			$order->update_meta_data( '_corepay_money_payment_method_changed', current_time( 'mysql' ) );
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_subscription_redirect_url( $order ),
			);
		}

		if ( 0 >= (float) $order->get_total() ) {
			$order->payment_complete();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$this->store_payment_payload( $order, $this->get_payment_context( $order ) );

		$order->update_status( 'on-hold', __( 'Awaiting CorePay Money webhook confirmation.', 'corepay-money-woocommerce' ) );
		wc_reduce_stock_levels( $order_id );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Process automatic WooCommerce Subscriptions renewal payments.
	 *
	 * CorePay is expected to receive the generated payload through the
	 * integration hook and confirm payment asynchronously through the webhook.
	 *
	 * @param float    $renewal_total Renewal amount.
	 * @param WC_Order $renewal_order Renewal order.
	 */
	public function process_scheduled_subscription_payment( $renewal_total, $renewal_order ) {
		if ( ! $renewal_order instanceof WC_Order ) {
			$renewal_order = wc_get_order( $renewal_order );
		}

		if ( ! $renewal_order ) {
			return;
		}

		if ( 0 >= (float) $renewal_total ) {
			$renewal_order->payment_complete();
			$renewal_order->add_order_note( __( 'CorePay Money completed zero-total subscription renewal.', 'corepay-money-woocommerce' ) );
			return;
		}

		$payload = $this->store_payment_payload( $renewal_order, 'subscription_renewal' );
		$renewal_order->update_status( 'on-hold', __( 'Awaiting CorePay Money recurring payment webhook confirmation.', 'corepay-money-woocommerce' ) );

		/**
		 * Fires when a CorePay recurring payment payload is ready to be sent.
		 *
		 * Use this hook to POST the custom JSON to a CorePay server-side endpoint
		 * if/when that endpoint is available. The default widget flow stores the
		 * payload and waits for the CorePay webhook to mark the renewal paid.
		 *
		 * @param WC_Order              $renewal_order Renewal order.
		 * @param array                 $payload Renewal payment payload.
		 * @param CorePay_Money_Gateway $gateway Gateway instance.
		 */
		do_action( 'corepay_money_recurring_payment_payload_created', $renewal_order, $payload, $this );
		$this->log( 'Recurring payment payload created.', array( 'order_id' => $renewal_order->get_id() ) );
	}

	/**
	 * Copy CorePay metadata after a customer pays a failed renewal.
	 *
	 * @param WC_Order $original_order Original subscription/order object.
	 * @param WC_Order $renewal_order Renewal order object.
	 */
	public function update_failing_payment_method( $original_order, $renewal_order ) {
		if ( ! $original_order instanceof WC_Order || ! $renewal_order instanceof WC_Order ) {
			return;
		}

		$original_order->set_payment_method( $this->id );
		$original_order->set_payment_method_title( $this->title );
		$original_order->update_meta_data( '_corepay_money_payment_method_changed', current_time( 'mysql' ) );
		$original_order->save();

		$renewal_order->add_order_note( __( 'CorePay Money updated the future recurring payment method after a failed renewal payment.', 'corepay-money-woocommerce' ) );
	}

	/**
	 * Render receipt page containing widget payload.
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			echo esc_html__( 'Order not found.', 'corepay-money-woocommerce' );
			return;
		}

		$payload = $this->build_payment_payload( $order, $this->get_payment_context( $order ) );
		$widget_url = esc_url( $this->widget_url );
		?>
		<div class="corepay-money-widget-wrap">
			<p><?php esc_html_e( 'Complete your payment in the secure CorePay Money widget.', 'corepay-money-woocommerce' ); ?></p>
			<form id="corepay-money-widget-form" action="<?php echo $widget_url; ?>" method="post">
				<input type="hidden" name="custom_json" value="<?php echo esc_attr( wp_json_encode( $payload ) ); ?>" />
				<noscript><button type="submit" class="button alt"><?php esc_html_e( 'Open CorePay Money', 'corepay-money-woocommerce' ); ?></button></noscript>
			</form>
			<iframe id="corepay-money-widget" title="<?php esc_attr_e( 'CorePay Money payment widget', 'corepay-money-woocommerce' ); ?>" src="about:blank" style="width:100%;min-height:720px;border:0;" loading="eager"></iframe>
		</div>
		<script>
			(function() {
				var form = document.getElementById('corepay-money-widget-form');
				var iframe = document.getElementById('corepay-money-widget');

				if (!form || !iframe) {
					return;
				}

				form.target = 'corepay-money-widget';
				form.submit();
			}());
		</script>
		<?php
	}

	/**
	 * Build custom JSON payload for CorePay widget.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $context Payment context.
	 * @return array
	 */
	public function build_payment_payload( $order, $context = 'checkout' ) {
		$operator = $this->get_primary_operator();
		$core_id = is_array( $operator ) && isset( $operator['id'] ) ? $operator['id'] : '';
		$currency = $this->get_payment_currency();
		$is_recurring = $this->order_has_subscription( $order ) || 'subscription_renewal' === $context;

		return array(
			'provider'    => 'woocommerce',
			'gateway'     => $this->id,
			'organization' => $this->get_shop_organization(),
			'payment'     => array(
				'type'      => $context,
				'recurring' => $is_recurring,
			),
			'order'       => array(
				'id'          => (string) $order->get_id(),
				'number'      => $order->get_order_number(),
				'key'         => $order->get_order_key(),
				'amount'      => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'currency'    => $currency,
				'description' => sprintf( /* translators: %s: order number */ __( 'WooCommerce order %s', 'corepay-money-woocommerce' ), $order->get_order_number() ),
			),
			'merchant'    => array(
				'core_id'  => $core_id,
				'operator' => $operator,
			),
			'operators'   => $this->get_configured_operators(),
			'subscriptions' => $this->get_order_subscriptions_payload( $order, $context ),
			'digitize'    => $this->digitize,
			'urls'        => array(
				'webhook' => WC()->api_request_url( 'corepay_money' ),
				'return'  => $this->get_return_url( $order ),
				'cancel'  => $order->get_cancel_order_url_raw(),
			),
			'customer'    => array(
				'email' => $order->get_billing_email(),
				'name'  => trim( $order->get_formatted_billing_full_name() ),
			),
			'additional_data' => $this->get_additional_data(),
		);
	}

	/**
	 * Get shop website host for CorePay organization parameter.
	 *
	 * @return string
	 */
	private function get_shop_organization() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? strtolower( $host ) : '';
	}

	/**
	 * Get compact additional data for CorePay widget.
	 *
	 * CorePay requires this canonical JSON object to be 250 characters or less.
	 *
	 * @return array
	 */
	private function get_additional_data() {
		$data = array(
			'plugin'  => 'corepay-money-woocommerce',
			'shop'    => $this->get_shop_organization(),
			'version' => COREPAY_MONEY_WC_VERSION,
		);

		if ( $this->is_canonical_json_under_limit( $data, 250 ) ) {
			return $data;
		}

		$data = array(
			'plugin' => 'corepay-money-woocommerce',
			'shop'   => $this->get_shop_organization(),
		);

		if ( $this->is_canonical_json_under_limit( $data, 250 ) ) {
			return $data;
		}

		$data = array(
			'plugin' => 'corepay-money-woocommerce',
		);

		return $this->is_canonical_json_under_limit( $data, 250 ) ? $data : array();
	}

	/**
	 * Check canonical JSON length for CorePay additional data.
	 *
	 * @param array $data Data object.
	 * @param int   $limit Character limit.
	 * @return bool
	 */
	private function is_canonical_json_under_limit( $data, $limit ) {
		$this->sort_array_keys_recursive( $data );
		$json = wp_json_encode( $data );

		return is_string( $json ) && strlen( $json ) <= $limit;
	}

	/**
	 * Sort array keys recursively for deterministic canonical JSON checks.
	 *
	 * @param array $data Data object.
	 */
	private function sort_array_keys_recursive( &$data ) {
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				$this->sort_array_keys_recursive( $value );
			}
		}
		unset( $value );

		ksort( $data );
	}

	/**
	 * Store a CorePay payload on an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $context Payment context.
	 * @return array
	 */
	private function store_payment_payload( $order, $context ) {
		$payload = $this->build_payment_payload( $order, $context );

		$order->update_meta_data( '_corepay_money_payload', wp_json_encode( $payload ) );
		$order->update_meta_data( '_corepay_money_status', 'created' );
		$order->update_meta_data( '_corepay_money_payment_context', $context );
		$order->save();

		return $payload;
	}

	/**
	 * Determine payment context for a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function get_payment_context( $order ) {
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return 'subscription_renewal';
		}

		if ( $this->order_has_subscription( $order ) ) {
			return 'subscription_initial';
		}

		return 'checkout';
	}

	/**
	 * Check if an order contains subscription data.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function order_has_subscription( $order ) {
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
			return true;
		}

		return function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
	}

	/**
	 * Build subscription metadata for the CorePay payload.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $context Payment context.
	 * @return array
	 */
	private function get_order_subscriptions_payload( $order, $context ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return array();
		}

		if ( 'subscription_renewal' === $context && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
		} else {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		}

		if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
			return array();
		}

		$payload = array();

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof WC_Order ) {
				continue;
			}

			$payload[] = array(
				'id'             => (string) $subscription->get_id(),
				'number'         => $subscription->get_order_number(),
				'status'         => $subscription->get_status(),
				'billing_period' => method_exists( $subscription, 'get_billing_period' ) ? $subscription->get_billing_period() : '',
				'billing_interval' => method_exists( $subscription, 'get_billing_interval' ) ? (int) $subscription->get_billing_interval() : 0,
				'total'          => wc_format_decimal( $subscription->get_total(), wc_get_price_decimals() ),
				'currency'       => $this->get_payment_currency(),
				'start_date'     => method_exists( $subscription, 'get_date' ) ? $subscription->get_date( 'start' ) : '',
				'next_payment'   => method_exists( $subscription, 'get_date' ) ? $subscription->get_date( 'next_payment' ) : '',
				'end_date'       => method_exists( $subscription, 'get_date' ) ? $subscription->get_date( 'end' ) : '',
			);
		}

		return $payload;
	}

	/**
	 * Check if process_payment is being used to change a subscription payment method.
	 *
	 * @param int $order_id Order or subscription ID.
	 * @return bool
	 */
	private function is_subscription_payment_method_change( $order_id ) {
		return function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id );
	}

	/**
	 * Get redirect URL for subscription payment method changes.
	 *
	 * @param WC_Order $subscription Subscription object.
	 * @return string
	 */
	private function get_subscription_redirect_url( $subscription ) {
		if ( method_exists( $subscription, 'get_view_order_url' ) ) {
			return $subscription->get_view_order_url();
		}

		return wc_get_account_endpoint_url( 'subscriptions' );
	}

	/**
	 * Get configured operators.
	 *
	 * @return array
	 */
	public function get_operators() {
		$raw = $this->get_option( 'operators', '[]' );
		$operators = json_decode( (string) $raw, true );

		if ( ! is_array( $operators ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					function ( $operator ) {
						if ( ! is_array( $operator ) ) {
							return null;
						}

						return array(
							'id'       => isset( $operator['id'] ) ? sanitize_text_field( $operator['id'] ) : '',
							'operator' => isset( $operator['operator'] ) ? sanitize_text_field( $operator['operator'] ) : '',
						);
					},
					$operators
				)
			)
		);
	}

	/**
	 * Get payment currency for payloads.
	 *
	 * @return string
	 */
	public function get_payment_currency() {
		if ( 'custom' === $this->currency_mode && '' !== $this->custom_currency ) {
			return $this->custom_currency;
		}

		return get_woocommerce_currency();
	}

	/**
	 * Get first preferred operator.
	 *
	 * @return array|null
	 */
	private function get_primary_operator() {
		$operators = $this->get_configured_operators();

		return isset( $operators[0] ) ? $operators[0] : null;
	}

	/**
	 * Get provider rows with all required values.
	 *
	 * @return array
	 */
	private function get_configured_operators() {
		return array_values(
			array_filter(
				$this->operators,
				function ( $operator ) {
					return ! empty( $operator['id'] ) && ! empty( $operator['operator'] );
				}
			)
		);
	}

	/**
	 * Determine whether an operator row has usable values.
	 *
	 * @return bool
	 */
	private function has_configured_operator() {
		return ! empty( $this->get_configured_operators() );
	}

	/**
	 * Log debug messages.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function log( $message, $context = array() ) {
		if ( ! $this->debug ) {
			return;
		}

		wc_get_logger()->debug( $message, array_merge( array( 'source' => $this->id ), $context ) );
	}
}
