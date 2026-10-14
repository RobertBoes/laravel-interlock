<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('claimant_id')->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }
};
