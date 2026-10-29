<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matching_pool_id')->constrained();
            $table->unsignedBigInteger('amount_cents');
            // How much of this donation the sponsor matched. Zero once the pool is spent.
            $table->unsignedBigInteger('matched_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
