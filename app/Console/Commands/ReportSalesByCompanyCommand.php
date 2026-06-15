<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportSalesByCompanyCommand extends Command
{
    protected $signature = 'sales:report-by-company
                            {--company-id= : Только указанная компания}
                            {--show-ids : Показать ID продаж по каждой компании}';

    protected $description = 'Сводка продаж по компаниям (через склад) для планирования миграции в заказы';

    /**
     * @return int
     */
    public function handle(): int
    {
        $companyFilter = $this->option('company-id');
        $companyId = ($companyFilter !== null && $companyFilter !== '') ? (int) $companyFilter : null;

        $totalSales = (int) DB::table('sales')->count();
        $totalLines = (int) DB::table('sales_products')->count();
        $nullCash = (int) DB::table('sales')->whereNull('cash_id')->count();
        $withTransactions = (int) DB::table('transactions')
            ->where('source_type', 'App\\Models\\Sale')
            ->where('is_deleted', false)
            ->distinct('source_id')
            ->count('source_id');

        $this->info("Всего продаж: {$totalSales}");
        $this->info("Строк sales_products: {$totalLines}");
        $this->info("Продаж без кассы (cash_id IS NULL): {$nullCash}");
        $this->info("Продаж с активными транзакциями: {$withTransactions}");
        $this->newLine();

        $mismatchCount = $this->countCompanyMismatch();
        if ($mismatchCount > 0) {
            $this->warn("Продаж, где company_id кассы ≠ company_id склада: {$mismatchCount}");
            $this->warn('Для миграции используется company_id склада (warehouse).');
            $this->newLine();
        }

        $rows = $this->fetchSummaryByWarehouseCompany($companyId);

        if ($rows->isEmpty()) {
            $this->warn('Продаж не найдено.');

            return self::SUCCESS;
        }

        $this->table(
            ['company_id', 'company', 'sales', 'lines', 'sum_price', 'sum_discount', 'first_date', 'last_date'],
            $rows->map(fn ($row) => [
                $row->company_id ?? '—',
                $row->company_name ?? '(без компании)',
                $row->sales_count,
                $row->lines_count,
                $row->sum_price,
                $row->sum_discount,
                $row->first_sale,
                $row->last_sale,
            ])
        );

        if ($this->option('show-ids')) {
            $this->newLine();
            foreach ($rows as $row) {
                $ids = $this->fetchSaleIdsForCompany($row->company_id);
                $this->line("company_id={$row->company_id}: " . $ids->implode(', '));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param int|null $companyId
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fetchSummaryByWarehouseCompany(?int $companyId)
    {
        $query = DB::table('sales')
            ->join('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('companies', 'warehouses.company_id', '=', 'companies.id')
            ->selectRaw('
                warehouses.company_id as company_id,
                companies.name as company_name,
                COUNT(sales.id) as sales_count,
                ROUND(SUM(sales.price), 2) as sum_price,
                ROUND(SUM(sales.discount), 2) as sum_discount,
                MIN(sales.date) as first_sale,
                MAX(sales.date) as last_sale
            ')
            ->groupBy('warehouses.company_id', 'companies.name')
            ->orderByDesc('sales_count');

        if ($companyId !== null) {
            $query->where('warehouses.company_id', $companyId);
        }

        $rows = $query->get();

        $lineCounts = DB::table('sales_products')
            ->join('sales', 'sales_products.sale_id', '=', 'sales.id')
            ->join('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->selectRaw('warehouses.company_id as company_id, COUNT(sales_products.id) as lines_count')
            ->when($companyId !== null, fn ($q) => $q->where('warehouses.company_id', $companyId))
            ->groupBy('warehouses.company_id')
            ->pluck('lines_count', 'company_id');

        return $rows->map(function ($row) use ($lineCounts) {
            $row->lines_count = (int) ($lineCounts[$row->company_id] ?? 0);

            return $row;
        });
    }

    /**
     * @return int
     */
    private function countCompanyMismatch(): int
    {
        return (int) DB::table('sales as s')
            ->leftJoin('cash_registers as cr', 's.cash_id', '=', 'cr.id')
            ->join('warehouses as w', 's.warehouse_id', '=', 'w.id')
            ->whereRaw('COALESCE(cr.company_id, w.company_id) != w.company_id')
            ->count();
    }

    /**
     * @param int|null $companyId
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function fetchSaleIdsForCompany(?int $companyId)
    {
        return DB::table('sales')
            ->join('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->where('warehouses.company_id', $companyId)
            ->orderBy('sales.id')
            ->pluck('sales.id');
    }
}
