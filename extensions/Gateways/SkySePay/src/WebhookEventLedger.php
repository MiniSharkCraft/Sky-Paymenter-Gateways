<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\SkySePay\Src;

use PDO;
use PDOException;

final class WebhookEventLedger
{
    public function __construct(private readonly PDO $connection) {}

    /** Must be called inside the same database transaction as payment processing. */
    public function claim(string $transactionId, string $payloadHash): bool
    {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO skysepay_webhook_events (transaction_id, payload_hash, status, created_at, updated_at) VALUES (:id, :hash, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $statement->execute(['id' => $transactionId, 'hash' => $payloadHash, 'status' => 'received']);

            return true;
        } catch (PDOException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if ($sqlState === '23000' || $sqlState === '23505') {
                return false;
            }

            throw $exception;
        }
    }
}
