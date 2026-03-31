<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = ['leave_type_id', 'user_id', 'company_id', 'comment', 'date_from', 'date_to'];

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}
