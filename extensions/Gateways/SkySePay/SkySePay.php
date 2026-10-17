<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\SkySePay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Gateways\SkySePay\Src\PaymentReference;
use Paymenter\Extensions\Gateways\SkySePay\Src\VndAmount;
use Paymenter\Extensions\Gateways\SkySePay\Src\WebhookAuthenticator;
use Paymenter\Extensions\Gateways\SkySePay\Src\WebhookEventLedger;
use Paymenter\Extensions\Gateways\SkySePay\Src\WebhookPayload;
use JsonException;
use Throwable;

#[ExtensionMeta(
    name: 'SkySePay',
    description: 'VND bank transfer payments with SePay webhook confirmation.',
    version: '1.0.0',
    author: 'Sky Hosting',
    url: 'https://github.com/MiniSharkCraft/Sky-Paymenter-Gateways'
)]
class SkySePay extends Gateway
{
    public function boot(): void
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('gateways.skysepay', __DIR__ . '/resources/views');
    }

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'bank_code',
                'label' => 'Bank code (SePay short_name, alias, code, or BIN)',
                'type' => 'text',
                'required' => true,
                'description' => 'Use a bank identifier supported by SePay VietQR.',
            ],
            [
                'name' => 'account_number',
                'label' => 'Beneficiary account number or VA',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'account_name',
                'label' => 'Beneficiary account name',
                'type' => 'text',
                'required' => true,
                'description' => 'Shown to the customer with the transfer instructions.',
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'SePay HMAC-SHA256 webhook secret',
                'type' => 'text',
                'encrypted' => true,
                'required' => true,
                'description' => 'Create the SePay webhook with HMAC-SHA256 and store its secret here.',
            ],
            [
                'name' => 'reference_secret',
                'label' => 'Payment reference signing secret',
                'type' => 'text',
                'encrypted' => true,
                'required' => true,
                'description' => 'Generate a separate random secret. Keep it stable while invoices are open.',
            ],
        ];
    }

    public function pay(Invoice $invoice, $total)
    {
        if ($invoice->currency_code !== 'VND') {
            return redirect()->route('invoices.show', $invoice)->with('notification', [
                'type' => 'error',
                'message' => 'SkySePay accepts VND invoices only.',
            ]);
        }

        $amount = VndAmount::parseExact($total);
        $referenceSecret = (string) $this->config('reference_secret');
        if ($amount === null || $amount < 1 || $referenceSecret === '') {
            Log::warning('SkySePay checkout rejected a non-integer VND amount or missing reference secret.', [
                'invoice_id' => $invoice->id,
            ]);

            return redirect()->route('invoices.show', $invoice)->with('notification', [
                'type' => 'error',
                'message' => 'This invoice total cannot be represented as whole VND. Contact support before paying.',
            ]);
        }

        $reference = PaymentReference::forInvoice((int) $invoice->id, $referenceSecret);
        $bankCode = trim((string) $this->config('bank_code'));
        $accountNumber = trim((string) $this->config('account_number'));
        $accountName = trim((string) $this->config('account_name'));
        if ($bankCode === '' || $accountNumber === '' || $accountName === '') {
            return redirect()->route('invoices.show', $invoice)->with('notification', [
                'type' => 'error',
                'message' => 'SkySePay bank details are incomplete.',
            ]);
        }

        $qrUrl = 'https://vietqr.app/img?' . http_build_query([
            'acc' => $accountNumber,
            'bank' => $bankCode,
            'amount' => $amount,
            'des' => $reference,
            'template' => 'compact',
            'showinfo' => 'true',
            'holder' => iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $accountName) ?: $accountName,
        ], '', '&', PHP_QUERY_RFC3986);

        return view('gateways.skysepay::pay', [
            'invoice' => $invoice,
            'amount' => $amount,
            'reference' => $reference,
            'bankCode' => $bankCode,
            'accountNumber' => $accountNumber,
            'accountName' => $accountName,
            'qrUrl' => $qrUrl,
        ]);
    }

    public function webhook(Request $request)
    {
        $rawBody = $request->getContent();
        if (strlen($rawBody) > 65536) {
            return response()->json(['success' => false, 'message' => 'Payload too large'], 413);
        }

        if (!WebhookAuthenticator::verifyHmac(
            $rawBody,
            (string) $request->header('X-SePay-Signature', ''),
            (string) $request->header('X-SePay-Timestamp', ''),
            (string) $this->config('webhook_secret'),
        )) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['success' => false, 'message' => 'Invalid JSON'], 400);
        }

        $event = WebhookPayload::parse($payload);
        if ($event === null) {
            return response()->json(['success' => false, 'message' => 'Invalid transaction payload'], 400);
        }

        try {
            DB::transaction(function () use ($event, $rawBody): void {
                $ledger = new WebhookEventLedger(DB::connection()->getPdo());
                if (!$ledger->claim($event['id'], hash('sha256', $rawBody))) {
                    return;
                }

                $update = [
                    'payment_code' => $event['code'] !== '' ? $event['code'] : null,
                    'bank_account' => $event['account_number'],
                    'transfer_amount' => $event['amount'],
                    'reference_code' => $event['reference_code'] !== '' ? $event['reference_code'] : null,
                    'updated_at' => now(),
                ];
                $finish = function (string $status, string $reason, ?int $invoiceId = null) use (&$update, $event): void {
                    DB::table('skysepay_webhook_events')->where('transaction_id', $event['id'])->update($update + [
                        'invoice_id' => $invoiceId,
                        'status' => $status,
                        'reason' => $reason,
                    ]);
                };

                if ($event['transfer_type'] !== 'in') {
                    $finish('ignored', 'Outbound transaction');

                    return;
                }
                if (!hash_equals(trim((string) $this->config('account_number')), $event['account_number'])) {
                    $finish('rejected', 'Beneficiary account mismatch');

                    return;
                }

                $referenceSecret = (string) $this->config('reference_secret');
                $invoiceId = PaymentReference::invoiceIdFromCode($event['code'], $referenceSecret);
                if ($invoiceId === null) {
                    $finish('unmatched', 'Payment code missing or invalid');

                    return;
                }

                /** @var Invoice|null $invoice */
                $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
                if (!$invoice) {
                    $finish('unmatched', 'Invoice not found', $invoiceId);

                    return;
                }
                if ($invoice->currency_code !== 'VND') {
                    $finish('rejected', 'Invoice currency is not VND', $invoice->id);

                    return;
                }
                if ($invoice->status !== Invoice::STATUS_PENDING) {
                    $finish('review', 'Invoice is not pending', $invoice->id);

                    return;
                }

                $invoice->load(['items', 'transactions']);
                $remaining = VndAmount::parseExact($invoice->remaining);
                if ($remaining === null || $remaining < 1) {
                    $finish('review', 'Invoice remaining amount is not a positive whole VND value', $invoice->id);

                    return;
                }

                if (!WebhookPayload::matchesAmount($event, $remaining)) {
                    $disposition = WebhookPayload::amountDisposition($event, $remaining);
                    $finish($disposition, 'Transfer amount does not exactly match invoice remaining amount', $invoice->id);

                    return;
                }

                ExtensionHelper::addPayment(
                    $invoice->id,
                    class_basename(self::class),
                    $event['amount'],
                    null,
                    'sepay:' . $event['id'],
                    InvoiceTransactionStatus::Succeeded,
                );
                $finish('paid', 'Authenticated incoming transfer matched exact invoice amount', $invoice->id);
            }, 3);
        } catch (Throwable $exception) {
            Log::error('SkySePay webhook processing failed.', [
                'transaction_id' => $event['id'],
                'exception' => $exception::class,
            ]);

            return response()->json(['success' => false, 'message' => 'Processing failed'], 500);
        }

        return response()->json(['success' => true]);
    }
}
