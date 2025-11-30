<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locksmith_rotation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('secret_id')->constrained('locksmith_secrets')->cascadeOnDelete();
            $table->unsignedTinyInteger('status')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('rotated_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['secret_id', 'status']);
            $table->index('rotated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locksmith_rotation_logs');
    }
};
