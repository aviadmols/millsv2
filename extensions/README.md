# Shopify extensions

## mills-subscriptions-channel (Sales Channel)

Registers the app as a **Sales Channel** so orders the system creates appear under
the app's native **Channel** column in Shopify Admin (D17 / ARCHITECTURE.md §1b).

**Why it exists:** setting `source_name` on an order (which `ShopifyOrderAttribution`
does) is *necessary but not sufficient* — the native Channel column is only
populated when the app is a registered Sales Channel and `source_name` equals the
channel handle (`mills-subscriptions`). This mirrors the working precedent in the
PayPlus engine.

## hide-paypal-on-subscriptions (Shopify Function)

Removes **PayPal** from checkout whenever the cart contains a subscription.

**Why it exists:** a subscription paid through PayPal is a customer we can never
charge again. Shopify's PayPal checkout hands the merchant no reusable billing
agreement — the consent is PayPal's with Shopify, not with us — so the next cycle
has nothing to charge, and the shopper who has just paid is asked for a card
instead. Unlike a PayMe checkout (where `CheckoutCardCapture` pulls the buyer_key
straight out of the payment), there is nothing to recover. Removing the option is
the only place that failure can be prevented rather than apologised for.

**How it decides:** the cart line's `_subscription` attribute — the same flag the
server treats as authoritative in `PaidOrderIngestor::subscriptionLines()`, so
checkout and ingestion can never disagree about what a subscription is.

**It fails open.** No subscription, no PayPal offered, PayPal as the only method,
malformed input — every uncertain path changes nothing. A subscription paid by
PayPal is recoverable (the customer updates a card); a checkout nobody can
complete is a lost sale.

### Setup

1. `shopify app deploy` — publishes the function.
2. Shopify Admin → **Settings → Payments → Payment method customizations** →
   **Add customization** → pick *Hide PayPal on subscriptions* → Activate.
   Until this is activated the function is deployed but inert.
3. Verify: add a subscription to the cart → PayPal is gone from checkout; an
   ordinary cart still shows it.

### Tests

`npx vitest run` inside the extension builds the real Wasm and runs it against
the fixtures in `tests/fixtures/`. Add a fixture per rule rather than mocking.

## One-time setup for the sales channel (Aviad)

1. Create the app in the Partner Dashboard (custom distribution) → get
   `client_id`/`client_secret`; fill `shopify.app.toml`.
2. `shopify app deploy` — publishes this channel extension.
3. Approve the app as a Sales Channel on the store.
4. Verify: place a test recurring order → it shows under the **Mills Subscriptions**
   channel in Shopify Admin → Orders.
