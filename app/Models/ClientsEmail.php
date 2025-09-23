<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientsEmail extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'email'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
