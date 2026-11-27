<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('offer_price_days',function(Blueprint $t){
            $t->id();$t->foreignId('offer_id')->constrained();$t->date('day');$t->char('identity_key',64);
            $t->decimal('minimum_price',14,2)->nullable();$t->decimal('last_price',14,2)->nullable();$t->boolean('valid');
            $t->dateTime('first_checked_at');$t->dateTime('last_checked_at');$t->unique(['offer_id','day']);
        });
        Schema::create('offer_price_tracking',function(Blueprint $t){
            $t->foreignId('offer_id')->primary()->constrained();$t->char('identity_key',64);
            $t->decimal('last_price',14,2)->nullable();$t->dateTime('last_checked_at');$t->boolean('last_available');
            $t->string('status',12)->default('none');$t->decimal('reference_price',14,2)->nullable();
            $t->timestamp('reference_from')->nullable();$t->timestamp('reference_to')->nullable();
            $t->timestamp('started_at')->nullable();$t->timestamp('closed_at')->nullable();$t->string('close_reason')->nullable();
            $t->index(['status','last_checked_at']);
        });
    }
    public function down(): void {Schema::dropIfExists('offer_price_tracking');Schema::dropIfExists('offer_price_days');}
};
