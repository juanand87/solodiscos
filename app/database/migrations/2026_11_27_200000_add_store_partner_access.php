<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        Schema::table('stores',function(Blueprint $table){
            $table->boolean('commercial_agreement_active')->default(false);
            $table->dateTime('commercial_agreement_activated_at')->nullable();
            $table->foreignId('commercial_agreement_activated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('store_integrations',function(Blueprint $table){
            $table->dateTime('last_connected_at')->nullable();
            $table->string('connected_token_hash',64)->nullable();
        });
        DB::table('store_integrations')->whereNotNull('token_hash')->whereNotNull('last_sync_at')->update(['last_connected_at'=>DB::raw('last_sync_at'),'connected_token_hash'=>DB::raw('token_hash')]);
    }
    public function down(): void {
        Schema::table('store_integrations',fn(Blueprint $table)=>$table->dropColumn(['last_connected_at','connected_token_hash']));
        Schema::table('stores',function(Blueprint $table){$table->dropConstrainedForeignId('commercial_agreement_activated_by');$table->dropColumn(['commercial_agreement_active','commercial_agreement_activated_at']);});
    }
};
