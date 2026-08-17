<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_token_sync_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32)->unique();
            $table->string('status', 32)->default('idle');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->unsignedInteger('shopee_updated')->default(0);
            $table->unsignedInteger('tiktok_updated')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_token_sync_statuses');
    }
};
