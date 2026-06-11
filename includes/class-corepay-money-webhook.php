<?php
/**
 * CorePay Money webhook handling.
 *
 * @package CorePayMoneyWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CorePay Money webhook requests.
 */
class CorePay_Money_Webhook {
	/**
	 * Handle WooCommerce API callback.
	 */
	public static function handle() {
		$gateway = self::get_gateway();
		$raw_body = file_get_contents( 'php://input' );
		$payload = json_decode( $raw_body, true );

		if ( ! $gateway || ! $gateway instanceof CorePay_Money_Gateway ) {
			self::respond( array( 'error' => 'gateway_unavailable' ), 503 );
		}

		$gateway->log(
			'Webhook received.',
			array(
				'body_length' => strlen( $raw_body ),
				'body_sha256' => hash( 'sha256', $raw_body ),
			)
		);

		if ( ! is_array( $payload ) ) {
			self::respond( array( 'error' => 'invalid_json' ), 400 );
		}

		$payload = self::sanitize_payload( $payload );

		if ( ! self::verify_signature( $raw_body, $gateway ) ) {
			$gateway->log( 'Webhook signature failed.' );
			self::respond( array( 'error' => 'invalid_signature' ), 401 );
		}

		$order = self::resolve_order( $payload );

		if ( ! $order ) {
			self::respond( array( 'error' => 'order_not_found' ), 404 );
		}

		if ( $order->get_payment_method() !== $gateway->id ) {
			self::respond( array( 'error' => 'payment_method_mismatch' ), 409 );
		}

		$status = self::payload_value( $payload, array( 'status', 'payment_status', 'event' ) );
		$status = strtolower( sanitize_text_field( (string) $status ) );
		$transaction_id = sanitize_text_field( (string) self::payload_value( $payload, array( 'transaction_id', 'payment_id', 'txid', 'id' ) ) );
		$recurring_id = sanitize_text_field( (string) self::payload_value( $payload, array( 'recurring_id', 'subscription_id', 'mandate_id', 'corepay_subscription_id' ) ) );
		$amount = self::payload_value( $payload, array( 'amount', 'total', 'order.amount' ) );
		$currency = strtoupper( sanitize_text_field( (string) self::payload_value( $payload, array( 'currency', 'order.currency' ) ) ) );

		if ( $transaction_id ) {
			$order->set_transaction_id( $transaction_id );
			$order->update_meta_data( '_corepay_money_transaction_id', $transaction_id );
		}

		if ( $recurring_id ) {
			$order->update_meta_data( '_corepay_money_recurring_id', $recurring_id );
			self::update_related_subscriptions_meta( $order, '_corepay_money_recurring_id', $recurring_id );
		}

		$order->update_meta_data( '_corepay_money_last_webhook', wp_json_encode( $payload ) );
		$order->update_meta_data( '_corepay_money_status', $status );

		if ( in_array( $status, array( 'paid', 'payment_paid', 'completed', 'complete', 'success', 'succeeded' ), true ) ) {
			self::complete_order( $order, $gateway, $amount, $currency, $transaction_id );
		} elseif ( in_array( $status, array( 'failed', 'failure', 'cancelled', 'canceled', 'expired' ), true ) ) {
			$order->update_status( 'failed', __( 'CorePay Money payment failed or expired.', 'corepay-gateway-for-woocommerce' ) );
		} else {
			$order->add_order_note( sprintf( /* translators: %s: webhook status */ __( 'CorePay Money webhook received with status: %s', 'corepay-gateway-for-woocommerce' ), $status ? $status : __( 'unknown', 'corepay-gateway-for-woocommerce' ) ) );
			$order->save();
		}

		self::respond(
			array(
				'ok'       => true,
				'order_id' => $order->get_id(),
				'status'   => $order->get_status(),
			)
		);
	}

