<?php

namespace App\Console\Commands;

use App\Models\CashRegister;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckCashBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cash:check-balances {--detailed : Показать детальную информацию по транзакциям}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверка балансов касс: пересчитывает приход, расход и итоговый баланс без изменения данных';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $detailed = $this->option('detailed');

        $this->info('🔍 Начинаем проверку балансов касс...');
        $this->newLine();

        $cashRegisters = CashRegister::with('currency')->get();

        if ($cashRegisters->isEmpty()) {
            $this->warn('⚠️  Кассы не найдены');
            return Command::SUCCESS;
        }

        $hasDiscrepancies = false;
        $totalDiscrepancy = 0;
        $totalIncome = 0;
        $totalOutcome = 0;

        foreach ($cashRegisters as $cashRegister) {
            $this->info("═══════════════════════════════════════════════════════════");
            $this->info("📦 Касса: {$cashRegister->name} (ID: {$cashRegister->id})");
            $this->info("   Валюта: {$cashRegister->currency->code} ({$cashRegister->currency->symbol})");
            $this->newLine();

            // Получаем все транзакции кассы
            $allTransactions = Transaction::where('cash_id', $cashRegister->id)->get();

            // Транзакции без долга (учитываются в балансе)
            $nonDebtTransactions = $allTransactions->where('is_debt', false);

            // Транзакции с долгом (НЕ учитываются в балансе кассы)
            $debtTransactions = $allTransactions->where('is_debt', true);

            // === Основные транзакции (не долговые) ===
            $incomeCount = $nonDebtTransactions->where('type', 1)->count();
            $income = $nonDebtTransactions->where('type', 1)->sum('amount');

            $outcomeCount = $nonDebtTransactions->where('type', 0)->count();
            $outcome = $nonDebtTransactions->where('type', 0)->sum('amount');

            // === Долговые транзакции (для информации) ===
            $debtIncomeCount = $debtTransactions->where('type', 1)->count();
            $debtIncome = $debtTransactions->where('type', 1)->sum('amount');

            $debtOutcomeCount = $debtTransactions->where('type', 0)->count();
            $debtOutcome = $debtTransactions->where('type', 0)->sum('amount');

            // Рассчитываем итоговый баланс (только не долговые)
            $calculatedBalance = $income - $outcome;

            // Текущий баланс в БД
            $currentBalance = $cashRegister->balance;

            // Разница
            $difference = $calculatedBalance - $currentBalance;

            // === Основная статистика ===
            $this->line("   📊 ОСНОВНЫЕ ТРАНЗАКЦИИ (учитываются в балансе кассы):");
            $this->line("      ┌─ Приход:              " . $this->formatMoney($income) . " ({$incomeCount} транзакций)");
            $this->line("      └─ Расход:              " . $this->formatMoney($outcome) . " ({$outcomeCount} транзакций)");
            $this->newLine();

            if ($debtTransactions->count() > 0) {
                $this->line("   💳 ДОЛГОВЫЕ ОПЕРАЦИИ (НЕ учитываются в балансе кассы):");
                $this->line("      ┌─ Приход (долг):       " . $this->formatMoney($debtIncome) . " ({$debtIncomeCount} транзакций)");
                $this->line("      └─ Расход (долг):       " . $this->formatMoney($debtOutcome) . " ({$debtOutcomeCount} транзакций)");
                $this->newLine();
            }

            $this->line("   💰 БАЛАНС:");
            $this->line("      ┌─ Рассчитанный:        " . $this->formatMoney($calculatedBalance));
            $this->line("      └─ Текущий в БД:        " . $this->formatMoney($currentBalance));
            $this->newLine();

            if (abs($difference) < 0.01) {
                $this->info("   ✅ Статус: Баланс правильный!");
            } else {
                $this->error("   ❌ РАСХОЖДЕНИЕ: " . $this->formatMoney($difference));
                $hasDiscrepancies = true;
                $totalDiscrepancy += abs($difference);
            }

            // Детальная информация по транзакциям
            if ($detailed) {
                $this->newLine();
                $this->line("   📋 Детальная статистика:");
                $this->line("      Всего транзакций:      " . $allTransactions->count());
                $this->line("      ├─ Обычных:            " . $nonDebtTransactions->count());
                $this->line("      └─ Долговых:           " . $debtTransactions->count());

                // Группировка по дням
                $transactionsByDate = $nonDebtTransactions->groupBy(function($transaction) {
                    return \Carbon\Carbon::parse($transaction->date)->format('Y-m-d');
                });

                $this->line("      Уникальных дней:       " . $transactionsByDate->count());

                // Средние значения
                if ($incomeCount > 0) {
                    $this->line("      Средний приход:        " . $this->formatMoney($income / $incomeCount));
                }
                if ($outcomeCount > 0) {
                    $this->line("      Средний расход:        " . $this->formatMoney($outcome / $outcomeCount));
                }
            }

            $totalIncome += $income;
            $totalOutcome += $outcome;

            $this->newLine();
        }

        // === Итоговая статистика ===
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('📈 ОБЩАЯ СТАТИСТИКА ПО ВСЕМ КАССАМ:');
        $this->newLine();
        $this->line("   Всего касс:            " . $cashRegisters->count());
        $this->line("   Общий приход:          " . $this->formatMoney($totalIncome));
        $this->line("   Общий расход:          " . $this->formatMoney($totalOutcome));
        $this->line("   Общий баланс:          " . $this->formatMoney($totalIncome - $totalOutcome));
        $this->newLine();

        if (!$hasDiscrepancies) {
            $this->info('✅ Все балансы касс верны!');
        } else {
            $this->error('❌ Обнаружены расхождения в балансах касс!');
            $this->error('   Общая сумма расхождений: ' . $this->formatMoney($totalDiscrepancy));
        }

        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        if (!$detailed) {
            $this->comment('💡 Для получения детальной информации используйте флаг --detailed');
        }

        return Command::SUCCESS;
    }

    /**
     * Форматирование денежной суммы
     */
    private function formatMoney($amount)
    {
        return number_format($amount, 2, '.', ' ');
    }
}

