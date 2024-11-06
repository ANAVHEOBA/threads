<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('mastodon_users', function (Blueprint $table) {
            $table->id();
            $table->string('mastodon_user_id')->unique();
            $table->string('mastodon_access_token');
            $table->string('refresh_token')->nullable(); // Added for token refresh
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('bio')->nullable();
            $table->string('instance_url')->nullable();
            $table->string('scope')->nullable();
            $table->string('state')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mastodon_users');
    }
};