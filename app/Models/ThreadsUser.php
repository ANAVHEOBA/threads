<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThreadsUser extends Model
{
    protected $fillable = [
        'threads_user_id',
        'threads_access_token',
        'threads_refresh_token',
        'token_expires_at',
        'username',
        'email',
        'state'
    ];

    protected $dates = [
        'token_expires_at',
        'created_at',
        'updated_at'
    ];

    public function posts()
    {
        return $this->hasMany(ThreadsPost::class);
    }
}