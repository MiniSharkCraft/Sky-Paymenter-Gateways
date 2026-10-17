<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skysepay_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 64)->unique();
            $table->char('payload_hash', 64);
            $table->string('payment_code', 64)->nullable();
            $table->string('bank_account', 64)->nullable();
            $table->unsignedBigInteger('transfer_amount')->nullable();
            $table->string('reference_code', 128)->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('status', 32)->default('received')->index();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skysepay_webhook_events');
    }
};
