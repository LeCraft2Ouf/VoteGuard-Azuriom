<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voteguard_suspects', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->unique();
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('max_score')->default(0);
            $table->unsignedInteger('votes_analyzed')->default(0);
            $table->string('status', 32)->default('watch')->index();
            $table->json('last_flags')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('voteguard_detections', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('vote_id')->nullable();
            $table->unsignedInteger('site_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('flags');
            $table->string('source', 16)->default('live');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index('score');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voteguard_detections');
        Schema::dropIfExists('voteguard_suspects');
    }
};
