<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThreadsPost extends Model
{
    protected $fillable = [
        'threads_user_id',
        'post_id',
        'media_type',
        'text',
        'image_url',
        'video_url',
        'media_container_ids',
        'status',
        'error_message'
    ];

    protected $casts = [
        'media_container_ids' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(ThreadsUser::class, 'threads_user_id');
    }
}