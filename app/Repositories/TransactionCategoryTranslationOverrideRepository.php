<?php

namespace App\Repositories;

use App\Models\CompanyTranslationOverride;
use App\Services\CacheService;
use App\Support\TransactionCategoryTranslationDictionary;
use Illuminate\Support\Collection;

class TransactionCategoryTranslationOverrideRepository extends BaseRepository
{
    /**
     * @return array<int, array{key: string, slug: string, translations: array<string, string>}>
     */
    public function getDictionaryWithOverrides(): array
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('transaction_category_translation_dictionary', [$companyId]);

        return CacheService::getReferenceData($cacheKey, function (): array {
            $keys = TransactionCategoryTranslationDictionary::keys();
            $overrides = $this->getOverridesMap();
            $rows = [];
            foreach ($keys as $key) {
                $rows[] = [
                    'key' => $key,
                    'slug' => mb_substr($key, mb_strlen(TransactionCategoryTranslationDictionary::DOMAIN) + 1),
                    'translations' => $overrides[$key] ?? [],
                ];
            }

            return $rows;
        });
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getOverridesMap(): array
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId === null) {
            return [];
        }

        $cacheKey = $this->generateCacheKey('transaction_category_translation_overrides', [$companyId]);

        return CacheService::getReferenceData($cacheKey, function () use ($companyId): array {
            /** @var Collection<int, CompanyTranslationOverride> $rows */
            $rows = CompanyTranslationOverride::query()
                ->where('company_id', $companyId)
                ->where('domain', TransactionCategoryTranslationDictionary::DOMAIN)
                ->orderBy('translation_key')
                ->orderBy('locale')
                ->get();

            $map = [];
            foreach ($rows as $row) {
                if (!isset($map[$row->translation_key])) {
                    $map[$row->translation_key] = [];
                }
                $map[$row->translation_key][$row->locale] = $row->value;
            }

            return $map;
        });
    }

    /**
     * @param array<int, array{key: string, locale: string, value: string}> $items
     * @return void
     */
    public function upsertMany(array $items): void
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId === null) {
            return;
        }

        foreach ($items as $item) {
            CompanyTranslationOverride::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'domain' => TransactionCategoryTranslationDictionary::DOMAIN,
                    'translation_key' => $item['key'],
                    'locale' => $item['locale'],
                ],
                [
                    'value' => $item['value'],
                ]
            );
        }

        CacheService::invalidateTransactionCategoryTranslationsCache();
    }
}
