<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\SkySePay\Src;

final class VndAmount
{
    public static function parseExact(mixed $amount): ?int
    {
        if (is_int($amount)) {
            return $amount >= 0 ? $amount : null;
        }

        if (is_float($amount)) {
            if (!is_finite($amount) || $amount < 0 || floor($amount) !== $amount) {
                return null;
            }
            $amount = sprintf('%.0f', $amount);
        }

        if (!is_string($amount) || !preg_match('/\A([0-9]+)(?:\.0{1,2})?\z/', $amount, $matches)) {
            return null;
        }

        $digits = ltrim($matches[1], '0') ?: '0';
        if (strlen($digits) > strlen((string) PHP_INT_MAX) || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $digits;
    }
}
