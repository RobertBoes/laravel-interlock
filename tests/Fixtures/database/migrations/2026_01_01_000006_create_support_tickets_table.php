<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->text('last_message')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->string('status');
            $table->timestamps();
        });
    }
};
