<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('stores', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('url'); $t->string('platform');
            $t->string('currency',3)->default('CLP'); $t->string('default_condition')->default('unknown');
            $t->unsignedInteger('interval_hours')->default(48); $t->boolean('active')->default(true);
            $t->timestamp('last_attempt_at')->nullable(); $t->timestamp('last_success_at')->nullable(); $t->timestamps();
        });
        Schema::create('scrape_runs', function (Blueprint $t) {
            $t->id(); $t->foreignId('store_id')->constrained(); $t->string('status')->default('queued');
            $t->unsignedInteger('imported')->default(0); $t->text('message')->nullable();
            $t->timestamp('finished_at')->nullable(); $t->timestamps();
        });
        Schema::create('albums', function (Blueprint $t) {
            $t->id(); $t->string('artist')->nullable(); $t->string('title'); $t->string('match_key',64)->unique(); $t->timestamps();
        });
        Schema::create('offers', function (Blueprint $t) {
            $t->id(); $t->foreignId('store_id')->constrained(); $t->foreignId('album_id')->nullable()->constrained();
            $t->string('external_id'); $t->string('title',1000); $t->text('description')->nullable();
            $t->text('url'); $t->text('image')->nullable(); $t->decimal('price',14,2)->nullable();
            $t->string('currency',3); $t->string('format')->default('unknown'); $t->string('condition')->default('unknown');
            $t->string('availability')->default('unknown'); $t->boolean('reviewed')->default(false);
            $t->timestamp('checked_at')->nullable(); $t->timestamp('missing_since')->nullable(); $t->timestamps();
            $t->unique(['store_id','external_id']);
        });
        Schema::create('offer_observations', function (Blueprint $t) {
            $t->id(); $t->foreignId('offer_id')->constrained(); $t->decimal('price',14,2)->nullable();
            $t->string('availability'); $t->timestamp('created_at');
        });
    }
    public function down(): void {
        foreach (['offer_observations','offers','albums','scrape_runs','stores'] as $table) Schema::dropIfExists($table);
    }
};
