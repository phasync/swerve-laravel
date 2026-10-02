<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gadgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('hidden')->default(false);
            $table->unsignedInteger('listener_calls')->default(0);
            $table->unsignedInteger('observer_calls')->default(0);
        });
    }
};
