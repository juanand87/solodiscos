<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('store_orders',function(Blueprint $t){
            // NULL means the older plugin never supplied attribution, not a direct sale.
            $t->boolean('attributed_to_solodiscos')->nullable();
            $t->index(['store_id','attributed_to_solodiscos','ordered_at'],'store_orders_attribution_date');
        });
    }
    public function down(): void {
        Schema::table('store_orders',function(Blueprint $t){$t->dropIndex('store_orders_attribution_date');$t->dropColumn('attributed_to_solodiscos');});
    }
};
