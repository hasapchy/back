<?php

namespace App\Http\Requests\Concerns;

trait PreparesCompanyRequestData
{
    /**
     * @return void
     */
    protected function prepareCompanyRequestData(): void
    {
        $data = $this->all();

        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_orders_enabled'])) {
            $data['rounding_orders_enabled'] = filter_var($data['rounding_orders_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_contracts_enabled'])) {
            $data['rounding_contracts_enabled'] = filter_var($data['rounding_contracts_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_warehouse_enabled'])) {
            $data['rounding_warehouse_enabled'] = filter_var($data['rounding_warehouse_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_quantity_enabled'])) {
            $data['rounding_quantity_enabled'] = filter_var($data['rounding_quantity_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['skip_project_order_balance'])) {
            $data['skip_project_order_balance'] = filter_var($data['skip_project_order_balance'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }
        if (isset($data['rounding_quantity_custom_threshold']) && $data['rounding_quantity_custom_threshold'] === '') {
            $data['rounding_quantity_custom_threshold'] = null;
        }

        if (isset($data['rounding_enabled'])) {
            $roundingEnabled = $data['rounding_enabled'];
            if ($roundingEnabled === false || $roundingEnabled === 'false' || $roundingEnabled === '0' || $roundingEnabled === 0) {
                $data['rounding_direction'] = null;
                $data['rounding_custom_threshold'] = null;
                $data['rounding_orders_enabled'] = false;
                $data['rounding_contracts_enabled'] = false;
                $data['rounding_warehouse_enabled'] = false;
            }
        }

        if (isset($data['rounding_quantity_enabled'])) {
            $roundingQuantityEnabled = $data['rounding_quantity_enabled'];
            if ($roundingQuantityEnabled === false || $roundingQuantityEnabled === 'false' || $roundingQuantityEnabled === '0' || $roundingQuantityEnabled === 0) {
                $data['rounding_quantity_direction'] = null;
                $data['rounding_quantity_custom_threshold'] = null;
            }
        }

        $this->merge($data);
    }
}