	/**
	 * Get configured gateway instance.
	 *
	 * @return CorePay_Money_Gateway|null
	 */
	private static function get_gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['corepay_money'] ) ? $gateways['corepay_money'] : null;
	}

	/**
	 * Verify CorePay webhook signature from the well-known JWKS.
	 *
	 * @param string $raw_body Raw request body.
	 * @param CorePay_Money_Gateway $gateway Gateway instance.
	 * @return bool
	 */
	private static function verify_signature( $raw_body, $gateway ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$gateway->log( 'Webhook signature verification requires the PHP sodium extension.' );
			return false;
		}

		$signature = self::get_signature_header();
		$key_id = self::get_signature_key_id( $gateway );

		if ( '' === $signature || '' === $key_id ) {
			return false;
		}

		if ( $key_id !== $gateway->signature_key_id ) {
			$gateway->log( 'Webhook signature key ID mismatch.', array( 'received_key_id' => $key_id ) );
			return false;
		}

		$signature_bytes = self::decode_signature( $signature );
		$public_key = self::get_public_key_for_key_id( $key_id );

		if ( ! $signature_bytes || ! $public_key ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $signature_bytes, $raw_body, $public_key );
	}

	/**
	 * Read CorePay signature header.
	 *
	 * @return string
	 */
	private static function get_signature_header() {
		$signature = isset( $_SERVER['HTTP_X_COREPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_COREPAY_SIGNATURE'] ) ) : '';
		$signature_params = self::parse_signature_header_params( $signature );

		if ( isset( $signature_params['signature'] ) ) {
			return $signature_params['signature'];
		}

		if ( 0 === strpos( $signature, 'ed25519=' ) ) {
			return substr( $signature, 8 );
		}

		if ( 0 === strpos( $signature, 'signature=' ) ) {
			return substr( $signature, 10 );
		}

		return $signature;
	}

	/**
	 * Get CorePay signature key ID from headers or gateway default.
	 *
	 * @param CorePay_Money_Gateway $gateway Gateway instance.
	 * @return string
	 */
	private static function get_signature_key_id( $gateway ) {
		if ( isset( $_SERVER['HTTP_X_COREPAY_KEY_ID'] ) ) {
			return sanitize_key( wp_unslash( $_SERVER['HTTP_X_COREPAY_KEY_ID'] ) );
		}

		if ( isset( $_SERVER['HTTP_X_COREPAY_KID'] ) ) {
			return sanitize_key( wp_unslash( $_SERVER['HTTP_X_COREPAY_KID'] ) );
		}

		if ( isset( $_SERVER['HTTP_X_COREPAY_SIGNATURE_ID'] ) ) {
			return sanitize_key( wp_unslash( $_SERVER['HTTP_X_COREPAY_SIGNATURE_ID'] ) );
		}

		$signature = isset( $_SERVER['HTTP_X_COREPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_COREPAY_SIGNATURE'] ) ) : '';
		$signature_params = self::parse_signature_header_params( $signature );

		if ( isset( $signature_params['keyid'] ) ) {
			return sanitize_key( $signature_params['keyid'] );
		}

		if ( isset( $signature_params['kid'] ) ) {
			return sanitize_key( $signature_params['kid'] );
		}

		return sanitize_key( $gateway->signature_key_id );
	}

	/**
	 * Parse comma-delimited signature header parameters.
	 *
	 * @param string $header Signature header.
	 * @return array
	 */
	private static function parse_signature_header_params( $header ) {
		$params = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			$name = strtolower( trim( $pair[0] ) );
			$value = trim( $pair[1], " \t\n\r\0\x0B\"" );

			if ( '' !== $name && '' !== $value ) {
				$params[ $name ] = $value;
			}
		}

		return $params;
	}

	/**
	 * Decode signature from base64url, base64, or hexadecimal.
	 *
	 * @param string $signature Signature string.
	 * @return string|false
	 */
	private static function decode_signature( $signature ) {
		$signature = trim( $signature );

		if ( preg_match( '/^[a-f0-9]{128}$/i', $signature ) ) {
			return hex2bin( $signature );
		}

		$decoded = self::base64url_decode( $signature );

		if ( false !== $decoded ) {
			return $decoded;
		}

		return base64_decode( $signature, true );
	}

	/**
	 * Fetch Ed25519 public key bytes for a CorePay key ID.
	 *
	 * @param string $key_id Key ID.
	 * @return string|false
	 */
	private static function get_public_key_for_key_id( $key_id ) {
		$jwks = get_transient( 'corepay_money_jwks' );

		if ( ! is_array( $jwks ) ) {
			$response = wp_remote_get(
				'https://corepay.money/.well-known/jwks.json',
				array(
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			$jwks = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $jwks ) ) {
				return false;
			}

			set_transient( 'corepay_money_jwks', $jwks, HOUR_IN_SECONDS );
		}

		if ( empty( $jwks['keys'] ) || ! is_array( $jwks['keys'] ) ) {
			return false;
		}

		foreach ( $jwks['keys'] as $key ) {
			if ( ! is_array( $key ) || $key_id !== ( isset( $key['kid'] ) ? sanitize_key( $key['kid'] ) : '' ) ) {
				continue;
			}

			if ( 'OKP' !== ( $key['kty'] ?? '' ) || 'Ed25519' !== ( $key['crv'] ?? '' ) || empty( $key['x'] ) ) {
				return false;
			}

			return self::base64url_decode( $key['x'] );
		}

		return false;
	}

	/**
	 * Decode base64url string.
	 *
	 * @param string $value Base64url value.
	 * @return string|false
	 */
	private static function base64url_decode( $value ) {
		if ( '' === $value || preg_match( '/[^A-Za-z0-9_\\-]/', $value ) ) {
			return false;
		}

		$padding = strlen( $value ) % 4;

		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/**
	 * Resolve WooCommerce order from webhook payload.
	 *
	 * @param array $payload Payload.
	 * @return WC_Order|null
	 */
	private static function resolve_order( $payload ) {
		$order_id = self::payload_value( $payload, array( 'order_id', 'order.id', 'woocommerce_order_id' ) );
		$order_key = self::payload_value( $payload, array( 'order_key', 'order.key' ) );

		if ( ! $order_id || ! $order_key ) {
			return null;
		}

		$order = wc_get_order( absint( $order_id ) );

		if ( $order && hash_equals( $order->get_order_key(), (string) $order_key ) ) {
			return $order;
		}

		return null;
	}

	/**
	 * Mark order paid after validation.
	 *
	 * @param WC_Order              $order Order.
	 * @param CorePay_Money_Gateway $gateway Gateway.
	 * @param mixed                 $amount Paid amount.
	 * @param string                $currency Paid currency.
	 * @param string                $transaction_id Transaction ID.
	 */
	private static function complete_order( $order, $gateway, $amount, $currency, $transaction_id ) {
		$expected_amount = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
		$expected_currency = method_exists( $gateway, 'get_order_currency' ) ? $gateway->get_order_currency( $order ) : $gateway->get_payment_currency();
		$paid_amount = null !== $amount ? wc_format_decimal( $amount, wc_get_price_decimals() ) : '';

		if ( '' !== $paid_amount && $paid_amount !== $expected_amount ) {
			$order->update_status( 'on-hold', sprintf( /* translators: 1: paid amount, 2: expected amount */ __( 'CorePay Money amount mismatch. Paid %1$s, expected %2$s.', 'corepay-gateway-for-woocommerce' ), $paid_amount, $expected_amount ) );
			return;
		}

		if ( '' !== $currency && $currency !== $expected_currency ) {
			$order->update_status( 'on-hold', sprintf( /* translators: 1: paid currency, 2: expected currency */ __( 'CorePay Money currency mismatch. Paid %1$s, expected %2$s.', 'corepay-gateway-for-woocommerce' ), $currency, $expected_currency ) );
			return;
		}

		if ( ! $order->is_paid() ) {
			$order->payment_complete( $transaction_id );
			$order->add_order_note( __( 'CorePay Money webhook confirmed payment.', 'corepay-gateway-for-woocommerce' ) );
		} else {
			$order->add_order_note( __( 'Duplicate CorePay Money payment webhook ignored; order is already paid.', 'corepay-gateway-for-woocommerce' ) );
			$order->save();
		}
	}

	/**
	 * Update subscription metadata related to an order or renewal.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $meta_key Meta key.
	 * @param string   $meta_value Meta value.
	 */
	private static function update_related_subscriptions_meta( $order, $meta_key, $meta_value ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
		} else {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		}

		if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof WC_Order ) {
				continue;
			}

			$subscription->update_meta_data( $meta_key, $meta_value );
			$subscription->save();
		}
	}

	/**
	 * Sanitize decoded webhook payload data before storage or use.
	 *
	 * @param array $payload Decoded payload.
	 * @return array
	 */
	private static function sanitize_payload( $payload ) {
		$sanitized = array();

		foreach ( $payload as $key => $value ) {
			$sanitized_key = is_int( $key ) ? $key : sanitize_key( (string) $key );

			if ( '' === $sanitized_key && ! is_int( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $sanitized_key ] = self::sanitize_payload( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$sanitized[ $sanitized_key ] = $value;
				continue;
			}

			$sanitized[ $sanitized_key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Read nested payload values by accepted keys.
	 *
	 * @param array $payload Payload.
	 * @param array $keys Accepted keys.
	 * @return mixed|null
	 */
	private static function payload_value( $payload, $keys ) {
		foreach ( $keys as $key ) {
			$segments = explode( '.', $key );
			$value = $payload;

			foreach ( $segments as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					$value = null;
					break;
				}

				$value = $value[ $segment ];
			}

			if ( null !== $value && '' !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Send JSON response.
	 *
	 * @param array $body Body.
	 * @param int   $status HTTP status.
	 */
	private static function respond( $body, $status = 200 ) {
		wp_send_json( $body, $status );
	}
}
