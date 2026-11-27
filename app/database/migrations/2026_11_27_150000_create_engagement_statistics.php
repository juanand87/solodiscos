<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        Schema::create('engagement_state',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->timestamp('started_at');});
        DB::table('engagement_state')->insert(['id'=>1,'started_at'=>now()]);
        Schema::create('engagement_days',function(Blueprint $t){
            $t->id();$t->string('metric_key',64)->unique();$t->date('day');$t->string('kind',20);
            $t->foreignId('album_id')->constrained()->cascadeOnDelete();$t->string('format',10);
            $t->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();$t->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('total')->default(0);$t->index(['store_id','day','kind']);$t->index(['album_id','format','day']);$t->index(['day','kind']);
        });
    }
    public function down(): void {Schema::dropIfExists('engagement_days');Schema::dropIfExists('engagement_state');}
};
