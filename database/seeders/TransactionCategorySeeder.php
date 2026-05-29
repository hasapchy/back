<?php

namespace Database\Seeders;

use App\Models\TransactionCategory;
use Illuminate\Database\Seeder;

class TransactionCategorySeeder extends Seeder
{
    private const CREATOR_ID = 1;

    /**
     * @return void
     */
    public function run(): void
    {
        $this->seedRows($this->legacyCategories(), false);
        $this->seedRows($this->incomeGroups(), false);
        $this->seedRows($this->expenseGroups(), false);
        $this->seedRows($this->incomeLeaves(), true);
        $this->seedRows($this->expenseLeaves(), true);
        $this->attachLegacyParents();
    }

    /**
     * @param array<int, array{id: int, name: string, type: int, parent_id: int|null}> $rows
     * @return void
     */
    private function seedRows(array $rows, bool $withParent): void
    {
        foreach ($rows as $row) {
            $payload = [
                'name' => $row['name'],
                'type' => $row['type'],
                'creator_id' => self::CREATOR_ID,
            ];
            if ($withParent) {
                $payload['parent_id'] = $row['parent_id'];
            }
            TransactionCategory::updateOrCreate(['id' => $row['id']], $payload);
        }
    }

    /**
     * @return array<int, array{id: int, name: string, type: int, parent_id: int|null}>
     */
    private function legacyCategories(): array
    {
        $legacy = [
            [1, 'SALE', 1],
            [2, 'CUSTOMER_PAYMENT', 1],
            [3, 'PREPAYMENT', 1],
            [4, 'OTHER_INCOME', 1],
            [5, 'CUSTOMER_REFUND', 0],
            [6, 'GOODS_PAYMENT', 0],
            [7, 'SALARY_PAYMENT', 0],
            [8, 'TAX_PAYMENT', 0],
            [9, 'RENT_PAYMENT', 0],
            [10, 'FUEL_TRANSPORT', 0],
            [11, 'UTILITIES', 0],
            [12, 'ADVERTISING', 0],
            [13, 'PHONE_INTERNET', 0],
            [14, 'OTHER_EXPENSE', 0],
            [15, 'FOOD', 0],
            [16, 'LOGISTICS', 0],
            [17, 'TRANSFER_OUTCOME', 0],
            [18, 'TRANSFER_INCOME', 1],
            [19, 'NON_CASH', 0],
            [20, 'BONUS', 0],
            [21, 'BALANCE_ADJUSTMENT_EXP', 0],
            [22, 'BALANCE_ADJUSTMENT_INC', 1],
            [23, 'ADVANCE', 0],
            [24, 'SALARY_ACCRUAL', 0],
            [25, 'ORDER', 1],
            [26, 'PREMIUM', 0],
            [27, 'FINE', 1],
            [28, 'RENT_INCOME', 1],
            [30, 'CONTRACT', 1],
            [31, 'UNPAID_LEAVE', 1],
        ];

        return $this->rowsFromTuples($legacy);
    }

