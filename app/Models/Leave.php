<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\LeaveType;
use App\Models\User;

class Leave extends Model
{
    use HasFactory;

    protected $fillable = ['leave_type_id', 'user_id', 'comment', 'date_from', 'date_to'];

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
