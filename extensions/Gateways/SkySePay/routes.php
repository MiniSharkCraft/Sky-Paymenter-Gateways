<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\SkySePay\SkySePay;

Route::post('/extensions/skysepay/webhook', [SkySePay::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.skysepay.webhook');
