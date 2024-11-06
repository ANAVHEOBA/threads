<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('mastodon_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mastodon_user_id')->constrained('mastodon_users')->onDelete('cascade');
            $table->string('post_id')->unique();
            $table->text('content');
            $table->string('visibility')->default('public');
            $table->boolean('sensitive')->default(false);
            $table->text('spoiler_text')->nullable();
            $table->json('media_ids')->nullable();
            $table->string('language')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mastodon_posts');
    }
};