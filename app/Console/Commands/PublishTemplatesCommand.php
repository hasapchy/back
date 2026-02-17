<?php

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use App\Models\News;
use App\Services\CacheService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PublishTemplatesCommand extends Command
{
    protected $signature = 'templates:publish-all';

    protected $description = 'Автоматически публикует все шаблоны сообщений по расписанию';

    public function handle(): int
    {
        $today = Carbon::today();
        $typeConfigs = config('template_types', []);

        $publishedTotal = 0;

        $templateTypes = MessageTemplate::query()
            ->distinct()
            ->pluck('type')
            ->toArray();

        foreach ($templateTypes as $type) {
            if (! isset($typeConfigs[$type])) {
                continue;
            }

            try {
                $published = $this->publishType(
                    $type,
                    $typeConfigs[$type],
                    $today
                );

                $publishedTotal += $published;

                if ($published > 0) {
                    $this->info("[$type] опубликовано: {$published}");
                }
            } catch (\Throwable $e) {
                $this->error("[$type] ошибка: {$e->getMessage()}");
                Log::error('Template publish type failed', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("Всего опубликовано новостей: {$publishedTotal}");

        // Инвалидируем кэш новостей для всех компаний после публикации всех шаблонов
        if ($publishedTotal > 0) {
            // Инвалидируем кэш без фильтра по компании, чтобы очистить для всех
            CacheService::invalidateByLike('%news%');
            // Также инвалидируем пагинированные данные
            CacheService::invalidatePaginatedData('news_paginated');
            CacheService::invalidatePaginatedData('news_all');
            $this->info('Кэш новостей очищен');
        }

        return Command::SUCCESS;
    }

    /**
     * Опубликовать новости для шаблонов указанного типа
     *
     * @param  string  $type  Тип шаблона (birthday, holiday, и т.д.)
     * @param  array  $config  Конфигурация типа из template_types.php
     * @param  Carbon  $today  Сегодняшняя дата
     * @return int Количество опубликованных новостей
     */
    private function publishType(string $type, array $config, Carbon $today): int
    {
        $modelClass = $config['model'];
        $dateField = $config['date_field'];
        $variables = $config['variables'] ?? [];

        $items = $this->getItemsForToday($modelClass, $dateField, $today);

        if ($items->isEmpty()) {
            return 0;
        }

        $count = 0;

        // Для User модели нужно получать компании через связь
        $isUserModel = $modelClass === \App\Models\User::class;

        if ($isUserModel) {
            // Загружаем компании для всех пользователей
            $items->load('companies');
        }

        // Собираем все уникальные company_id
        $companies = collect();
        foreach ($items as $item) {
            if ($isUserModel) {
                // Для пользователей берем компании из связи
                foreach ($item->companies as $company) {
                    $companies->push($company->id);
                }
            } else {
                // Для других моделей (CompanyHoliday) берем напрямую
                if ($item->company_id) {
                    $companies->push($item->company_id);
                }
            }
        }

        $companies = $companies->filter()->unique();

        foreach ($companies as $companyId) {
            $template = MessageTemplate::query()
                ->where('type', $type)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->first();

            if (! $template) {
                continue;
            }

            // Фильтруем элементы для текущей компании
            $companyItems = $items->filter(function ($item) use ($companyId, $isUserModel) {
                if ($isUserModel) {
                    return $item->companies->contains('id', $companyId);
                }

                return $item->company_id === $companyId;
            });

            foreach ($companyItems as $item) {
                try {
                    if ($this->alreadyPosted($type, $item, $companyId, $today)) {
                        continue;
                    }

                    $content = $template->render(
                        $this->buildVariables($item, $variables)
                    );

                    News::create([
                        'title' => $template->name,
                        'content' => $content,
                        'company_id' => $companyId,
                        'creator_id' => $template->creator_id,
                        'meta' => [
                            'template_type' => $type,
                            'source_id' => $item->id ?? null,
                        ],
                    ]);

                    $count++;
                } catch (\Throwable $e) {
                    Log::error('Template publish item failed', [
                        'type' => $type,
                        'item_id' => $item->id ?? null,
                        'company_id' => $companyId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Получить элементы (пользователей/праздники), у которых сегодня особая дата
     *
     * @param  string  $modelClass  Класс модели (User, CompanyHoliday, и т.д.)
     * @param  string  $dateField  Название поля с датой
     * @param  Carbon  $today  Сегодняшняя дата
     */
    private function getItemsForToday(
        string $modelClass,
        string $dateField,
        Carbon $today
    ): Collection {
        $query = $modelClass::query()->whereNotNull($dateField);

        if ($dateField === 'birthday') {
            return $query
                ->whereMonth($dateField, $today->month)
                ->whereDay($dateField, $today->day)
                ->get();
        }

        return $query
            ->whereDate($dateField, $today)
            ->get();
    }

    /**
     * Построить массив переменных для подстановки в шаблон
     *
     * @param  \App\Models\User|\App\Models\CompanyHoliday  $item  Элемент (пользователь или праздник)
     * @param  array<string>  $variables  Массив имен переменных
     * @return array<string, string>
     */
    private function buildVariables($item, array $variables): array
    {
        $result = [];

        foreach ($variables as $var) {
            if ($var === 'fullName') {
                $result['fullName'] = trim(
                    ($item->name ?? '').' '.($item->surname ?? '')
                );

                continue;
            }

            $value = $item->$var ?? '';

            $result[$var] = is_scalar($value)
                ? (string) $value
                : '';
        }

        return $result;
    }

    /**
     * Проверить, была ли уже опубликована новость для этого элемента сегодня
     *
     * @param  string  $type  Тип шаблона
     * @param  \App\Models\User|\App\Models\CompanyHoliday  $item  Элемент (пользователь или праздник)
     * @param  int  $companyId  ID компании
     * @param  Carbon  $today  Сегодняшняя дата
     */
    private function alreadyPosted(
        string $type,
        $item,
        int $companyId,
        Carbon $today
    ): bool {
        return News::query()
            ->where('company_id', $companyId)
            ->whereDate('created_at', $today)
            ->where('meta->template_type', $type)
            ->where('meta->source_id', $item->id ?? null)
            ->exists();
    }
}
