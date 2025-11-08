<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'show_deleted_transactions',
        'rounding_decimals',
        'rounding_enabled',
        'rounding_direction',
        'rounding_custom_threshold',
        'rounding_quantity_decimals',
        'rounding_quantity_enabled',
        'rounding_quantity_direction',
        'rounding_quantity_custom_threshold',
        'skip_project_order_balance',
    ];

    protected $attributes = [
        'logo' => 'logo.jpg',
        'show_deleted_transactions' => false,
        'rounding_enabled' => true,
        'rounding_direction' => 'standard',
        'rounding_quantity_enabled' => true,
        'rounding_quantity_direction' => 'standard',
        'skip_project_order_balance' => true,
    ];

    protected $casts = [
        'show_deleted_transactions' => 'boolean',
        'rounding_enabled' => 'boolean',
        'rounding_quantity_enabled' => 'boolean',
        'skip_project_order_balance' => 'boolean',
    ];

    /**
     * Пользователи, принадлежащие к компании
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user');
    }
}
