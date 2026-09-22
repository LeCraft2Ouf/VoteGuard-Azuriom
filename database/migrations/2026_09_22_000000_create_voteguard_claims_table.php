<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voteguard_claims')) {
            return;
        }

        Schema::create('voteguard_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('site_id')->nullable();
            $table->unsignedInteger('vote_id')->nullable();
            $table->string('outcome', 16);
            $table->unsignedSmallInteger('pendings')->default(0);
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->boolean('authenticated')->default(false);
            $table->json('flags')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index('created_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voteguard_claims');
    }
};
