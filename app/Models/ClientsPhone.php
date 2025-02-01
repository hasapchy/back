<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientsPhone extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'phone', 'is_sms'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
