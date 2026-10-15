<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->nullable();
            $table->string('status');
            $table->timestamp('status_changed_at')->nullable()->index();
            $table->string('priority');
            $table->timestamp('priority_changed_at')->nullable();
            $table->unsignedInteger('replies')->default(0);
            $table->timestamps();
        });
    }
};
