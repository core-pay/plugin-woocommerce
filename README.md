# CorePay Gateway for WooCommerce

A WooCommerce payment gateway plugin by **CorePay** for processing one-time and recurring payments through the CorePay Money hosted widget at `corepay.money`.

Repository: <https://github.com/core-pay/plugin-woocommerce>

The plugin creates a custom JSON payment payload at checkout, opens the CorePay hosted widget, and waits for a webhook callback before marking the WooCommerce order paid. For subscriptions, it integrates with WooCommerce Subscriptions renewal hooks and stores recurring-payment payloads for CorePay webhook confirmation.

## Features

- WooCommerce payment gateway for product checkout.
- WooCommerce Subscriptions support for initial subscription payments and renewal orders.
- Hosted CorePay Money widget integration.
- Custom JSON payload containing order, merchant provider, customer, subscription, and callback data.
- Additional data object is checked against CorePay's 250-character canonical JSON limit.
- Webhook endpoint for asynchronous payment confirmation.
- Store currency by default, with an admin override for a custom CorePay asset and store-currency fiat quote.
- Digitize option enabled by default and sent as `digitize: true`.
- Sortable provider list with drag-to-reorder and delete controls.
- Required Ed25519 webhook signature validation against CorePay's well-known JWKS.
- WooCommerce HPOS compatibility declaration.

## Requirements

- WordPress 6.4 or newer.
- WooCommerce 8.0 or newer.
- PHP 7.4 or newer.
- PHP sodium extension for Ed25519 webhook signature verification.
- HTTPS-enabled store URL for production payments.
- WooCommerce Subscriptions for recurring products and renewal orders.

## Installation

1. Copy this repository into `wp-content/plugins/corepay-gateway-for-woocommerce`.
2. Activate **CorePay Gateway for WooCommerce** in WordPress Admin → Plugins.
3. Go to WooCommerce → Settings → Payments → CorePay Money.
4. Enable the gateway and configure at least one complete provider row, currency, and signature key ID.

## Configuration

### Providers

Add provider rows with an ID / CORE ID and Provider ID. The first complete row is the preferred provider, and complete rows are sent in the custom JSON payload. The default provider ID is `ping`. Drag rows to reorder providers or delete rows to remove them.

### Currency

By default, the plugin sends the WooCommerce store currency as the CorePay asset. Shop admins can switch to a custom CorePay asset code from 1 to 6 characters, such as `BTC`, `CTN`, or `XCB`; the hosted widget still uses the WooCommerce store currency as the fiat quote that the customer pays.

### Digitize

The digitize checkbox is enabled by default. When checked, payloads include:

```json
{
    "digitize": true
}
```

### Signature verification

Webhook requests must be signed by CorePay and verified against the CorePay JWKS endpoint:

```text
https://corepay.money/.well-known/jwks.json
```

The plugin requires the PHP `sodium` extension because CorePay uses Ed25519 / EdDSA keys. If `sodium_crypto_sign_verify_detached()` is unavailable, webhook verification fails and the order is not marked paid.

The default admin key ID is `corepay-key-1`. CorePay must send an `X-CorePay-Signature` header containing an Ed25519 signature of the exact raw JSON request body. The plugin accepts base64url, base64, hex, `ed25519=...`, or `signature=...` signature formats.

CorePay may also send `X-CorePay-Key-Id`, `X-CorePay-Kid`, or `X-CorePay-Signature-Id`. The plugin also accepts structured `X-CorePay-Signature` values such as `keyid="corepay-key-1",signature="..."`. When absent, the configured admin key ID is used. The received/configured key ID must match the key ID in the JWKS.

Verification flow:

1. Read the raw webhook body before parsing JSON.
2. Read the signature and key ID from CorePay headers.
3. Fetch and cache CorePay JWKS for one hour.
4. Find the configured key ID, currently `corepay-key-1`.
5. Decode the JWK Ed25519 public key.
6. Verify the detached signature against the raw body with PHP sodium.
7. Process the order only after signature, order key, amount, and currency validation pass.

## Payment flow

1. Customer selects **CorePay Money** at WooCommerce checkout.
2. WooCommerce creates the order and redirects the customer to the order payment page.
3. The plugin renders the CorePay hosted widget using the configured provider row, order amount, and currency.
4. CorePay processes the payment.
5. CorePay sends a JSON webhook to the WooCommerce callback URL.
6. The plugin validates the CorePay signature, order key, amount, and currency.
7. Successful payment statuses call `$order->payment_complete()`.

## Recurring payment flow

WooCommerce Subscriptions filters checkout gateways to those that advertise `subscriptions` support. This plugin registers that support and hooks into `woocommerce_scheduled_subscription_payment_corepay_money` for renewal orders.

