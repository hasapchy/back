<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read \App\Models\LeaveType|null $leaveType
 */
class Leave extends Model
{
    use HasFactory;

    protected $fillable = ['leave_type_id', 'user_id', 'company_id', 'comment', 'date_from', 'date_to'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}
