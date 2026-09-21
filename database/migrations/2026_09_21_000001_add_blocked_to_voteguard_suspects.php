<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voteguard_suspects', function (Blueprint $table) {
            $table->boolean('blocked')->default(false)->after('status');
            $table->index('blocked');
        });
    }

    public function down(): void
    {
        Schema::table('voteguard_suspects', function (Blueprint $table) {
            $table->dropIndex(['blocked']);
            $table->dropColumn('blocked');
        });
    }
};