    /**
     * @return array<int, array{id: int, name: string, type: int, parent_id: int|null}>
     */
    private function incomeGroups(): array
    {
        return $this->rowsFromTuples([
            [100, 'GROUP_INCOME_ORDERS_PAYMENTS', 1],
            [101, 'GROUP_INCOME_RENT_PROPERTY', 1],
            [102, 'GROUP_INCOME_FINANCE', 1],
            [103, 'GROUP_INCOME_REFUNDS_COMPENSATION', 1],
            [104, 'GROUP_INCOME_OTHER', 1],
            [105, 'GROUP_INCOME_EMPLOYEE', 1],
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string, type: int, parent_id: int|null}>
     */
    private function expenseGroups(): array
    {
        return $this->rowsFromTuples([
            [200, 'GROUP_EXPENSE_PURCHASES', 0],
            [201, 'GROUP_EXPENSE_PRODUCTION', 0],
            [202, 'GROUP_EXPENSE_PAYROLL', 0],
            [203, 'GROUP_EXPENSE_TAXES', 0],
            [204, 'GROUP_EXPENSE_GOVERNMENT', 0],
            [205, 'GROUP_EXPENSE_RENT_PREMISES', 0],
            [206, 'GROUP_EXPENSE_TRANSPORT', 0],
            [207, 'GROUP_EXPENSE_IT', 0],
            [208, 'GROUP_EXPENSE_BANKING', 0],
            [209, 'GROUP_EXPENSE_PARTNER', 0],
            [210, 'GROUP_EXPENSE_ADMIN', 0],
            [211, 'GROUP_EXPENSE_REFUNDS', 0],
            [212, 'GROUP_EXPENSE_OTHER', 0],
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string, type: int, parent_id: int|null}>
     */
    private function incomeLeaves(): array
    {
        return $this->rowsFromParentTuples([
            [121, 'ACCOUNT_TOP_UP', 1, 102],
            [122, 'FOUNDER_CONTRIBUTION', 1, 102],
            [123, 'INVESTMENT_INCOME', 1, 102],
            [124, 'LOAN_RECEIVED', 1, 102],
            [125, 'DEBT_REPAYMENT_RECEIVED', 1, 102],
            [126, 'FX_GAIN', 1, 102],
            [127, 'SUPPLIER_REFUND_INCOME', 1, 103],
            [128, 'OVERPAYMENT_REFUND', 1, 103],
            [129, 'COMPENSATION_INCOME', 1, 103],
            [130, 'INSURANCE_PAYOUT', 1, 103],
            [131, 'CASHBACK', 1, 104],
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string, type: int, parent_id: int|null}>
     */
    private function expenseLeaves(): array
    {
        $leaves = [
            [220, 'RAW_MATERIALS_PURCHASE', 200],
            [221, 'MATERIALS_PURCHASE', 200],
            [222, 'EQUIPMENT_PURCHASE', 200],
            [223, 'CONSUMABLES_PURCHASE', 200],
            [224, 'PACKAGING_PURCHASE', 200],
            [225, 'PRODUCTION_COSTS', 201],
            [226, 'CUTTING_SHOP_COSTS', 201],
            [227, 'PRODUCTION_MATERIALS', 201],
            [228, 'PRODUCTION_CONSUMABLES', 201],
            [229, 'EQUIPMENT_MAINTENANCE', 201],
            [230, 'EQUIPMENT_REPAIR', 201],
            [231, 'PRODUCTION_DEFECT', 201],
            [232, 'PACKAGING_EXPENSE', 201],
            [233, 'BUSINESS_TRIP', 202],
            [234, 'STAFF_TRAINING', 202],
            [235, 'CUSTOMS_FEE', 203],
            [236, 'PENSION_FUND_PAYMENT', 203],
            [237, 'FIRE_SERVICE', 204],
            [238, 'STATE_STANDARD', 204],
            [239, 'MVD_FEE', 204],
            [240, 'ECOLOGY_FEE', 204],
            [241, 'CERTIFICATION_FEE', 204],
            [242, 'LICENSE_FEE', 204],
            [243, 'COURT_FEE', 204],
            [244, 'HAKIMLIK_FEE', 204],
            [245, 'CLEANING', 205],
            [246, 'PREMISES_MAINTENANCE', 205],
            [247, 'PREMISES_REPAIR', 205],
            [248, 'VEHICLE_REPAIR', 206],
            [249, 'PARKING', 206],
            [250, 'TAXI', 206],
            [251, 'SERVERS', 207],
            [252, 'HOSTING', 207],
            [253, 'CLOUD_SERVICES', 207],
            [254, 'SUBSCRIPTIONS', 207],
            [255, 'SOFTWARE_LICENSES', 207],
            [256, 'BANK_COMMISSION', 208],
            [257, 'ACQUIRING', 208],
            [258, 'CURRENCY_CONVERSION', 208],
            [259, 'LOAN_INTEREST', 208],
            [260, 'FX_LOSS', 208],
            [261, 'PROJECT_COMMISSION', 209],
            [262, 'PROFIT_SHARE', 209],
            [263, 'INTERMEDIARY_PAYMENT', 209],
            [264, 'GROCERIES', 210],
            [265, 'BEVERAGES', 210],
            [266, 'STATIONERY', 210],
            [267, 'HOUSEHOLD_SUPPLIES', 210],
            [268, 'REPRESENTATION_EXPENSE', 210],
            [269, 'SUPPLIER_REFUND_EXPENSE', 211],
            [270, 'CHARITY', 212],
            [271, 'UNEXPECTED_EXPENSE', 212],
        ];

        return $this->rowsFromExpenseParentTuples($leaves);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}> $tuples
     * @return array<int, array{id: int, name: string, type: int, parent_id: int}>
     */
    private function rowsFromExpenseParentTuples(array $tuples): array
    {
        $rows = [];
        foreach ($tuples as [$id, $name, $parentId]) {
            $rows[] = ['id' => $id, 'name' => $name, 'type' => 0, 'parent_id' => $parentId];
        }

        return $rows;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}> $tuples
     * @return array<int, array{id: int, name: string, type: int, parent_id: null}>
     */
    private function rowsFromTuples(array $tuples): array
    {
        $rows = [];
        foreach ($tuples as [$id, $name, $type]) {
            $rows[] = ['id' => $id, 'name' => $name, 'type' => $type, 'parent_id' => null];
        }

        return $rows;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int, 3: int}> $tuples
     * @return array<int, array{id: int, name: string, type: int, parent_id: int}>
     */
    private function rowsFromParentTuples(array $tuples): array
    {
        $rows = [];
        foreach ($tuples as [$id, $name, $type, $parentId]) {
            $rows[] = ['id' => $id, 'name' => $name, 'type' => $type, 'parent_id' => $parentId];
        }

        return $rows;
    }

    /**
     * Распределение legacy-категорий по группам (id и slug не меняются).
     *
     * @return void
     */
    private function attachLegacyParents(): void
    {
        $map = [
            1 => 100,
            2 => 100,
            3 => 100,
            25 => 100,
            30 => 100,
            28 => 101,
            18 => 102,
            22 => 102,
            4 => 104,
            31 => 104,
            27 => 105,
            6 => 200,
            24 => 202,
            7 => 202,
            23 => 202,
            26 => 202,
            20 => 202,
            8 => 203,
            9 => 205,
            11 => 205,
            10 => 206,
            16 => 206,
            13 => 207,
            12 => 210,
            15 => 210,
            5 => 211,
            14 => 212,
            17 => 208,
            19 => 208,
            21 => 212,
        ];

        foreach ($map as $categoryId => $parentId) {
            TransactionCategory::where('id', $categoryId)->update(['parent_id' => $parentId]);
        }
    }
}
