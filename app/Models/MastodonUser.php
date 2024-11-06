<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MastodonUser extends Model
{
    protected $fillable = [
        'mastodon_user_id',
        'mastodon_access_token',
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
        'mastodon_access_token'
    ];

    public function posts()
    {
        return $this->hasMany(MastodonPost::class);
    }
}