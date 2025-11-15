<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель email клиента
 *
 * @property int $id
 * @property int $client_id ID клиента
 * @property string $email Email адрес
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 */
class ClientsEmail extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'email'];

    /**
     * Связь с клиентом
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
