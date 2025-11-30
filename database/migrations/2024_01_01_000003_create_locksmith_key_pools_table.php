<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locksmith_key_pools', function (Blueprint $table): void {
            $table->id();
            $table->string('secret_key')->index();
            $table->text('value');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['secret_key', 'status']);
            $table->index(['secret_key', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locksmith_key_pools');
    }
};
