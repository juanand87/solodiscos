<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('stores',function(Blueprint $table){
            $table->dateTime('partner_plan_requested_at')->nullable();
            $table->foreignId('partner_plan_requested_by')->nullable()->constrained('store_accounts')->nullOnDelete();
            $table->string('partner_plan_requested_version',40)->nullable();
            $table->dateTime('commercial_agreement_terminated_at')->nullable();
            $table->foreignId('commercial_agreement_terminated_by')->nullable()->constrained('store_accounts')->nullOnDelete();
            $table->string('commercial_agreement_reference',255)->nullable();
            $table->date('commercial_agreement_signed_at')->nullable();
            $table->string('commercial_agreement_version',40)->nullable();
            $table->string('commercial_agreement_plugin_sha256',64)->nullable();
        });
        Schema::table('store_orders',function(Blueprint $table){
            $table->decimal('products_total',16,2)->nullable();
            $table->decimal('products_refunded',16,2)->nullable();
            $table->decimal('commission_base',16,2)->nullable();
        });
    }
    public function down(): void {
        Schema::table('store_orders',fn(Blueprint $table)=>$table->dropColumn(['products_total','products_refunded','commission_base']));
        Schema::table('stores',function(Blueprint $table){
            $table->dropConstrainedForeignId('partner_plan_requested_by');$table->dropConstrainedForeignId('commercial_agreement_terminated_by');
            $table->dropColumn(['partner_plan_requested_at','partner_plan_requested_version','commercial_agreement_terminated_at','commercial_agreement_reference','commercial_agreement_signed_at','commercial_agreement_version','commercial_agreement_plugin_sha256']);
        });
    }
};
