<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('stores',fn(Blueprint $t)=>$t->unsignedInteger('shopify_collection')->default(0)); }
    public function down(): void { Schema::table('stores',fn(Blueprint $t)=>$t->dropColumn('shopify_collection')); }
};
