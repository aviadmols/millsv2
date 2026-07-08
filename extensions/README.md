# Shopify extensions

## mills-subscriptions-channel (Sales Channel)

Registers the app as a **Sales Channel** so orders the system creates appear under
the app's native **Channel** column in Shopify Admin (D17 / ARCHITECTURE.md §1b).

**Why it exists:** setting `source_name` on an order (which `ShopifyOrderAttribution`
does) is *necessary but not sufficient* — the native Channel column is only
populated when the app is a registered Sales Channel and `source_name` equals the
channel handle (`mills-subscriptions`). This mirrors the working precedent in the
PayPlus engine.

### One-time setup (Aviad)

1. Create the app in the Partner Dashboard (custom distribution) → get
   `client_id`/`client_secret`; fill `shopify.app.toml`.
2. `shopify app deploy` — publishes this channel extension.
3. Approve the app as a Sales Channel on the store.
4. Verify: place a test recurring order → it shows under the **Mills Subscriptions**
   channel in Shopify Admin → Orders.
