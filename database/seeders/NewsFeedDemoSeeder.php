<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use App\Services\CacheService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class NewsFeedDemoSeeder extends Seeder
{
    private const DEMO_HOLIDAY_PREFIX = '[Демо] ';

    private const DEMO_USER_EMAIL_PREFIX = 'feed-demo-';

    /**
     * Тестовые события и дни рождения для ленты новостей (по ~20 на компанию).
     *
     * @return void
     */
    public function run(): void
    {
        $companies = Company::query()->orderBy('id')->get();
        if ($companies->isEmpty()) {
            $this->command?->warn('Нет компаний — сначала выполните CompanySeeder.');

            return;
        }

        $today = Carbon::today();
        $holidayTitles = [
            'День компании',
            'Корпоратив',
            'Новый год (корп.)',
            '8 марта',
            'День труда',
            'День независимости',
            'День знаний',
            'День строителя',
            'День нефтяника',
            'День учителя',
            'День медика',
            'День защитника',
            'День семьи',
            'День благодарности',
            'День инноваций',
            'День качества',
            'День клиента',
            'День команды',
            'День открытых дверей',
            'День наставника',
        ];
        $colors = [
            '#3B82F6', '#5CB85C', '#EE4F47', '#FFA500', '#8B5CF6',
            '#EC4899', '#14B8A6', '#F59E0B', '#6366F1', '#10B981',
        ];

        foreach ($companies as $company) {
            foreach ($holidayTitles as $index => $title) {
                $name = self::DEMO_HOLIDAY_PREFIX.$title;
                $start = $today->copy()->addDays($index + 1);
                $isRange = $index % 4 === 0;
                $end = $isRange ? $start->copy()->addDays(2) : null;

                Holiday::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'name' => $name,
                    ],
                    [
                        'date' => $start->toDateString(),
                        'end_date' => $end?->toDateString(),
                        'is_recurring' => $index % 5 !== 0,
                        'color' => $colors[$index % count($colors)],
                        'icon' => Holiday::ALLOWED_ICONS[$index % count(Holiday::ALLOWED_ICONS)],
                    ]
                );
            }

            $this->seedBirthdaysForCompany($company, $today);
        }

        CacheService::invalidateHolidaysCache();

        $this->command?->info(sprintf(
            'Создано/обновлено: %d событий на компанию, дни рождения для ленты.',
            count($holidayTitles)
        ));
    }

    /**
     * @return void
     */
    private function seedBirthdaysForCompany(Company $company, Carbon $today): void
    {
        $targetCount = 20;
        $activeUsers = User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->orderBy('id')
            ->get();

        $used = 0;
        foreach ($activeUsers as $user) {
            if ($used >= $targetCount) {
                break;
            }
            $occurrence = $today->copy()->addDays($used + 1);
            $user->update([
                'birthday' => $occurrence->copy()->subYears(25 + ($used % 15))->toDateString(),
            ]);
            $used++;
        }

        while ($used < $targetCount) {
            $occurrence = $today->copy()->addDays($used + 1);
            $suffix = $used + 1;
            $email = self::DEMO_USER_EMAIL_PREFIX.$company->id.'-'.$suffix.'@example.test';

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => 'Демо',
                    'surname' => 'Сотрудник '.$suffix,
                    'password' => Hash::make('12345678'),
                    'is_active' => true,
                    'is_admin' => false,
                    'position' => 'Тестовая должность',
                    'birthday' => $occurrence->copy()->subYears(30)->toDateString(),
                ]
            );

            if (! $user->companies()->whereKey($company->id)->exists()) {
                $user->companies()->attach($company->id);
            }

            $used++;
        }
    }
}
