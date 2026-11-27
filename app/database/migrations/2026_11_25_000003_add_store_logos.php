<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('stores',function(Blueprint $t) { $t->text('logo_url')->nullable(); $t->timestamp('logo_checked_at')->nullable(); }); }
    public function down(): void { Schema::table('stores',fn(Blueprint $t)=>$t->dropColumn(['logo_url','logo_checked_at'])); }
};