1. Initial subscription checkout uses the same hosted widget flow as a one-time order.
2. Initial payloads include `payment.recurring: true` and a `subscriptions` array.
3. When WooCommerce Subscriptions creates a renewal order, the plugin stores a `subscription_renewal` custom JSON payload on that renewal order.
4. The renewal order moves to `on-hold` while CorePay processes the recurring payment.
5. CorePay confirms the renewal by sending a webhook with the renewal order ID and key.
6. The webhook calls `$renewal_order->payment_complete()`, allowing WooCommerce Subscriptions to record the renewal payment.

The plugin also fires this integration hook when a recurring payload is ready:

```php
do_action( 'corepay_money_recurring_payment_payload_created', $renewal_order, $payload, $gateway );
```

Use that hook to POST the custom JSON to a future CorePay server-side recurring endpoint if needed. Until then, the renewal order contains the generated `_corepay_money_payload` meta and waits for a CorePay webhook.

Zero-total subscriptions, trials, and payment-method-change flows complete without opening the widget.

## Widget payload

The widget receives a `custom_json` form field with this shape:

```json
{
    "provider": "woocommerce",
    "gateway": "corepay_money",
    "organization": "example.com",
    "payment": {
        "type": "subscription_initial",
        "recurring": true
    },
    "order": {
        "id": "123",
        "number": "123",
        "key": "wc_order_key",
        "amount": "49.00",
        "currency": "EUR",
        "description": "WooCommerce order 123"
    },
    "merchant": {
        "core_id": "CB…",
        "operator": {
            "id": "CB…",
            "operator": "ping"
        }
    },
    "operators": [],
    "subscriptions": [
        {
            "id": "456",
            "number": "456",
            "status": "pending",
            "billing_period": "month",
            "billing_interval": 1,
            "total": "49.00",
            "currency": "EUR",
            "start_date": "2026-06-04 12:00:00",
            "next_payment": "2026-07-04 12:00:00",
            "end_date": ""
        }
    ],
    "digitize": true,
    "urls": {
        "webhook": "https://example.com/wc-api/corepay_money",
        "return": "https://example.com/checkout/order-received/123/",
        "cancel": "https://example.com/cart/"
    },
    "customer": {
        "email": "customer@example.com",
        "name": "Customer Name"
    },
    "additional_data": {
        "plugin": "corepay-gateway-for-woocommerce",
        "shop": "example.com",
        "version": "0.1.2"
    }
}
```

The `additional_data` object is canonicalized with sorted keys and checked to ensure its JSON representation is no more than 250 characters. If the shop host ever makes it too long, the plugin falls back to a smaller safe object.

Payment `type` values:

- `checkout` for one-time product orders.
- `subscription_initial` for initial subscription checkout.
- `subscription_renewal` for renewal orders created by WooCommerce Subscriptions.

## Webhook endpoint

The webhook URL is included in each payload and follows WooCommerce's API callback format:

```text
https://example.com/wc-api/corepay_money
```

Accepted successful status values:

- `paid`
- `payment_paid`
- `completed`
- `complete`
- `success`
- `succeeded`

Accepted failure status values:

- `failed`
- `failure`
- `cancelled`
- `canceled`
- `expired`

Minimum webhook JSON for one-time or recurring orders:

```json
{
    "order_id": 123,
    "order_key": "wc_order_key",
    "status": "paid",
    "transaction_id": "corepay-tx-123",
    "recurring_id": "corepay-recurring-456",
    "amount": "49.00",
    "currency": "EUR"
}
```

Nested order fields are also supported:

```json
{
    "order": {
        "id": 123,
        "key": "wc_order_key",
        "amount": "49.00",
        "currency": "EUR"
    },
    "payment_status": "succeeded",
    "payment_id": "corepay-tx-123"
}
```

For recurring payments, `order_id` must be the WooCommerce renewal order ID, not only the subscription ID.

## Development

Run PHP syntax checks from the plugin root:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

This repository uses the standard plugin-root layout for Git development:

- `corepay-money-woocommerce.php` is the main plugin file.
- `includes` contains PHP classes.
- `assets` contains plugin runtime CSS and JavaScript used by the plugin, such as `assets/admin.css`, `assets/admin.js`, and `assets/blocks.js`.
- `.wordpress-org/assets` contains WordPress.org-only directory assets such as icons, banners, and screenshots.
- `readme.txt` contains WordPress.org directory metadata.

Deploy to WordPress.org SVN:

```sh
npm run deploy:wporg -- 0.1.2
```

The deployment script syncs plugin files into SVN `trunk`, syncs `.wordpress-org/assets` into SVN top-level `assets`, creates a version tag, shows `svn status`, and commits.

## License

Licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
