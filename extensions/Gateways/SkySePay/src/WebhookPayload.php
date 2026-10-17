<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\SkySePay\Src;

final class WebhookPayload
{
    /** @return array{id:string,account_number:string,code:string,content:string,transfer_type:string,amount:int,reference_code:string,transaction_date:string}|null */
    public static function parse(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;
        $amount = $payload['transferAmount'] ?? null;
        if (!is_int($id) || $id < 1 || !is_int($amount) || $amount < 0) {
            return null;
        }

        $account = $payload['accountNumber'] ?? null;
        $type = $payload['transferType'] ?? null;
        if (!is_string($account) || $account === '' || !is_string($type) || !in_array($type, ['in', 'out'], true)) {
            return null;
        }

        $code = $payload['code'] ?? '';
        if ($code !== null && !is_string($code)) {
            return null;
        }
        $content = $payload['content'] ?? '';
        $reference = $payload['referenceCode'] ?? '';
        $date = $payload['transactionDate'] ?? '';
        if (!is_string($content) || !is_string($reference) || !is_string($date)) {
            return null;
        }

        return [
            'id' => (string) $id,
            'account_number' => $account,
            'code' => $code ?? '',
            'content' => $content,
            'transfer_type' => $type,
            'amount' => $amount,
            'reference_code' => $reference,
            'transaction_date' => $date,
        ];
    }

    public static function matchesAmount(array $payload, int $expected): bool
    {
        return $payload['amount'] === $expected;
    }

    public static function amountDisposition(array $payload, int $expected): string
    {
        if ($payload['amount'] < $expected) {
            return 'partial';
        }
        if ($payload['amount'] > $expected) {
            return 'overpayment';
        }

        return 'exact';
    }
}
