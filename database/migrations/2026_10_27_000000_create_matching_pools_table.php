<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matching_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The most the sponsor will match across every donation, in cents.
            $table->unsignedBigInteger('cap_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matching_pools');
    }
};
