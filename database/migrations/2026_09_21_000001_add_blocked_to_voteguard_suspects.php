<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voteguard_suspects') || Schema::hasColumn('voteguard_suspects', 'blocked')) {
            return;
        }

        Schema::table('voteguard_suspects', function (Blueprint $table) {
            $table->boolean('blocked')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('voteguard_suspects') || ! Schema::hasColumn('voteguard_suspects', 'blocked')) {
            return;
        }

        Schema::table('voteguard_suspects', function (Blueprint $table) {
            $table->dropColumn('blocked');
        });
    }
};
