<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\SkySePay\Src;

final class PaymentReference
{
    private const PREFIX = 'SKY';
    private const ID_WIDTH = 10;
    private const CHECKSUM_LENGTH = 8;

    public static function forInvoice(int $invoiceId, string $secret): string
    {
        if ($invoiceId < 1 || $secret === '') {
            throw new \InvalidArgumentException('Invoice ID and payment reference secret are required.');
        }

        $encodedId = strtoupper(base_convert((string) $invoiceId, 10, 36));
        if (strlen($encodedId) > self::ID_WIDTH) {
            throw new \InvalidArgumentException('Invoice ID exceeds the supported payment reference range.');
        }

        $body = self::PREFIX . str_pad($encodedId, self::ID_WIDTH, '0', STR_PAD_LEFT);
        $checksum = strtoupper(substr(hash_hmac('sha256', $body, $secret), 0, self::CHECKSUM_LENGTH));

        return $body . $checksum;
    }

    public static function invoiceIdFromCode(string $code, string $secret): ?int
    {
        if ($secret === '' || !preg_match('/\ASKY([0-9A-Z]{10})([A-F0-9]{8})\z/', strtoupper($code), $matches)) {
            return null;
        }

        $body = self::PREFIX . $matches[1];
        $expected = strtoupper(substr(hash_hmac('sha256', $body, $secret), 0, self::CHECKSUM_LENGTH));
        if (!hash_equals($expected, $matches[2])) {
            return null;
        }

        $id = base_convert(ltrim($matches[1], '0') ?: '0', 36, 10);
        if (!ctype_digit($id) || (string) (int) $id !== $id || (int) $id < 1) {
            return null;
        }

        return (int) $id;
    }
}
