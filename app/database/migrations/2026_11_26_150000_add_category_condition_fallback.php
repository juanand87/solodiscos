<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('stores', fn(Blueprint $table) => $table->string('category_condition_fallback')->default('unknown'));
    }
    public function down(): void {
        Schema::table('stores', fn(Blueprint $table) => $table->dropColumn('category_condition_fallback'));
    }
};
