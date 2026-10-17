<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\SkySePay\Src;

final class WebhookAuthenticator
{
    public static function verifyHmac(string $rawBody, string $signature, string $timestamp, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || !preg_match('/\A[0-9]{1,12}\z/', $timestamp) || !preg_match('/\Asha256=[a-f0-9]{64}\z/i', $signature)) {
            return false;
        }

        $sentAt = (int) $timestamp;
        if (abs(($now ?? time()) - $sentAt) > 300) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, strtolower($signature));
    }
}
