<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'user_id', 'client_id', 'files', 'budget', 'date'];

    protected $casts = [
        'files' => 'array',
        'date' => 'datetime'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id');
    }

    public function getUsersAttribute()
    {
        return $this->users()->get();
    }

    public function hasUser($userId)
    {
        return $this->users()->where('user_id', $userId)->exists();
    }
}
