<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A plain numeric column, so mass increments of attributes Interlock does not govern can be tested.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('delivery_attempts')->default(0);
        });
    }
};
