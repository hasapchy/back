<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Services\CacheService;
use App\Services\LeadConversionService;
use App\Services\Timeline\TimelineCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LeadsRepository extends BaseRepository
{
    /**
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination(int $userUuid, int $perPage = 20, int $page = 1, ?int $statusId = null)
    {
        $currentUser = auth('api')->user();
        $cacheKey = $this->generateCacheKey('leads_paginated', [
            $userUuid,
            $perPage,
            $statusId,
            $currentUser?->id,
        ]);

        return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $statusId) {
            $companyId = $this->getCurrentCompanyId();
            $query = Lead::query()
                ->with(['client', 'status', 'source', 'order', 'responsible'])
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when(! $companyId, fn ($q) => $q->whereNull('company_id'))
                ->when($statusId !== null, fn ($q) => $q->where('status_id', $statusId));

            if ($this->shouldApplyUserFilter('leads')) {
                $filterUserId = $this->getFilterUserIdForPermission('leads', $userUuid);
                $query->where('creator_id', $filterUserId);
            }

            return $query->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        }, (int) $page);
    }

    /**
     * @return Lead
     */
    public function getItemById(int $id): Lead
    {
        $cacheKey = $this->generateCacheKey('leads_item', [$id]);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            return Lead::query()->with(['client', 'status', 'source', 'order', 'responsible'])->findOrFail($id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(array $data, \App\Models\User $user): Lead
    {
        $companyId = (int) ($data['company_id'] ?? $this->getCurrentCompanyId());
        if (! isset($data['lead_source_id']) || $data['lead_source_id'] === null) {
            $data['lead_source_id'] = LeadSource::query()
                ->where('company_id', $companyId)
                ->where('name', 'Звонок')
                ->orderBy('id')
                ->value('id');
        }
        if (! $data['lead_source_id']) {
            $data['lead_source_id'] = LeadSource::query()
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->value('id');
        }

        if (empty($data['status_id'])) {
            $data['status_id'] = LeadStatus::query()
                ->where('company_id', $companyId)
                ->where('name', 'Новый')
                ->orderBy('sort')
                ->value('id');
        }

        $creatorId = (int) $data['creator_id'];
        $responsibleId = $creatorId;
        if (array_key_exists('responsible_id', $data) && $data['responsible_id'] !== null && $data['responsible_id'] !== '') {
            $responsibleId = (int) $data['responsible_id'];
        }

        $title = (isset($data['title']) && $data['title'] !== null && $data['title'] !== '') ? trim((string) $data['title']) : null;
        if ($title === '') {
            $title = null;
        }

        $lead = Lead::query()->create([
            'company_id' => $companyId,
            'creator_id' => $creatorId,
            'responsible_id' => $responsibleId,
            'client_id' => (int) $data['client_id'],
            'title' => $title,
            'lead_source_id' => $data['lead_source_id'] ? (int) $data['lead_source_id'] : null,
            'status_id' => (int) $data['status_id'],
            'comment' => $data['comment'] ?? null,
            'files' => $this->normalizeLeadFiles($data['files'] ?? null),
            'order_id' => null,
        ]);

        if ($title === null) {
            $lead->update(['title' => 'Лид #'.$lead->id]);
        }

        $lead = $lead->fresh(['client', 'status', 'source', 'order', 'responsible']);
        TimelineCache::forget('lead', (int) $lead->id, $companyId);
        CacheService::invalidateLeadsCache();

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(int $id, array $data, \App\Models\User $user): Lead
    {
        $lead = Lead::query()->findOrFail($id);
        $previousStatusId = (int) $lead->status_id;
        if (array_key_exists('title', $data)) {
            $lead->title = ($data['title'] !== null && $data['title'] !== '') ? (string) $data['title'] : null;
        }
        if (array_key_exists('responsible_id', $data)) {
            $lead->responsible_id = $data['responsible_id'] !== null ? (int) $data['responsible_id'] : null;
        }
        if (array_key_exists('client_id', $data)) {
            $lead->client_id = (int) $data['client_id'];
        }
        if (array_key_exists('lead_source_id', $data)) {
            $lead->lead_source_id = $data['lead_source_id'] !== null ? (int) $data['lead_source_id'] : null;
        }
        if (array_key_exists('status_id', $data)) {
            $lead->status_id = (int) $data['status_id'];
        }
        if (array_key_exists('comment', $data)) {
            $lead->comment = $data['comment'];
        }
        if (array_key_exists('files', $data)) {
            $lead->files = $this->normalizeLeadFiles($data['files']);
        }
        $lead->save();

        $lead->refresh();
        $lead->load('status');
        try {
            app(LeadConversionService::class)->syncOrderForSuccessStatus($lead, $user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $lead->update(['status_id' => $previousStatusId]);
            throw $e;
        }

        $lead = $lead->fresh(['client', 'status', 'source', 'order', 'responsible']);
        TimelineCache::forget('lead', (int) $lead->id, (int) $lead->company_id);
        CacheService::invalidateLeadsCache();

        return $lead;
    }

    /**
     * @return bool
     */
    public function deleteItem(int $id): bool
    {
        $lead = Lead::query()->findOrFail($id);
        $companyId = (int) $lead->company_id;
        $leadId = (int) $lead->id;
        $lead->delete();
        TimelineCache::forget('lead', $leadId, $companyId);
        CacheService::invalidateLeadsCache();

        return true;
    }

    /**
     * @param  list<string>  $relativePaths
     */
    public function appendStoredFilePaths(int $leadId, array $relativePaths): Lead
    {
        $lead = Lead::query()->findOrFail($leadId);
        $existing = is_array($lead->files) ? $lead->files : [];
        $lead->files = array_values(array_merge($existing, $relativePaths));
        $lead->save();
        $lead = $lead->fresh(['client', 'status', 'source', 'order', 'responsible']);
        TimelineCache::forget('lead', (int) $lead->id, (int) $lead->company_id);
        CacheService::invalidateLeadsCache();

        return $lead;
    }

    /**
     * @param  mixed|null  $files
     * @return array<int, string>|null
     */
    private function normalizeLeadFiles(mixed $files): ?array
    {
        if ($files === null || ! is_array($files)) {
            return null;
        }

        $out = [];
        foreach ($files as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[] = is_string($value) ? $value : (string) $value;
        }

        return $out;
    }

    /**
     * @return void
     */
    public function assertClientBelongsToCompany(int $clientId, int $companyId): void
    {
        $exists = Client::query()
            ->where('id', $clientId)
            ->where('company_id', $companyId)
            ->exists();
        if (! $exists) {
            abort(422, 'Клиент не принадлежит текущей компании.');
        }
    }
}
