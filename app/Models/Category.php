<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'parent_id', 'user_id'];

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function categoryUsers()
    {
        return $this->hasMany(CategoryUser::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'category_users', 'category_id', 'user_id');
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
