<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_stock_mirror_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->string('status', 32)->index();
            $table->json('scope');
            $table->json('target_accounts');
            $table->json('state');
            $table->timestamps();
        });
        Schema::create('marketplace_stock_mirror_claims', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('active_run_id')->nullable();
            $table->uuid('owner')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
        DB::table('marketplace_stock_mirror_claims')->insert(['id' => 'global']);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_stock_mirror_claims');
        Schema::dropIfExists('marketplace_stock_mirror_runs');
    }
};
