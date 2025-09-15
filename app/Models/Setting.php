<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['setting_name', 'setting_value', 'company_id'];

    /**
     * Компания, к которой принадлежит настройка
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
