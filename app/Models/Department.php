<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasManyToManyUsers;

class Department extends Model
{
    use HasFactory, HasManyToManyUsers;

    protected $fillable = [
        'title',
        'description',
        'parent_id',
        'head_id',
        'deputy_head_id',
        'company_id',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function head()
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    public function deputyHead()
    {
        return $this->belongsTo(User::class, 'deputy_head_id');
    }

    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
