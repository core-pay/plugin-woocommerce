=== CorePay Gateway for WooCommerce ===
Contributors: corelabs, corepay
Tags: woocommerce, payments, payment-gateway, checkout, subscriptions
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce

Accept WooCommerce payments through the CorePay Money hosted payment widget.

== Description ==

CorePay Gateway for WooCommerce adds CorePay Money as a WooCommerce payment gateway for product checkout and subscription payments.

The plugin creates WooCommerce orders, renders the CorePay Money hosted widget on the order payment page, and waits for signed webhook callbacks before marking orders paid.

= Features =

* WooCommerce payment gateway for product checkout.
* WooCommerce Checkout Blocks support.
* WooCommerce Subscriptions support for initial subscription payments and renewal orders.
* Hosted CorePay Money widget integration.
* Sortable provider list with drag-to-reorder and delete controls.
* Store currency by default, with an admin override for a custom CorePay asset and store-currency fiat quote.
* Digitize option enabled by default.
* Required Ed25519 webhook signature validation against CorePay's well-known JWKS.
* WooCommerce HPOS compatibility declaration.

= External service =

This plugin connects to CorePay Money, an external payment service, to render the hosted payment widget and verify payment webhooks.

The plugin loads the hosted widget script from:

* https://corepay.money/widget

The widget script requests rendered payment markup from:

* https://corepay.money/api/v1/widget/render

The plugin fetches CorePay's public signing keys from:

* https://corepay.money/.well-known/jwks.json

The widget receives configured provider IDs, payment currency, and payment amount. Webhook verification receives and validates payment event data sent by CorePay, including order ID, order key, status, transaction ID, amount, and currency.

Use of this plugin requires a CorePay Money account/provider configuration. See CorePay Money for service terms and privacy details:

* https://corepay.money
* Terms of Service: https://corepay.money/terms/service
* Privacy Policy: https://corepay.money/terms/privacy

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/corepay-gateway-for-woocommerce`, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to WooCommerce > Settings > Payments > CorePay Money.
4. Enable the gateway.
5. Configure at least one complete provider row with an ID / CORE ID and Provider ID.
6. Configure currency behavior and webhook signature key ID if needed.

== Frequently Asked Questions ==

= Why does the payment method not appear at checkout? =

The gateway must be enabled and at least one provider row must have both an ID / CORE ID and Provider ID. If custom currency mode is enabled, the custom CorePay asset must be 1 to 6 characters; the WooCommerce store currency is still used as the fiat quote.

= Does this support WooCommerce Checkout Blocks? =

Yes. The plugin registers a payment method integration for the block-based checkout.

= Does this support subscriptions? =

Yes. The plugin advertises WooCommerce Subscriptions support and handles initial subscription payments, renewal orders, and payment-method changes.

= What webhook URL should be configured in CorePay? =

Use the webhook URL shown on the CorePay Money payment settings page. It follows the WooCommerce API callback format:

`https://example.com/wc-api/corepay_money`

= Is PHP sodium required? =

Yes. CorePay webhook signatures use Ed25519 / EdDSA verification, which requires PHP sodium support.

== Screenshots ==

1. CorePay Money payment gateway settings in WooCommerce.
2. CorePay Money provider configuration.
3. CorePay Money payment method at checkout.
4. CorePay Money payment widget.
5. Successful CorePay Money payment confirmation.

== Changelog ==

= 0.1.1 =

* Updated plugin name and text domain for WordPress.org review.
* Avoided storing raw webhook request bodies in debug logs.
* Sanitized decoded webhook payload data before use and storage.

= 0.1.0 =

* Initial release.
* Added WooCommerce payment gateway.
* Added WooCommerce Checkout Blocks integration.
* Added CorePay Money hosted widget rendering.
* Added signed webhook verification.
* Added provider row configuration.
* Added WooCommerce Subscriptions support.
