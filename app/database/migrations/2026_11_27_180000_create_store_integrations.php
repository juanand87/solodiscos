<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if(!Schema::hasTable('store_integrations'))Schema::create('store_integrations',function(Blueprint $t){
            $t->id();$t->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('token_hash',64)->nullable()->unique();$t->string('domain');$t->timestamp('last_sync_at')->nullable();$t->timestamps();
        });
        Schema::create('store_orders',function(Blueprint $t){
            $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->string('external_id',64);
            $t->string('status',40);$t->string('currency',3);$t->dateTime('ordered_at');$t->dateTime('source_updated_at');
            $t->boolean('paid');$t->decimal('total',16,2);$t->decimal('refunded',16,2);$t->unsignedInteger('units');$t->unsignedInteger('refunded_units');$t->timestamps();
            $t->unique(['store_id','external_id']);$t->index(['store_id','ordered_at']);
        });
    }
    public function down(): void {Schema::dropIfExists('store_orders');Schema::dropIfExists('store_integrations');}
};
