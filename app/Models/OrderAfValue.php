<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderAfValue extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'order_af_id', 'value'];

    public function additionalField()
    {
        return $this->belongsTo(OrderAf::class, 'order_af_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function getFormattedValue()
    {
        if (!$this->additionalField) {
            return $this->value;
        }

        switch ($this->additionalField->type) {
            case 'date':
                return $this->value ? date('d.m.Y', strtotime($this->value)) : '';
            case 'select':
                $options = $this->additionalField->getOptionsArray();
                return $options[$this->value] ?? $this->value;
            default:
                return $this->value;
        }
    }
}
