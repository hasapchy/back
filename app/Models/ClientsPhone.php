<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель телефона клиента
 *
 * @property int $id
 * @property int $client_id ID клиента
 * @property string $phone Номер телефона
 * @property bool $is_sms Разрешена ли отправка SMS
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\Client $client
 */
class ClientsPhone extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'phone', 'is_sms'];

    protected $casts = [
        'is_sms' => 'boolean',
    ];

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
