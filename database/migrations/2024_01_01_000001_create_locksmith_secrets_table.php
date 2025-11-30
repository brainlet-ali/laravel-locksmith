<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locksmith_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->text('previous_value')->nullable();
            $table->timestamp('previous_value_expires_at')->nullable();
            $table->timestamps();

            $table->index('previous_value_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locksmith_secrets');
    }
};
