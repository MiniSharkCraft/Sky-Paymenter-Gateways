<?php

declare(strict_types=1);

require __DIR__ . '/../extensions/Gateways/SkySePay/src/WebhookAuthenticator.php';
require __DIR__ . '/../extensions/Gateways/SkySePay/src/PaymentReference.php';
require __DIR__ . '/../extensions/Gateways/SkySePay/src/VndAmount.php';
require __DIR__ . '/../extensions/Gateways/SkySePay/src/WebhookPayload.php';
require __DIR__ . '/../extensions/Gateways/SkySePay/src/WebhookEventLedger.php';

use Paymenter\Extensions\Gateways\SkySePay\Src\WebhookAuthenticator;
use Paymenter\Extensions\Gateways\SkySePay\Src\PaymentReference;
use Paymenter\Extensions\Gateways\SkySePay\Src\VndAmount;
use Paymenter\Extensions\Gateways\SkySePay\Src\WebhookPayload;
use Paymenter\Extensions\Gateways\SkySePay\Src\WebhookEventLedger;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$secret = 'synthetic-webhook-secret';
$timestamp = 1_800_000_000;
$body = '{"id":123,"transferType":"in","transferAmount":25000}';
$signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
check(WebhookAuthenticator::verifyHmac($body, $signature, (string) $timestamp, $secret, $timestamp), 'valid HMAC should pass');
check(!WebhookAuthenticator::verifyHmac($body, 'sha256=' . str_repeat('0', 64), (string) $timestamp, $secret, $timestamp), 'invalid HMAC must fail');
check(!WebhookAuthenticator::verifyHmac($body, $signature, (string) $timestamp, $secret, $timestamp + 301), 'stale HMAC timestamp must fail');

$referenceKey = 'synthetic-reference-key';
$reference = PaymentReference::forInvoice(987654, $referenceKey);
check(PaymentReference::invoiceIdFromCode($reference, $referenceKey) === 987654, 'payment code should resolve to its invoice');
check(PaymentReference::invoiceIdFromCode($reference . 'X', $referenceKey) === null, 'tampered payment code must fail');
check(PaymentReference::forInvoice(987654, $referenceKey) === $reference, 'payment code must remain stable');

check(VndAmount::parseExact('25000.00') === 25000, 'integer VND amount should parse exactly');
check(VndAmount::parseExact('25000') === 25000, 'integer VND amount should parse');
check(VndAmount::parseExact('25000.50') === null, 'fractional VND amount must fail closed');
check(VndAmount::parseExact('25,000') === null, 'formatted input must not be parsed ambiguously');
check(VndAmount::parseExact(25000.25) === null, 'fractional float must never be rounded into VND');
check(VndAmount::parseExact(25000.0) === 25000, 'integer-valued float may be represented exactly');

$payload = [
    'id' => 123,
    'gateway' => 'Synthetic Bank',
    'transactionDate' => '2026-10-18 12:00:00',
    'accountNumber' => '0012345678',
    'code' => $reference,
    'content' => $reference . ' test',
    'transferType' => 'in',
    'transferAmount' => 25000,
    'referenceCode' => 'SYNTHETIC-123',
];
$parsed = WebhookPayload::parse($payload);
check($parsed !== null && $parsed['id'] === '123', 'official SePay payload should parse');
check(WebhookPayload::matchesAmount($parsed, 25000), 'exact payment should match');
check(!WebhookPayload::matchesAmount($parsed, 25001), 'incorrect payment amount must not match');
check(WebhookPayload::amountDisposition($parsed, 25001) === 'partial', 'partial transfer must be classified');
check(WebhookPayload::amountDisposition($parsed, 24999) === 'overpayment', 'overpayment must be classified');
$payload['transferType'] = 'out';
check(WebhookPayload::parse($payload)['transfer_type'] === 'out', 'outbound bank transfer must be identified and rejected by reconciliation');

$dbPath = sys_get_temp_dir() . '/skysepay-ledger-' . bin2hex(random_bytes(6)) . '.sqlite';
$pdo = new PDO('sqlite:' . $dbPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE skysepay_webhook_events (transaction_id VARCHAR(128) PRIMARY KEY, payload_hash CHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
$ledger = new WebhookEventLedger($pdo);
check($ledger->claim('synthetic-duplicate', hash('sha256', 'event')), 'first webhook delivery should claim transaction');
check(!$ledger->claim('synthetic-duplicate', hash('sha256', 'event')), 'replayed webhook must be idempotent');
$pdo = null;

$children = [];
for ($i = 0; $i < 6; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $childPdo = new PDO('sqlite:' . $dbPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $childPdo->exec('PRAGMA busy_timeout = 5000');
        usleep(random_int(0, 50000));
        $childLedger = new WebhookEventLedger($childPdo);
        exit($childLedger->claim('concurrent-delivery', hash('sha256', 'same-event')) ? 0 : 1);
    }
    $children[] = $pid;
}
$claimed = 0;
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    check(pcntl_wifexited($status), 'concurrent worker should exit normally');
    $claimed += pcntl_wexitstatus($status) === 0 ? 1 : 0;
}
check($claimed === 1, 'concurrent delivery must be claimed exactly once');
unlink($dbPath);

fwrite(STDOUT, "PASS: auth, timestamp replay, idempotency, six-way concurrent delivery, payment references, exact VND and payment amount classification\n");
