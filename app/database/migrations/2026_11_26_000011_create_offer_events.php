<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('offer_observations',function(Blueprint $t){
            $t->string('currency',3)->nullable();$t->timestamp('previous_checked_at')->nullable();
        });
        Schema::create('offer_events',function(Blueprint $t){
            $t->id();$t->string('event_key',100)->unique();$t->foreignId('offer_id')->constrained();$t->foreignId('store_id')->constrained();
            $t->string('type',24);$t->timestamp('occurred_at');$t->timestamp('previous_checked_at')->nullable();
            $t->decimal('previous_price',14,2)->nullable();$t->decimal('price',14,2)->nullable();$t->string('currency',3)->nullable();
            $t->string('previous_availability')->nullable();$t->string('availability');
            $t->boolean('initial_load')->default(false);$t->boolean('historical')->default(false);
            $t->index(['occurred_at','type']);$t->index(['store_id','occurred_at']);$t->index('offer_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('offer_events');
        Schema::table('offer_observations',fn(Blueprint $t)=>$t->dropColumn(['currency','previous_checked_at']));
    }
};
