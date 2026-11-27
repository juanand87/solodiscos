<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {Schema::table('offer_observations',fn(Blueprint $t)=>$t->index(['offer_id','currency','created_at','id'],'observations_price_history_index'));}
    public function down(): void {Schema::table('offer_observations',fn(Blueprint $t)=>$t->dropIndex('observations_price_history_index'));}
};
