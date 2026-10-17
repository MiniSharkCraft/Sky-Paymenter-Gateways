# Sky gateway architecture

This repository is the Sky Hosting development fork of [VaibhavSys/PhonePe-Paymenter](https://github.com/VaibhavSys/PhonePe-Paymenter). The inherited PhonePe extension and its license notices are retained as the reference implementation. The upstream license is MIT, copyright Vaibhav Dhiman (2024); retain that notice with inherited code. PhonePe branding remains the provider's trademark, and this project is not affiliated with PhonePe.

## Paymenter integration seam

Paymenter v1.5.9 uses `App\Classes\Extension\Gateway`, with `pay(Invoice $invoice, $total)` for initiation and extension routes loaded from `boot()`. A verified callback can record payment through `ExtensionHelper::addPayment(...)`. When passed a stable provider transaction ID, the helper wraps the update in a database transaction and uses `updateOrCreate` for repeat delivery.

Each provider adapter should own only its protocol: credentials, request payload, provider response, callback authentication, provider status mapping and provider fixtures. Shared Paymenter code should own invoice lookup, payable-state checks, exact amount/currency comparison, idempotency, payment recording, redacted logs and safe redirects. A callback must not mark an invoice paid unless authenticated provider evidence matches the still-payable invoice.

## Reference limitations

The inherited PhonePe code is not a compatibility template for SePay or Card2K:

- It converts amounts with a factor of 100 for INR. VND has zero minor-unit decimals; never reuse this conversion for VND.
- Its webhook verifies a checksum and success status, but does not reconcile paid amount/currency or enforce invoice state.
- It includes posted callback data in exception logs. Sky code must redact credentials and callback bodies.
- Its “foreign currency” option treats foreign amounts as INR.
- The extension README only documents Paymenter V1 Beta and references Paymenter v0.9.5; no supported matrix confirms Paymenter 1.5.9. The method and route shape align with v1.5.9, but provider behavior and lifecycle require testing.

## Sky SePay

Implement as a separate Paymenter gateway extension. Pin the exact SePay product and webhook authentication mode first. Official SePay material describes HTTPS POST webhooks, HMAC-SHA256/API Key/OAuth 2.0 options and retry delivery. Authenticate the chosen mode, accept inbound transfers only, reconcile transaction ID, payment code, VND amount and outstanding invoice, then record once with a stable transaction ID. Test duplicate/replayed notices, retry timing, partial/overpayment, wrong account/code and malformed payloads with synthetic fixtures. Do not copy PhonePe authentication or amount units.

## Sky Card2K

Implement as a separate adapter only after obtaining the current partner API guide and sandbox. Public pages found during discovery do not establish the exact merchant initiation route, callback schema, signing/authentication contract, transaction ID semantics, status mapping or amount units for the intended product. Do not infer these from PhonePe or other Card2K products.

## Release gates

Both gateways stay disabled until fixtures pass authentication, replay/idempotency, invoice-state, exact amount/currency, refund/failure and provider retry cases. Never place provider credentials in source, docs, logs or test fixtures.
