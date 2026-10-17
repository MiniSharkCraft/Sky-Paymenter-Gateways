# SkySePay implementation notes

## Paymenter contract verification

The extension targets Paymenter v1.5.9's `App\Classes\Extension\Gateway` abstract `pay(Invoice $invoice, $total)` method, `Extension::config()` settings, `boot()` route loading, `ExtensionHelper::addPayment()`, and `InvoiceTransactionStatus::Succeeded`. The production v1.5.9 source was inspected read-only at `/var/www/paymenter`; no production files or settings were changed as part of this implementation.

Paymenter v1.5.9's helper locks the invoice row but its `invoice_transactions.transaction_id` column is not unique. SkySePay therefore adds an extension-owned table with a unique provider transaction ID. The ledger claim, invoice validation, Paymenter transaction creation, and reconciliation status update execute in one database transaction. This handles duplicate and concurrent deliveries without changing core.

## Confirmation rules

A webhook is accepted for payment only if all conditions pass:

1. HMAC-SHA256 matches SePay's documented `{timestamp}.{raw_body}` signing format and timestamp is within 300 seconds.
2. The payload parses as the documented SePay transaction shape; the transfer is incoming.
3. `accountNumber` matches the configured beneficiary account exactly.
4. `code` matches a valid HMAC-bound reference that resolves to an invoice ID.
5. The invoice exists, is VND, and is pending.
6. The current remaining amount is a positive whole VND integer and exactly equals `transferAmount`.
7. The SePay transaction ID is claimed for the first time by the unique ledger row.

Partial, overpaid, unmatched, invalid-account, outbound, non-pending, and fractional-value events are logged in the ledger and do not create a successful Paymenter transaction. An exact duplicate is acknowledged without repeating the payment write. The browser QR page only displays instructions and never changes invoice status.

## Provider data and authentication

The implementation follows the official SePay webhook payload and HMAC documentation. It does not use a provider API endpoint or make external calls during payment confirmation. The QR is rendered by the official SePay documented VietQR image URL. The endpoint URL and HMAC configuration must be set in SePay by an operator; no credentials are included in source.

## Known boundary: VND rounding

Paymenter v1.5.9 can produce decimal invoice totals. Since this request prohibits modifying Paymenter core and financial records, SkySePay fails closed for any invoice amount that is not representable as an exact whole VND amount. For rollout, product prices and discounts must be configured so generated VND invoice totals remain integer. A change to general discount rounding belongs in a separate Paymenter core change and review.
