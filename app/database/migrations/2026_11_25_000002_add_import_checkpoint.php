<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('stores',function(Blueprint $t) { $t->unsignedInteger('next_page')->default(1); $t->timestamp('cycle_started_at')->nullable(); }); }
    public function down(): void { Schema::table('stores',fn(Blueprint $t)=>$t->dropColumn(['next_page','cycle_started_at'])); }
};
