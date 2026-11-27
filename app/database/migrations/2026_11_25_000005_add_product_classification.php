<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('offers',function(Blueprint $t) { $t->json('source_metadata')->nullable(); $t->string('excluded_reason')->nullable(); $t->string('format_evidence')->nullable(); }); }
    public function down(): void { Schema::table('offers',fn(Blueprint $t)=>$t->dropColumn(['source_metadata','excluded_reason','format_evidence'])); }
};
