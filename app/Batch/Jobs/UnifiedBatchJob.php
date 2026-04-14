<?php

namespace App\Batch\Jobs;

use App\Batch\BatchService;
use App\Models\User;
use App\Support\ResolvedCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class UnifiedBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $entity,
        public string $action,
        public array $ids,
        public array $payload,
        public int $userId,
        public ?int $resolvedCompanyId,
        public string $correlationId,
    ) {}

    public function handle(BatchService $batchService): void
    {
        $user = User::findOrFail($this->userId);
        request()->attributes->set(ResolvedCompany::ATTRIBUTE, $this->resolvedCompanyId);
        auth('api')->setUser($user);

        $batchService->execute(
            [
                'entity' => $this->entity,
                'action' => $this->action,
                'ids' => $this->ids,
                'payload' => $this->payload,
            ],
            $user,
            true,
            $this->resolvedCompanyId,
            null,
        );
    }
}
