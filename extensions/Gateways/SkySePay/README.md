# SkySePay for Paymenter 1.5.9

SkySePay is a VND-only bank-transfer gateway extension for Paymenter. It uses SePay's documented HMAC-SHA256 webhook scheme and documented VietQR image URL. It does not call a payment API or use a browser redirect to confirm payment.

## Compatibility

- Paymenter: v1.5.9 Gateway contract (`pay(Invoice $invoice, $total)`), Laravel 12.
- PHP: 8.3 or 8.4 as required by Paymenter v1.5.9. This extension has no external Composer dependency.
- Currency: VND only, whole VND amounts. Any fractional invoice total fails closed for checkout and webhook matching.
- Database: Paymenter's configured MariaDB/MySQL connection. The extension adds one migration-owned ledger table; Paymenter core is not modified.

## Install

1. Back up the Paymenter application and database. Install first in staging; leave this gateway disabled until SePay's test webhook and reconciliation checks pass.
2. Copy this directory to `extensions/Gateways/SkySePay` under the Paymenter application root.
3. Run the extension ledger migration using the Paymenter's PHP 8.4 binary from the application root:

   ```sh
   /usr/bin/php8.4 artisan migrate --path=extensions/Gateways/SkySePay/database/migrations --force
   ```

4. In Paymenter Admin, add the **SkySePay** gateway. Configure the bank identifier, beneficiary account number (or the bank's required VA), account name, HMAC webhook secret, and a separate payment-reference secret.
5. Generate two independent random secrets (at least 32 random bytes each). Configure SePay's webhook with **HMAC-SHA256**, and copy its secret into the encrypted Paymenter gateway setting immediately. Do not use SePay's unauthenticated webhook mode.
6. Configure SePay's Company → General Settings → Payment Code Structure with prefix `SKY` and suffix length `18`. The extension emits a stable 21-character reference (`SKY` + 10 base36 invoice ID characters + 8 HMAC characters). Keep the reference secret unchanged while invoices using this gateway remain open.
7. Point the SePay webhook to `https://YOUR_PAYMENTER_HOST/extensions/skysepay/webhook`, set event type to money-in only, JSON content type, and HMAC-SHA256 authentication. SePay's current docs require a publicly reachable HTTPS URL.
8. Check the generated webhook URL route in Paymenter and send a **SePay Test mode** webhook before enabling the gateway for customers. Use only synthetic test invoices and test bank details.

The gateway has an explicit `Enable customer checkout` setting, unchecked by default. Paymenter 1.5.9 considers `canUseGateway()` when building checkout options; without this guard, adding a gateway record can expose it regardless of its `enabled` column. Keep the setting off throughout installation and synthetic testing. Enabling it is a separate operator action after approval and staging validation.

## Configuration

| Setting | Meaning |
|---|---|
| Enable customer checkout | Explicit opt-in; unchecked by default. Keep off for installation and synthetic tests. |
| Bank code | SePay VietQR bank short name, alias, code, or BIN |
| Account number | Beneficiary account number or bank-supported VA; preserve leading zeroes |
| Account name | Display name shown beside the QR |
| HMAC webhook secret | SePay webhook Secret Key; encrypted by Paymenter's extension configuration |
| Payment reference signing secret | Separate stable random secret used to bind invoice references |

Only VND invoices are accepted. SePay's webhook `transferAmount` must exactly match the whole-VND invoice amount still due. Fractional VND totals, partial payments, overpayments, non-VND invoices, incorrect account numbers, outbound transfers, invalid references, and already-paid invoices never confirm payment. Reconciliation outcomes are written to `skysepay_webhook_events`; no raw webhook body or secret is stored there.

The checkout embeds SePay's documented `https://vietqr.app/img` QR service. This transmits the configured account number, amount, bank code, holder display name, and invoice payment reference to that image service. If this data flow is unacceptable, do not enable the gateway; implement local VietQR rendering before use.

## Tests

From the repository root, run:

```sh
php tests/run.php
find extensions/Gateways/SkySePay -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

The synthetic suite covers valid/invalid HMAC, timestamp replay rejection, authenticated transaction idempotency, six-way concurrent event claims using SQLite, stable/tamper-checked references, exact integer VND conversion, invalid fractional amounts, invalid payment amount, and outbound transfer classification. It requires PHP CLI with PDO SQLite and PCNTL. It never contacts SePay or a bank.

## Rollback

1. Disable SkySePay in Paymenter Admin and remove/disable its SePay webhook in the SePay dashboard.
2. Restore the backed-up extension directory if an earlier version existed. Keep the Paymenter app, invoices, invoice transactions, and `skysepay_webhook_events` table intact for reconciliation.
3. Do not run `migrate:rollback` for the ledger migration while event history must be preserved. If the gateway is retired permanently, export and reconcile the ledger first; only then may an administrator remove its table through a reviewed maintenance procedure.
4. Re-enable a previous gateway only after confirming its configuration and test route. This extension does not modify Paymenter core or other gateway routes.

## Provider behavior and limitations

SePay's official webhook contract says the transaction ID is stable across retries/replays. The ledger's unique transaction ID enforces one processing claim, including concurrent delivery. HMAC verification uses the exact request body and the provider's timestamp/signature headers; the timestamp window is five minutes. Exact amount comparison is done while the invoice row is locked. Invalid business events are stored for reconciliation and acknowledged with `{"success":true}` to avoid pointless provider retries. Authentication or processing failures return an error to trigger SePay's retry behavior.

Paymenter v1.5.9 stores invoice amounts in decimal columns and can calculate non-integer amounts. The extension deliberately rejects fractional VND rather than silently rounding or changing invoice records. Configure whole-VND prices/discounts and verify the resulting invoice total before enabling SkySePay. Correcting Paymenter-wide discount rounding would require a separate reviewed core change, which this extension does not make.

## Official references

- [SePay webhook integration and payload](https://developer.sepay.vn/en/sepay-webhooks/tich-hop-webhook)
- [SePay webhook authentication, HMAC formula, and headers](https://developer.sepay.vn/en/sepay-webhooks/xac-thuc)
- [SePay QR generation URL and bank/VA rules](https://developer.sepay.vn/en/tien-ich-khac/tao-qr-code)
- [Paymenter v1.5.9 Gateway source contract](https://github.com/Paymenter/Paymenter/tree/v1.5.9/app/Classes/Extension)
