<?php

declare(strict_types=1);

if (getenv('SKYSEPAY_INTEGRATION_TEST') !== '1') {
    fwrite(STDERR, "Set SKYSEPAY_INTEGRATION_TEST=1 to run the synthetic Paymenter integration test.\n");
    exit(2);
}

$paymenterPath = getenv('PAYMENTER_PATH') ?: '/var/www/paymenter';
require $paymenterPath . '/vendor/autoload.php';
$app = require $paymenterPath . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Classes\Extension\Gateway as GatewayContract;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\SkySePay\SkySePay;
use Paymenter\Extensions\Gateways\SkySePay\Src\PaymentReference;

function integrationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function signedWebhook(SkySePay $gateway, array $payload, string $secret, ?string $signatureOverride = null, ?string $timestampOverride = null): Illuminate\Http\JsonResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = $timestampOverride ?? (string) time();
    $signature = $signatureOverride ?? ('sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret));
    $request = Request::create(
        '/extensions/skysepay/webhook',
        'POST',
        [],
        [],
        [],
        ['HTTP_X_SEPAY_SIGNATURE' => $signature, 'HTTP_X_SEPAY_TIMESTAMP' => $timestamp, 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    return $gateway->webhook($request);
}

DB::beginTransaction();
try {
    integrationCheck(!DB::table('extensions')->where('extension', 'SkySePay')->exists(), 'A persistent SkySePay gateway row already exists; aborting to avoid altering it.');

    $now = now();
    $gatewayId = DB::table('extensions')->insertGetId([
        'name' => 'SkySePay Synthetic Integration',
        'extension' => 'SkySePay',
        'type' => 'gateway',
        'enabled' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $suffix = bin2hex(random_bytes(8));
    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Synthetic',
        'last_name' => 'SkySePay Test',
        'email' => 'skysepay-' . $suffix . '@example.invalid',
        'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $invoiceId = DB::table('invoices')->insertGetId([
        'status' => Invoice::STATUS_PENDING,
        'currency_code' => 'VND',
        'user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('invoice_items')->insert([
        'invoice_id' => $invoiceId,
        'price' => '25000.00',
        'quantity' => 1,
        'description' => 'Synthetic SkySePay integration fixture',
        'reference_id' => null,
        'reference_type' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $webhookSecret = bin2hex(random_bytes(32));
    $referenceSecret = bin2hex(random_bytes(32));
    $config = [
        'checkout_enabled' => false,
        'bank_code' => 'VCB',
        'account_number' => '0000000000',
        'account_name' => 'SYNTHETIC TEST ONLY',
        'webhook_secret' => $webhookSecret,
        'reference_secret' => $referenceSecret,
    ];
    $gateway = new SkySePay($config);

    $available = collect(ExtensionHelper::getExtensions('gateway'))->pluck('name')->contains('SkySePay');
    integrationCheck($available, 'SkySePay is missing from Paymenter Admin gateway options.');
    integrationCheck(!$gateway->canUseGateway(25000, 'VND', 'invoice'), 'Checkout opt-in must stay disabled during integration testing.');
    integrationCheck(!collect(ExtensionHelper::getCheckoutGateways(25000, 'VND', 'invoice'))->contains(fn ($item) => $item->extension === 'SkySePay'), 'SkySePay leaked into public checkout while disabled.');

    $gateway->boot();
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($candidate) => $candidate->getName() === 'extensions.gateways.skysepay.webhook');
    integrationCheck($route !== null, 'Webhook POST route was not registered.');
    integrationCheck(in_array('POST', $route->methods(), true), 'Webhook route does not accept POST.');
    integrationCheck(in_array(Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, $route->excludedMiddleware(), true), 'Webhook route does not exclude Paymenter CSRF middleware.');

    $invoice = Invoice::query()->with(['items', 'transactions'])->findOrFail($invoiceId);
    $view = $gateway->pay($invoice, $invoice->remaining);
    integrationCheck($view instanceof Illuminate\View\View, 'Gateway did not return the Paymenter checkout view.');
    $html = $view->render();
    integrationCheck(str_contains($html, 'https://vietqr.app/img?'), 'Checkout view did not render the documented VietQR image URL.');
    $reference = PaymentReference::forInvoice($invoiceId, $referenceSecret);
    integrationCheck(str_contains($html, $reference) && str_contains($html, '25.000 ₫'), 'QR instructions do not show the synthetic payment reference and exact VND amount.');

    $payload = [
        'id' => random_int(900000000, 999999999),
        'gateway' => 'Synthetic Bank',
        'transactionDate' => now()->format('Y-m-d H:i:s'),
        'accountNumber' => '0000000000',
        'subAccount' => '',
        'code' => $reference,
        'content' => $reference,
        'transferType' => 'in',
        'transferAmount' => 24999,
        'accumulated' => 24999,
        'referenceCode' => 'SYN-' . $suffix,
    ];

    $invalidAuth = signedWebhook($gateway, $payload, $webhookSecret, 'sha256=' . str_repeat('0', 64));
    integrationCheck($invalidAuth->getStatusCode() === 401, 'Invalid HMAC was not rejected.');
    integrationCheck(DB::table('skysepay_webhook_events')->count() === 0, 'Invalid HMAC wrote a ledger event.');

    $partial = signedWebhook($gateway, $payload, $webhookSecret);
    integrationCheck($partial->getStatusCode() === 200 && $partial->getData(true) === ['success' => true], 'Authenticated partial transaction did not return SePay success response.');
    integrationCheck(DB::table('skysepay_webhook_events')->where('transaction_id', (string) $payload['id'])->value('status') === 'partial', 'Partial transaction was not logged for reconciliation.');
    integrationCheck(DB::table('invoice_transactions')->where('invoice_id', $invoiceId)->where('status', InvoiceTransactionStatus::Succeeded->value)->count() === 0, 'Partial transaction incorrectly confirmed invoice payment.');
    $partialReplay = signedWebhook($gateway, $payload, $webhookSecret);
    integrationCheck($partialReplay->getStatusCode() === 200 && DB::table('skysepay_webhook_events')->count() === 1, 'Replayed partial transaction was not idempotent.');

    $staleTimestamp = (string) (time() - 301);
    $staleBody = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $staleSignature = 'sha256=' . hash_hmac('sha256', $staleTimestamp . '.' . $staleBody, $webhookSecret);
    $stale = signedWebhook($gateway, $payload, $webhookSecret, $staleSignature, $staleTimestamp);
    integrationCheck($stale->getStatusCode() === 401, 'Stale HMAC timestamp was not rejected.');

    $overpaymentPayload = $payload;
    $overpaymentPayload['id'] = random_int(900000000, 999999999);
    $overpaymentPayload['transferAmount'] = 25001;
    $overpayment = signedWebhook($gateway, $overpaymentPayload, $webhookSecret);
    integrationCheck($overpayment->getStatusCode() === 200 && DB::table('skysepay_webhook_events')->where('transaction_id', (string) $overpaymentPayload['id'])->value('status') === 'overpayment', 'Overpayment was not logged safely.');

    $wrongAccountPayload = $payload;
    $wrongAccountPayload['id'] = random_int(900000000, 999999999);
    $wrongAccountPayload['accountNumber'] = '0000000001';
    $wrongAccount = signedWebhook($gateway, $wrongAccountPayload, $webhookSecret);
    integrationCheck($wrongAccount->getStatusCode() === 200 && DB::table('skysepay_webhook_events')->where('transaction_id', (string) $wrongAccountPayload['id'])->value('status') === 'rejected', 'Wrong beneficiary account was not rejected for reconciliation.');

    $successfulPayload = $payload;
    $successfulPayload['id'] = random_int(900000000, 999999999);
    $successfulPayload['transferAmount'] = 25000;
    $successful = signedWebhook($gateway, $successfulPayload, $webhookSecret);
    integrationCheck($successful->getStatusCode() === 200 && $successful->getData(true) === ['success' => true], 'Exact synthetic transaction was not accepted.');
    integrationCheck(DB::table('invoice_transactions')->where('invoice_id', $invoiceId)->where('gateway_id', $gatewayId)->where('transaction_id', 'sepay:' . $successfulPayload['id'])->where('status', InvoiceTransactionStatus::Succeeded->value)->count() === 1, 'Successful Paymenter invoice transaction was not recorded exactly once.');
    integrationCheck(Invoice::query()->findOrFail($invoiceId)->status === Invoice::STATUS_PAID, 'Exact synthetic VND invoice did not reach paid status.');
    $successfulReplay = signedWebhook($gateway, $successfulPayload, $webhookSecret);
    integrationCheck($successfulReplay->getStatusCode() === 200 && DB::table('invoice_transactions')->where('invoice_id', $invoiceId)->where('gateway_id', $gatewayId)->count() === 1, 'Successful callback replay created a duplicate Paymenter transaction.');

    throw new RuntimeException('__SKYSEPAY_TEST_COMPLETE__');
} catch (RuntimeException $exception) {
    DB::rollBack();
    if ($exception->getMessage() === '__SKYSEPAY_TEST_COMPLETE__') {
        integrationCheck(!DB::table('extensions')->where('extension', 'SkySePay')->exists(), 'Synthetic gateway row survived rollback.');
        integrationCheck(!DB::table('users')->where('id', $userId)->exists(), 'Synthetic user survived rollback.');
        integrationCheck(!DB::table('invoices')->where('id', $invoiceId)->exists(), 'Synthetic invoice survived rollback.');
        foreach ([$payload['id'], $overpaymentPayload['id'], $wrongAccountPayload['id'], $successfulPayload['id']] as $eventId) {
            integrationCheck(!DB::table('skysepay_webhook_events')->where('transaction_id', (string) $eventId)->exists(), 'Synthetic webhook ledger row survived rollback.');
        }
        fwrite(STDOUT, "PASS: Paymenter gateway discovery, checkout disabled, POST/CSRF route, QR rendering, HMAC rejection, stale timestamp, partial/overpayment/account mismatch, successful invoice recording and replay. All synthetic DB rows rolled back.\n");
        exit(0);
    }
    throw $exception;
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    throw $exception;
}
