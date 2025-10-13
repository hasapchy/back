<?php

namespace App\Console\Commands;

use App\Models\CashRegister;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManageCashBalances extends Command
{
    protected $signature = 'cash:balance
                            {action=check : Действие: check, fix, analyze}
                            {cash_id? : ID конкретной кассы (опционально)}
                            {--detailed : Детальная информация (для check)}
                            {--dry-run : Только показать изменения без применения (для fix)}';

    protected $description = 'Управление балансами касс: проверка, анализ и исправление';

    public function handle()
    {
        $action = $this->argument('action');
        $cashId = $this->argument('cash_id');

        switch ($action) {
            case 'check':
                return $this->checkBalances($cashId);
            case 'fix':
                return $this->fixBalances($cashId);
            case 'analyze':
                return $this->analyzeBalances($cashId);
            default:
                $this->error("❌ Неизвестное действие: {$action}");
                $this->info("Доступные действия: check, fix, analyze");
                return Command::FAILURE;
        }
    }

    // ================ ПРОВЕРКА БАЛАНСОВ ================
    private function checkBalances($cashId)
    {
        $detailed = $this->option('detailed');

        $this->info('🔍 Проверка балансов касс...');
        $this->newLine();

        $cashRegisters = $this->getCashRegisters($cashId);
        if (!$cashRegisters) return Command::FAILURE;

        $hasDiscrepancies = false;
        $totalDiscrepancy = 0;
        $totalIncome = 0;
        $totalOutcome = 0;

        /** @var CashRegister $cashRegister */
        foreach ($cashRegisters as $cashRegister) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("📦 Касса: {$cashRegister->name} (ID: {$cashRegister->id})");
            $this->info("   Валюта: {$cashRegister->currency->code} ({$cashRegister->currency->symbol})");
            $this->newLine();

            $stats = $this->calculateStats($cashRegister);

            // Основные транзакции
            $this->line("   📊 ОСНОВНЫЕ ТРАНЗАКЦИИ (учитываются в балансе кассы):");
            $this->line("      ┌─ Приход:              " . $this->money($stats['income']) . " ({$stats['incomeCount']} транзакций)");
            $this->line("      └─ Расход:              " . $this->money($stats['outcome']) . " ({$stats['outcomeCount']} транзакций)");
            $this->newLine();

            // Долговые операции
            if ($stats['debtCount'] > 0) {
                $this->line("   💳 ДОЛГОВЫЕ ОПЕРАЦИИ (НЕ учитываются в балансе кассы):");
                $this->line("      ┌─ Приход (долг):       " . $this->money($stats['debtIncome']) . " ({$stats['debtIncomeCount']} транзакций)");
                $this->line("      └─ Расход (долг):       " . $this->money($stats['debtOutcome']) . " ({$stats['debtOutcomeCount']} транзакций)");
                $this->newLine();
            }

            // Баланс
            $this->line("   💰 БАЛАНС:");
            $this->line("      ┌─ Рассчитанный:        " . $this->money($stats['calculatedBalance']));
            $this->line("      └─ Текущий в БД:        " . $this->money($stats['currentBalance']));
            $this->newLine();

            if (abs($stats['difference']) < 0.01) {
                $this->info("   ✅ Статус: Баланс правильный!");
            } else {
                $this->error("   ❌ РАСХОЖДЕНИЕ: " . $this->money($stats['difference']));
                $hasDiscrepancies = true;
                $totalDiscrepancy += abs($stats['difference']);
            }

            // Детальная статистика
            if ($detailed) {
                $this->newLine();
                $this->line("   📋 Детальная статистика:");
                $this->line("      Всего транзакций:      " . $stats['totalCount']);
                $this->line("      ├─ Обычных:            " . $stats['nonDebtCount']);
                $this->line("      └─ Долговых:           " . $stats['debtCount']);

                if ($stats['incomeCount'] > 0) {
                    $this->line("      Средний приход:        " . $this->money($stats['income'] / $stats['incomeCount']));
                }
                if ($stats['outcomeCount'] > 0) {
                    $this->line("      Средний расход:        " . $this->money($stats['outcome'] / $stats['outcomeCount']));
                }
            }

            $totalIncome += $stats['income'];
            $totalOutcome += $stats['outcome'];

            $this->newLine();
        }

        // Итоговая статистика
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('📈 ОБЩАЯ СТАТИСТИКА:');
        $this->newLine();
        $this->line("   Всего касс:            " . $cashRegisters->count());
        $this->line("   Общий приход:          " . $this->money($totalIncome));
        $this->line("   Общий расход:          " . $this->money($totalOutcome));
        $this->line("   Общий баланс:          " . $this->money($totalIncome - $totalOutcome));
        $this->newLine();

        if (!$hasDiscrepancies) {
            $this->info('✅ Все балансы касс верны!');
        } else {
            $this->error('❌ Обнаружены расхождения!');
            $this->error('   Сумма расхождений: ' . $this->money($totalDiscrepancy));
            $this->newLine();
            $this->comment('💡 Используйте команды для дальнейших действий:');
            $this->comment('   php artisan cash:balance analyze     - анализ причин');
            $this->comment('   php artisan cash:balance fix         - исправление балансов');
        }

        $this->info('═══════════════════════════════════════════════════════════');

        return Command::SUCCESS;
    }

    // ================ ИСПРАВЛЕНИЕ БАЛАНСОВ ================
    private function fixBalances($cashId)
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 РЕЖИМ ПРОВЕРКИ: изменения НЕ будут применены');
        } else {
            $this->error('⚠️  ВНИМАНИЕ: Баланс кассы будет ИЗМЕНЕН в БД!');
            if (!$this->confirm('Продолжить?')) {
                $this->info('Отменено');
                return Command::SUCCESS;
            }
        }

        $this->newLine();

        $cashRegisters = $this->getCashRegisters($cashId);
        if (!$cashRegisters) return Command::FAILURE;

        $fixedCount = 0;

        /** @var CashRegister $cashRegister */
        foreach ($cashRegisters as $cashRegister) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("📦 Касса: {$cashRegister->name} (ID: {$cashRegister->id})");
            $this->newLine();

            $stats = $this->calculateStats($cashRegister);

            $this->line("   📊 Транзакции (НЕ долговые):");
            $this->line("      Приход:                " . $this->money($stats['income']) . " ({$stats['incomeCount']} шт)");
            $this->line("      Расход:                " . $this->money($stats['outcome']) . " ({$stats['outcomeCount']} шт)");
            $this->newLine();

            $this->line("   💰 Баланс:");
            $this->line("      Текущий в БД:          " . $this->money($stats['currentBalance']));
            $this->line("      Правильный:            " . $this->money($stats['calculatedBalance']));
            $this->line("      Расхождение:           " . $this->money($stats['difference']));
            $this->newLine();

            if (abs($stats['difference']) < 0.01) {
                $this->info("   ✅ Баланс правильный, исправление не требуется");
            } else {
                if ($dryRun) {
                    $this->warn("   🔍 БУДЕТ ИЗМЕНЕНО: {$stats['currentBalance']} → {$stats['calculatedBalance']}");
                } else {
                    DB::beginTransaction();
                    try {
                        $cashRegister->balance = $stats['calculatedBalance'];
                        $cashRegister->save();
                        DB::commit();

                        $this->info("   ✅ ИСПРАВЛЕНО:");
                        $this->info("      Было:      " . $this->money($stats['currentBalance']));
                        $this->info("      Стало:     " . $this->money($stats['calculatedBalance']));
                        $fixedCount++;
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->error("   ❌ Ошибка: {$e->getMessage()}");
                    }
                }
            }

            $this->newLine();
        }

        if (!$dryRun && $fixedCount > 0) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("✅ Исправлено касс: {$fixedCount}");
        }

        return Command::SUCCESS;
    }

    // ================ АНАЛИЗ РАСХОЖДЕНИЙ ================
    private function analyzeBalances($cashId)
    {
        $this->info('🔍 Анализ расхождений в балансах...');
        $this->newLine();

        $cashRegisters = $this->getCashRegisters($cashId);
        if (!$cashRegisters) return Command::FAILURE;

        /** @var CashRegister $cashRegister */
        foreach ($cashRegisters as $cashRegister) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("📦 Касса: {$cashRegister->name} (ID: {$cashRegister->id})");
            $this->newLine();

            $stats = $this->calculateStats($cashRegister);

            $this->line("   💰 Состояние:");
            $this->line("      Рассчитанный:          " . $this->money($stats['calculatedBalance']));
            $this->line("      В БД:                  " . $this->money($stats['currentBalance']));
            $this->line("      Расхождение:           " . $this->money($stats['difference']));
            $this->newLine();

            if (abs($stats['difference']) < 0.01) {
                $this->info('   ✅ Баланс правильный, анализ не требуется');
                $this->newLine();
                continue;
            }

            $this->warn("   🔎 АНАЛИЗ ПРИЧИН:");
            $this->newLine();

            // Долговые транзакции
            if ($stats['debtCount'] > 0) {
                $this->line("   1️⃣ Долговые транзакции:");
                $this->line("      Найдено:               {$stats['debtCount']} шт");
                $this->line("      Приход (долг):         " . $this->money($stats['debtIncome']));
                $this->line("      Расход (долг):         " . $this->money($stats['debtOutcome']));
                $this->line("      Влияние:               " . $this->money($stats['debtIncome'] - $stats['debtOutcome']));
                $this->newLine();

                // Проверка: если долговой расход примерно равен расхождению
                if (abs($stats['debtOutcome'] - abs($stats['difference'])) < 100) {
                    $this->error("      ⚠️  ВОЗМОЖНАЯ ПРИЧИНА:");
                    $this->error("          Долговые расходы могли быть учтены в балансе!");
                    $this->newLine();
                }

                // Показать последние долговые транзакции
                $debtTransactions = Transaction::where('cash_id', $cashRegister->id)
                    ->where('is_debt', true)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();

                if ($debtTransactions->count() > 0) {
                    $this->line("      📋 Последние 5 долговых транзакций:");
                    foreach ($debtTransactions as $tr) {
                        $type = $tr->type == 1 ? '📥' : '📤';
                        $date = \Carbon\Carbon::parse($tr->date)->format('d.m.Y');
                        $this->line("         {$type} " . $this->money($tr->amount) . " | {$date} | ID: {$tr->id}");
                    }
                    $this->newLine();
                }
            }

            // Теоретический расчет
            if ($stats['debtCount'] > 0) {
                $allIncome = $stats['income'] + $stats['debtIncome'];
                $allOutcome = $stats['outcome'] + $stats['debtOutcome'];
                $balanceWithDebt = $allIncome - $allOutcome;

                $this->line("   2️⃣ Если бы долги УЧИТЫВАЛИСЬ:");
                $this->line("      Приход (все):          " . $this->money($allIncome));
                $this->line("      Расход (все):          " . $this->money($allOutcome));
                $this->line("      Баланс:                " . $this->money($balanceWithDebt));
                $this->line("      Расхождение с БД:      " . $this->money($balanceWithDebt - $stats['currentBalance']));

                if (abs($balanceWithDebt - $stats['currentBalance']) < abs($stats['difference'])) {
                    $this->error("      ⚠️  ВЕРОЯТНАЯ ПРИЧИНА: ранее долги учитывались в кассе!");
                }
                $this->newLine();
            }

            // Рекомендации
            $this->line("   💡 РЕКОМЕНДАЦИИ:");
            if ($stats['difference'] < 0) {
                $this->line("      • Баланс в БД БОЛЬШЕ на " . $this->money(abs($stats['difference'])));
                $this->line("      • Ранее долговые операции учитывались в балансе");
            } else {
                $this->line("      • Баланс в БД МЕНЬШЕ на " . $this->money($stats['difference']));
            }
            $this->line("      • Используйте: php artisan cash:balance fix {$cashRegister->id}");
            $this->newLine();

            // SQL для ручного исправления
            $this->comment("   🔧 SQL для ручного исправления:");
            $this->comment("      UPDATE cash_registers SET balance = {$stats['calculatedBalance']} WHERE id = {$cashRegister->id};");
            $this->newLine();
        }

        return Command::SUCCESS;
    }

    // ================ ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ================

    private function getCashRegisters($cashId)
    {
        if ($cashId) {
            $cashRegister = CashRegister::with('currency')->find($cashId);
            if (!$cashRegister) {
                $this->error('❌ Касса не найдена');
                return null;
            }
            return collect([$cashRegister]);
        }

        $cashRegisters = CashRegister::with('currency')->get();
        if ($cashRegisters->isEmpty()) {
            $this->warn('⚠️  Кассы не найдены');
            return null;
        }

        return $cashRegisters;
    }

    private function calculateStats(CashRegister $cashRegister)
    {
        $allTransactions = Transaction::where('cash_id', $cashRegister->id)->get();
        $nonDebtTransactions = $allTransactions->where('is_debt', false);
        $debtTransactions = $allTransactions->where('is_debt', true);

        return [
            'income' => $nonDebtTransactions->where('type', 1)->sum('amount'),
            'outcome' => $nonDebtTransactions->where('type', 0)->sum('amount'),
            'incomeCount' => $nonDebtTransactions->where('type', 1)->count(),
            'outcomeCount' => $nonDebtTransactions->where('type', 0)->count(),
            'debtIncome' => $debtTransactions->where('type', 1)->sum('amount'),
            'debtOutcome' => $debtTransactions->where('type', 0)->sum('amount'),
            'debtIncomeCount' => $debtTransactions->where('type', 1)->count(),
            'debtOutcomeCount' => $debtTransactions->where('type', 0)->count(),
            'totalCount' => $allTransactions->count(),
            'nonDebtCount' => $nonDebtTransactions->count(),
            'debtCount' => $debtTransactions->count(),
            'calculatedBalance' => $nonDebtTransactions->where('type', 1)->sum('amount') - $nonDebtTransactions->where('type', 0)->sum('amount'),
            'currentBalance' => $cashRegister->balance,
            'difference' => ($nonDebtTransactions->where('type', 1)->sum('amount') - $nonDebtTransactions->where('type', 0)->sum('amount')) - $cashRegister->balance,
        ];
    }

    private function money($amount)
    {
        return number_format($amount, 2, '.', ' ');
    }
}

