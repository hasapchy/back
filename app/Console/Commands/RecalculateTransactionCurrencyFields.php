<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\Currency;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RecalculateTransactionCurrencyFields extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:recalculate-currency-fields
                            {--company-id= : ID компании для пересчета}
                            {--skip-filled : Пропустить транзакции с уже заполненными полями}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Пересчитать поля rep_rate, rep_amount, def_rate, def_amount для всех транзакций по историческим курсам';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $companyId = $this->option('company-id') ? (int)$this->option('company-id') : null;

        $reportCurrency = Currency::where('is_report', true)
            ->where(function($q) use ($companyId) {
                if ($companyId) {
                    $q->where('company_id', $companyId)->orWhereNull('company_id');
                } else {
                    $q->whereNull('company_id')->orWhereNotNull('company_id');
                }
            })
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('company_id')
            ->first();

        if (!$reportCurrency) {
            $this->error('Валюта отчетов (is_report = true) не найдена');
            return 1;
        }

        $defaultCurrency = Currency::where('is_default', true)
            ->where(function($q) use ($companyId) {
                if ($companyId) {
                    $q->where('company_id', $companyId)->orWhereNull('company_id');
                } else {
                    $q->whereNull('company_id')->orWhereNotNull('company_id');
                }
            })
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('company_id')
            ->first();

        if (!$defaultCurrency) {
            $this->error('Дефолтная валюта (is_default = true) не найдена');
            return 1;
        }

        $this->info("Валюта отчетов: {$reportCurrency->name} (ID: {$reportCurrency->id})");
        $this->info("Дефолтная валюта: {$defaultCurrency->name} (ID: {$defaultCurrency->id})");

        $skipFilled = $this->option('skip-filled');

        $query = Transaction::where('is_deleted', false);

        if ($companyId) {
            $query->whereHas('cashRegister', function($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })->orWhereHas('client', function($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })->orWhereHas('project', function($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        if ($skipFilled) {
            $query->where(function($q) {
                $q->whereNull('rep_rate')
                  ->orWhereNull('rep_amount')
                  ->orWhereNull('def_rate')
                  ->orWhereNull('def_amount');
            });
            $this->info('Режим: пропуск транзакций с уже заполненными полями');
        } else {
            $this->warn('Внимание: команда пересчитает ВСЕ поля (rep_rate, rep_amount, def_rate, def_amount) для всех транзакций, включая уже заполненные!');
        }

        $total = $query->count();
        $this->info("Найдено транзакций для пересчета: {$total}");

        if (!$this->confirm('Продолжить пересчет?', true)) {
            $this->info('Операция отменена');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $errors = 0;
        $roundingService = new RoundingService();

        $query->with(['cashRegister', 'client', 'project', 'currency'])->chunk(100, function ($transactions) use (&$updated, &$errors, $reportCurrency, $defaultCurrency, $companyId, $roundingService, $bar) {
            foreach ($transactions as $transaction) {
                try {
                    $transactionCompanyId = $companyId ?? $transaction->cashRegister->company_id ?? $transaction->client->company_id ?? $transaction->project->company_id ?? null;
                    $transactionDate = $transaction->date ? Carbon::parse($transaction->date)->toDateString() : now()->toDateString();

                    $transactionCurrency = $transaction->currency;
                    if (!$transactionCurrency) {
                        $errors++;
                        $bar->advance();
                        continue;
                    }

                    $origAmount = (float)$transaction->orig_amount;

                    $fromRate = $transactionCurrency->getExchangeRateForCompany($transactionCompanyId, $transactionDate);
                    $reportRate = $reportCurrency->getExchangeRateForCompany($transactionCompanyId, $transactionDate);

                    if ($fromRate == 1.0 && $transactionCurrency->id !== $defaultCurrency->id) {
                        $historyCount = $transactionCurrency->exchangeRateHistories()
                            ->where(function($q) use ($transactionCompanyId) {
                                if ($transactionCompanyId) {
                                    $q->where('company_id', $transactionCompanyId)->orWhereNull('company_id');
                                } else {
                                    $q->whereNull('company_id');
                                }
                            })
                            ->count();
                        $this->warn("Транзакция ID {$transaction->id} (дата: {$transactionDate}, компания: {$transactionCompanyId}): курс валюты {$transactionCurrency->name} не найден в истории (найдено записей: {$historyCount}), используется дефолтный курс 1.0");
                    }
                    if ($reportRate == 1.0 && $reportCurrency->id !== $defaultCurrency->id) {
                        $historyCount = $reportCurrency->exchangeRateHistories()
                            ->where(function($q) use ($transactionCompanyId) {
                                if ($transactionCompanyId) {
                                    $q->where('company_id', $transactionCompanyId)->orWhereNull('company_id');
                                } else {
                                    $q->whereNull('company_id');
                                }
                            })
                            ->count();
                        $this->warn("Транзакция ID {$transaction->id} (дата: {$transactionDate}, компания: {$transactionCompanyId}): курс валюты отчетов {$reportCurrency->name} не найден в истории (найдено записей: {$historyCount}), используется дефолтный курс 1.0");
                    }

                    $repAmount = CurrencyConverter::convert($origAmount, $transactionCurrency, $reportCurrency, $defaultCurrency, $transactionCompanyId, $transactionDate);
                    $repAmount = $roundingService->roundForCompany($transactionCompanyId, $repAmount);

                    if ($transactionCurrency->id === $reportCurrency->id) {
                        $repRate = 1.0;
                    } else {
                        if ($transactionCurrency->id === $defaultCurrency->id) {
                            $repRate = 1 / $reportRate;
                        } elseif ($reportCurrency->id === $defaultCurrency->id) {
                            $repRate = $fromRate;
                        } else {
                            $repRate = $fromRate / $reportRate;
                        }
                    }

                    if ($transactionCurrency->id === $defaultCurrency->id) {
                        $defRate = 1.0;
                        $defAmount = $origAmount;
                    } else {
                        $defRate = $fromRate;
                        $defAmount = CurrencyConverter::convert($origAmount, $transactionCurrency, $defaultCurrency, null, $transactionCompanyId, $transactionDate);
                        $defAmount = $roundingService->roundForCompany($transactionCompanyId, $defAmount);
                    }

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'rep_rate' => $repRate,
                            'rep_amount' => $repAmount,
                            'def_rate' => $defRate,
                            'def_amount' => $defAmount,
                        ]);

                    $updated++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->newLine();
                    $this->error("Ошибка при обработке транзакции ID {$transaction->id}: " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Пересчет завершен. Обновлено: {$updated}, Ошибок: {$errors}");

        return 0;
    }
}
