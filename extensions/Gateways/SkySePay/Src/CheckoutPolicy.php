<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\SkySePay\Src;

final class CheckoutPolicy
{
    public static function allows(mixed $checkoutEnabled, string $currency): bool
    {
        return filter_var($checkoutEnabled, FILTER_VALIDATE_BOOLEAN) && strtoupper($currency) === 'VND';
    }
}
