// @ts-check

/**
 * Hide PayPal when the cart holds a subscription.
 *
 * A subscription paid through PayPal is a customer we can never charge again:
 * Shopify's PayPal checkout hands the merchant no reusable billing agreement — the
 * consent is PayPal's with Shopify, not with us — so the next cycle has nothing to
 * charge and the shopper who has just paid is asked for a card instead. This is the
 * only place that failure can be prevented rather than apologised for.
 *
 * FAILS OPEN on purpose. Every uncertain path returns NO_CHANGES, because the worst
 * outcome here is not "PayPal was offered": it is a checkout nobody can complete. A
 * subscription paid by PayPal is recoverable — the customer updates a card in the
 * personal area. A blocked checkout is a lost sale.
 *
 * @typedef {import("../generated/api").CartPaymentMethodsTransformRunInput} CartPaymentMethodsTransformRunInput
 * @typedef {import("../generated/api").CartPaymentMethodsTransformRunResult} CartPaymentMethodsTransformRunResult
 */

/** @type {CartPaymentMethodsTransformRunResult} */
const NO_CHANGES = {
  operations: [],
};

/**
 * Matched on the NAME, not the id: the id is a per-shop gid, and Shopify presents
 * the method as "PayPal" or "PayPal Express Checkout" depending on the setup.
 * Both have to be caught.
 */
const PAYPAL_PATTERN = /paypal/i;

/**
 * @param {CartPaymentMethodsTransformRunInput} input
 * @returns {CartPaymentMethodsTransformRunResult}
 */
export function cartPaymentMethodsTransformRun(input) {
  const lines = input?.cart?.lines ?? [];

  const hasSubscription = lines.some(
    (line) => (line?.subscriptionFlag?.value ?? "").trim() !== ""
  );

  if (!hasSubscription) {
    return NO_CHANGES;
  }

  const methods = input?.paymentMethods ?? [];
  const paypal = methods.filter((method) =>
    PAYPAL_PATTERN.test(method?.name ?? "")
  );

  if (paypal.length === 0) {
    return NO_CHANGES;
  }

  // Never hide the last option standing: a checkout with no payment method is
  // worse than the problem this function exists to solve.
  if (paypal.length >= methods.length) {
    return NO_CHANGES;
  }

  return {
    operations: paypal.map((method) => ({
      paymentMethodHide: { paymentMethodId: method.id },
    })),
  };
}
