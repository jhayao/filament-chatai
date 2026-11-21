<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatai_models', function (Blueprint $table): void {
            $table->string('type', 20)->default('chat');
            $table->unsignedInteger('dimensions')->nullable();
        });
        Schema::table('chatai_usage', fn (Blueprint $table) => $table->unsignedInteger('tokens_in')->nullable()->default(0)->change());
    }

    public function down(): void
    {
        DB::table('chatai_usage')->whereNull('tokens_in')->update(['tokens_in' => 0]);
        Schema::table('chatai_usage', fn (Blueprint $table) => $table->unsignedInteger('tokens_in')->nullable(false)->default(0)->change());
        Schema::table('chatai_models', fn (Blueprint $table) => $table->dropColumn(['type', 'dimensions']));
    }
};
