<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orphan_variant_cleanup_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('account_key');
            $t->string('status');
            $t->json('state');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('orphan_variant_cleanup_guards', function (Blueprint $t): void {
            $t->string('product_key')->primary();
            $t->uuid('run_id');
            $t->string('status');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orphan_variant_cleanup_guards');
        Schema::dropIfExists('orphan_variant_cleanup_runs');
    }
};
