<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('scrape_runs',function(Blueprint $t) { $t->unsignedInteger('current_page')->nullable(); $t->timestamp('started_at')->nullable(); $t->timestamp('heartbeat_at')->nullable(); }); }
    public function down(): void { Schema::table('scrape_runs',fn(Blueprint $t)=>$t->dropColumn(['current_page','started_at','heartbeat_at'])); }
};
