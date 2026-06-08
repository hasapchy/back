<?php

namespace App\Services;

use App\Enums\ListFilterPresetSource;
use App\Models\User;
use App\Models\UserFilterPreset;
use App\Support\ListFilterPresetAppearance;
use App\Support\ListFilterPresetFields;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserFilterPresetsService
{
    /**
     * @return array{items: array<int, array<string, mixed>>, defaultPresetId: int|null, schema: array{keys: array<int, string>, defaults: array<string, mixed>, ignoredKeysInKanban: array<int, string>}}
     */
    public function listForUser(User $user, int $companyId, ListFilterPresetSource $source): array
    {
        $items = UserFilterPreset::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('source', $source->value)
            ->orderBy('name')
            ->get();

        return [
            'items' => $items->map(fn (UserFilterPreset $preset) => $this->toItem($preset))->all(),
            'defaultPresetId' => $this->resolveDefaultPresetId($user, $companyId, $source),
            'schema' => ListFilterPresetFields::schemaFor($source),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function create(
        User $user,
        int $companyId,
        ListFilterPresetSource $source,
        string $name,
        array $filters,
        string $icon,
        string $color,
    ): UserFilterPreset {
        $mergedFilters = ListFilterPresetFields::mergeWithDefaults($source, $filters);

        return UserFilterPreset::query()->create([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'source' => $source->value,
            'name' => $name,
            'icon' => ListFilterPresetAppearance::normalizeIcon($icon),
            'color' => ListFilterPresetAppearance::normalizeColor($color),
            'filters' => $mergedFilters,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function updateFilters(User $user, int $companyId, int $presetId, array $filters): UserFilterPreset
    {
        $preset = $this->findOwnedOrFail($user, $companyId, $presetId);
        $source = ListFilterPresetSource::from($preset->source);
        $mergedFilters = ListFilterPresetFields::mergeWithDefaults($source, $filters);

        $preset->update(['filters' => $mergedFilters]);

        return $preset->fresh();
    }

    public function rename(User $user, int $companyId, int $presetId, string $name): UserFilterPreset
    {
        $preset = $this->findOwnedOrFail($user, $companyId, $presetId);
        $preset->update(['name' => $name]);

        return $preset->fresh();
    }

    /**
     * @param  array{name?: string, icon?: string|null, color?: string|null, filters?: array<string, mixed>}  $payload
     */
    public function updatePreset(User $user, int $companyId, int $presetId, array $payload): UserFilterPreset
    {
        $preset = $this->findOwnedOrFail($user, $companyId, $presetId);
        $source = ListFilterPresetSource::from($preset->source);
        $updates = [];

        if (array_key_exists('name', $payload)) {
            $updates['name'] = $payload['name'];
        }
        if (array_key_exists('icon', $payload)) {
            $updates['icon'] = ListFilterPresetAppearance::normalizeIcon($payload['icon']);
        }
        if (array_key_exists('color', $payload)) {
            $updates['color'] = ListFilterPresetAppearance::normalizeColor($payload['color']);
        }
        if (array_key_exists('filters', $payload)) {
            $updates['filters'] = ListFilterPresetFields::mergeWithDefaults($source, $payload['filters']);
        }

        if ($updates !== []) {
            $preset->update($updates);
        }

        return $preset->fresh();
    }

    public function delete(User $user, int $companyId, int $presetId): void
    {
        $this->findOwnedOrFail($user, $companyId, $presetId)->delete();
    }

    public function setDefault(User $user, int $companyId, ListFilterPresetSource $source, ?int $presetId): void
    {
        if ($presetId !== null) {
            $preset = $this->findOwnedOrFail($user, $companyId, $presetId);
            if ($preset->source !== $source->value) {
                throw new InvalidArgumentException('Preset source mismatch');
            }
        }

        DB::transaction(function () use ($user, $companyId, $source, $presetId): void {
            $this->clearDefaultFlags($user->id, $companyId, $source->value);

            if ($presetId !== null) {
                UserFilterPreset::query()
                    ->where('id', $presetId)
                    ->where('user_id', $user->id)
                    ->where('company_id', $companyId)
                    ->where('source', $source->value)
                    ->update(['is_default' => true]);
            }
        });
    }

    public function nameExists(User $user, int $companyId, ListFilterPresetSource $source, string $name, ?int $excludeId = null): bool
    {
        $query = UserFilterPreset::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('source', $source->value)
            ->where('name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * @return UserFilterPreset
     */
    public function findOwnedOrFail(User $user, int $companyId, int $presetId): UserFilterPreset
    {
        $preset = UserFilterPreset::query()
            ->where('id', $presetId)
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->first();

        if ($preset === null) {
            throw new InvalidArgumentException('Preset not found');
        }

        return $preset;
    }

    public function resolveDefaultPresetId(User $user, int $companyId, ListFilterPresetSource $source): ?int
    {
        $presetId = UserFilterPreset::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('source', $source->value)
            ->where('is_default', true)
            ->value('id');

        return $presetId !== null ? (int) $presetId : null;
    }

    private function clearDefaultFlags(int $userId, int $companyId, string $source): void
    {
        UserFilterPreset::query()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('source', $source)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toItem(UserFilterPreset $preset): array
    {
        return [
            'id' => $preset->id,
            'source' => $preset->source,
            'name' => $preset->name,
            'icon' => $preset->icon,
            'color' => $preset->color,
            'filters' => $preset->filters,
        ];
    }
}
