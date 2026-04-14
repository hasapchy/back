<?php

namespace App\Batch;

final class BatchResult
{
    public function __construct(
        public readonly int $successCount,
        public readonly array $failedIds,
        public readonly array $errors,
        public readonly ?string $asyncJobId = null,
        public readonly ?string $asyncConnection = null,
        public readonly ?string $strategyUsed = null,
        public readonly ?string $correlationId = null,
    ) {}

    public static function asyncQueued(
        ?string $jobId,
        ?string $connection = null,
        ?string $strategy = 'queue',
        ?string $correlationId = null,
    ): self {
        return new self(
            successCount: 0,
            failedIds: [],
            errors: [],
            asyncJobId: $jobId,
            asyncConnection: $connection,
            strategyUsed: $strategy,
            correlationId: $correlationId,
        );
    }

    public function toArray(): array
    {
        return [
            'success_count' => $this->successCount,
            'failed_ids' => array_values($this->failedIds),
            'errors' => $this->errors,
            'async_job_id' => $this->asyncJobId,
            'async_connection' => $this->asyncConnection,
            'correlation_id' => $this->correlationId,
            'strategy_used' => $this->strategyUsed,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            successCount: (int) ($data['success_count'] ?? 0),
            failedIds: array_map('intval', $data['failed_ids'] ?? []),
            errors: $data['errors'] ?? [],
            asyncJobId: isset($data['async_job_id']) ? (string) $data['async_job_id'] : null,
            asyncConnection: isset($data['async_connection']) ? (string) $data['async_connection'] : null,
            strategyUsed: isset($data['strategy_used']) ? (string) $data['strategy_used'] : null,
            correlationId: isset($data['correlation_id']) ? (string) $data['correlation_id'] : null,
        );
    }
}
