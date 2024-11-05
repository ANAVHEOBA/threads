<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('threads_users', function (Blueprint $table) {
            $table->id();
            $table->string('threads_user_id')->unique();
            $table->string('threads_access_token');
            $table->string('threads_refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('state')->nullable(); // For OAuth state verification
            $table->text('profile_pic_url')->nullable();
            $table->text('biography')->nullable();
            $table->integer('followers_count')->default(0);
            $table->integer('following_count')->default(0);
            $table->string('scope')->nullable();
            $table->timestamp('last_auth_at')->nullable();
            $table->timestamps();
        });

        Schema::create('threads_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('threads_user_id')->constrained('threads_users')->onDelete('cascade');
            $table->string('post_id')->unique(); // Threads post ID
            $table->string('media_type'); // TEXT, IMAGE, VIDEO, CAROUSEL
            $table->text('text')->nullable();
            $table->string('image_url')->nullable();
            $table->string('video_url')->nullable();
            $table->json('media_container_ids')->nullable(); // For storing multiple media IDs
            $table->string('status'); // pending, published, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('threads_posts');
        Schema::dropIfExists('threads_users');
    }
};