<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('new_condition_categories')->nullable();
            $table->text('used_condition_categories')->nullable();
        });
    }
    public function down(): void {
        Schema::table('stores', fn(Blueprint $table) => $table->dropColumn(['new_condition_categories','used_condition_categories']));
    }
};
