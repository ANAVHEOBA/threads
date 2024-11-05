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
        'state',
        'profile_pic_url',
        'biography',
        'followers_count',
        'following_count',
        'scope',
        'last_auth_at'
    ];

    protected $dates = [
        'token_expires_at',
        'last_auth_at',
        'created_at',
        'updated_at'
    ];

    protected $hidden = [
        'threads_access_token',
        'threads_refresh_token'
    ];

    public function posts()
    {
        return $this->hasMany(ThreadsPost::class);
    }
}