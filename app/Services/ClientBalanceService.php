<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Currency;
use App\Models\ClientBalance;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientBalanceService
{
    /**
     * Создать баланс в указанной валюте
     * Если isDefault = true, автоматически снимает флаг is_default с других балансов клиента
     *
     * ⚠️ Примечание: Метод должен вызываться из транзакции (обычно вызывается из ClientsRepository::createItem() или updateBalance())
     *
     * @param Client $client Клиент
     * @param Currency|null $currency Валюта (если null, используется дефолтная валюта системы)
     * @param bool $isDefault Установить как дефолтный баланс
     * @param float $initialBalance Начальное значение баланса
     * @param string|null $note Примечание
     * @return ClientBalance
     */
    public static function createBalance(Client $client, ?Currency $currency = null, bool $isDefault = false, float $initialBalance = 0, ?string $note = null): ClientBalance
    {
        if (!$currency) {
            $currency = Currency::where('is_default', true)->first();
            if (!$currency) {
                throw new \Exception('Дефолтная валюта системы не найдена');
            }
            $isDefault = true;
        }

        if ($isDefault) {
            self::clearDefaultFlags($client->id);
        }

        return ClientBalance::create([
            'client_id' => $client->id,
            'currency_id' => $currency->id,
            'balance' => $initialBalance,
            'is_default' => $isDefault,
            'note' => $note,
        ]);
    }

    /**
     * Снять флаг is_default со всех балансов клиента (кроме указанного)
     * Используется при создании/установке дефолтного баланса
     *
     * @param int $clientId ID клиента
     * @param int|null $excludeBalanceId ID баланса, который исключаем из обновления (опционально)
     */
    public static function clearDefaultFlags(int $clientId, ?int $excludeBalanceId = null): void
    {
        $query = ClientBalance::where('client_id', $clientId)
            ->where('is_default', true);

        if ($excludeBalanceId) {
            $query->where('id', '!=', $excludeBalanceId);
        }

        $query->update(['is_default' => false]);
    }

    /**
     * Обновить баланс клиента при транзакции
     * Логика:
     * 1. Если у клиента есть баланс в валюте транзакции - обновляем его напрямую (без конвертации)
     * 2. Если баланса в валюте транзакции нет - конвертируем сумму в валюту дефолтного баланса и обновляем его
     *
     * Примечание: Дефолтный баланс всегда существует, так как создается автоматически при создании клиента
     *
     * ✅ Метод уже обернут в транзакцию и использует lockForUpdate() для предотвращения race conditions
     *
     * @param Client $client Клиент
     * @param Currency $transactionCurrency Валюта транзакции
     * @param float $amount Сумма транзакции (может быть отрицательной для отката)
     * @param int $type Тип транзакции (0 или 1)
     * @param bool $isDebt Является ли долгом
     * @param int|null $companyId ID компании
     * @param string|null $date Дата транзакции
     * @param float|null $exchangeRate Ручной курс обмена
     * @param Currency|null $cashCurrency Валюта кассы
     */
    public static function updateBalance(Client $client, Currency $transactionCurrency, float $amount, int $type, bool $isDebt, ?int $companyId = null, ?string $date = null, ?float $exchangeRate = null, ?Currency $cashCurrency = null): ?int
    {
        if (!in_array($type, [0, 1])) {
            throw new \InvalidArgumentException('Некорректный тип транзакции');
        }

        $balanceId = null;

        DB::transaction(function () use ($client, $transactionCurrency, $amount, $type, $isDebt, $companyId, $date, $exchangeRate, $cashCurrency, &$balanceId) {
            $balance = ClientBalance::where('client_id', $client->id)
                ->where('currency_id', $transactionCurrency->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->first();

            if (!$balance) {
                $balance = ClientBalance::where('client_id', $client->id)
                    ->where('currency_id', $transactionCurrency->id)
                    ->lockForUpdate()
                    ->orderBy('id', 'asc')
                    ->first();
            }

            if ($balance) {
                $delta = self::calculateBalanceDelta($amount, $type, $isDebt);
                $balance->increment('balance', $delta);
                $balanceId = $balance->id;
                return;
            }

            $defaultBalance = ClientBalance::where('client_id', $client->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->first();

            if (!$defaultBalance) {
                $defaultCurrency = Currency::where('is_default', true)->first();
                if (!$defaultCurrency) {
                    throw new \Exception('Дефолтная валюта системы не найдена');
                }
                $defaultBalance = self::createBalance($client, $defaultCurrency, true);
            }

            if ($defaultBalance->currency_id === $transactionCurrency->id) {
                $delta = self::calculateBalanceDelta($amount, $type, $isDebt);
                $defaultBalance->increment('balance', $delta);
                $balanceId = $defaultBalance->id;
                return;
            }

            $defaultCurrency = Currency::find($defaultBalance->currency_id);
            if (!$defaultCurrency) {
                throw new \Exception("Валюта дефолтного баланса (ID: {$defaultBalance->currency_id}) не найдена");
            }

            try {
                $convertedAmount = self::convertAmountToDefaultCurrency(
                    $amount,
                    $transactionCurrency,
                    $defaultCurrency,
                    $companyId,
                    $date,
                    $exchangeRate,
                    $cashCurrency
                );

                $roundingService = new RoundingService;
                $convertedAmount = $roundingService->roundForCompany($companyId, $convertedAmount);

                $delta = self::calculateBalanceDelta($convertedAmount, $type, $isDebt);
                $defaultBalance->increment('balance', $delta);
                $balanceId = $defaultBalance->id;
            } catch (\Exception $e) {
                Log::error('Ошибка конвертации валют при обновлении баланса', [
                    'client_id' => $client->id,
                    'from_currency' => $transactionCurrency->id,
                    'to_currency' => $defaultCurrency->id,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException('Не удалось конвертировать валюту: ' . $e->getMessage(), 0, $e);
            }
        });

        return $balanceId;
    }

    /**
     * Конвертировать сумму в валюту дефолтного баланса
     * Логика из TransactionsRepository::convertAmountToDefaultCurrency
     *
     * @param float $amount Сумма
     * @param Currency $fromCurrency Валюта источника
     * @param Currency $defaultCurrency Валюта дефолтного баланса
     * @param int|null $companyId ID компании
     * @param string|null $date Дата
     * @param float|null $exchangeRate Ручной курс обмена
     * @param Currency|null $cashCurrency Валюта кассы
     * @return float
     */
    private static function convertAmountToDefaultCurrency(float $amount, Currency $fromCurrency, Currency $defaultCurrency, ?int $companyId, $date = null, ?float $exchangeRate = null, ?Currency $cashCurrency = null): float
    {
        if ($exchangeRate !== null && $exchangeRate > 0 && $cashCurrency) {
            $amountInCashCurrency = $amount * $exchangeRate;

            if ($cashCurrency->id === $defaultCurrency->id) {
                return $amountInCashCurrency;
            } else {
                return CurrencyConverter::convert($amountInCashCurrency, $cashCurrency, $defaultCurrency, null, $companyId, $date ?? now());
            }
        } elseif ($fromCurrency->id !== $defaultCurrency->id) {
            return CurrencyConverter::convert($amount, $fromCurrency, $defaultCurrency, null, $companyId, $date ?? now());
        }

        return $amount;
    }

    /**
     * Вычислить изменение баланса на основе типа транзакции
     * Логика соответствует ClientsRepository::calculateBalanceDelta и TransactionsRepository::updateClientBalanceValue
     *
     * @param float $amount Сумма
     * @param int $type Тип транзакции (0 или 1)
     * @param bool $isDebt Является ли долгом
     * @return float
     */
    private static function calculateBalanceDelta(float $amount, int $type, bool $isDebt): float
    {
        if ($amount == 0.0) {
            return 0;
        }

        $sign = $isDebt
            ? ($type === 1 ? 1 : -1)
            : ($type === 1 ? -1 : 1);

        return $sign * $amount;
    }

    /**
     * Получить баланс клиента в валюте (или дефолтный)
     *
     * @param Client $client Клиент
     * @param Currency|null $currency Валюта (если null, возвращается дефолтный баланс)
     * @return ClientBalance|null
     */
    public static function getBalance(Client $client, ?Currency $currency = null): ?ClientBalance
    {
        if ($currency) {
            return ClientBalance::where('client_id', $client->id)
                ->where('currency_id', $currency->id)
                ->first();
        }

        return ClientBalance::where('client_id', $client->id)
            ->where('is_default', true)
            ->first();
    }
}
