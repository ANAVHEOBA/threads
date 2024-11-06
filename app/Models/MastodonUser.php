<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MastodonUser extends Model
{
    protected $fillable = [
        'mastodon_user_id',
        'mastodon_access_token',
        'refresh_token',
        'username',
        'display_name',
        'avatar_url',
        'bio',
        'instance_url',
        'scope',
        'state',
        'token_expires_at'
    ];

    protected $dates = [
        'token_expires_at',
        'created_at',
        'updated_at'
    ];

    protected $hidden = [
        'mastodon_access_token',
        'refresh_token'
    ];

    public function posts()
    {
        return $this->hasMany(MastodonPost::class);
    }

    public function needsTokenRefresh()
    {
        return $this->token_expires_at && $this->token_expires_at->subMinutes(5)->isPast();
    }
}