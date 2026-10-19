<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ulid_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('uuid_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status');
            $table->timestamps();
        });
    }
};
