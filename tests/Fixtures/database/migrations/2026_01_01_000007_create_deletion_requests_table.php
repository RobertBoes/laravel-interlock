<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->timestamp('process_after')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }
};
