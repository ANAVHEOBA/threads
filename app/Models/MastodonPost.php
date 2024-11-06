<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MastodonPost extends Model
{
    protected $fillable = [
        'mastodon_user_id',
        'post_id',
        'content',
        'visibility',
        'sensitive',
        'spoiler_text',
        'media_ids',
        'language',
        'status',
        'error_message'
    ];

    protected $casts = [
        'media_ids' => 'array',
        'sensitive' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(MastodonUser::class, 'mastodon_user_id');
    }
}