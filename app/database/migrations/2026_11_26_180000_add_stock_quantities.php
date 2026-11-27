<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach(['offers','offer_observations'] as $table)Schema::table($table,function(Blueprint $t){
            $t->unsignedInteger('stock_quantity')->nullable();$t->string('stock_quantity_source',24)->default('unknown');
        });
        Schema::table('offers',fn(Blueprint $t)=>$t->timestamp('stock_quantity_checked_at')->nullable());
        Schema::table('offer_events',function(Blueprint $t){$t->unsignedInteger('stock_before')->nullable();$t->unsignedInteger('stock_after')->nullable();$t->unsignedInteger('units')->nullable();$t->string('stock_evidence',40)->nullable();});
    }
    public function down(): void {
        Schema::table('offer_events',fn(Blueprint $t)=>$t->dropColumn(['stock_before','stock_after','units','stock_evidence']));
        Schema::table('offers',fn(Blueprint $t)=>$t->dropColumn('stock_quantity_checked_at'));
        foreach(['offers','offer_observations'] as $table)Schema::table($table,fn(Blueprint $t)=>$t->dropColumn(['stock_quantity','stock_quantity_source']));
    }
};
