<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('cover_scans',function(Blueprint $t){
            $t->timestamp('planned_at')->nullable();$t->unsignedInteger('total_pairs')->default(0);$t->unsignedInteger('total_images')->default(0);
            $t->unsignedInteger('compared_pairs')->default(0);$t->unsignedInteger('skipped_pairs')->default(0);
            $t->unsignedInteger('batch_cursor')->default(0);$t->unsignedInteger('batch_count')->default(0);
        });
        Schema::table('cover_matches',fn(Blueprint $t)=>$t->foreignId('cover_scan_id')->nullable()->constrained()->nullOnDelete());
        Schema::create('cover_scan_images',function(Blueprint $t){
            $t->id();$t->foreignId('cover_scan_id')->constrained()->cascadeOnDelete();$t->char('url_hash',64);$t->text('url');
            $t->string('status',16)->default('pending');$t->foreignId('cover_fingerprint_id')->nullable()->constrained();
            $t->boolean('downloaded')->default(false);$t->text('error')->nullable();$t->timestamps();
            $t->unique(['cover_scan_id','url_hash']);$t->index(['cover_scan_id','status']);
        });
        Schema::create('cover_scan_pairs',function(Blueprint $t){
            $t->id();$t->foreignId('cover_scan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('left_offer_id')->constrained('offers');$t->foreignId('right_offer_id')->constrained('offers');
            $t->foreignId('left_album_id')->constrained('albums');$t->foreignId('right_album_id')->constrained('albums');$t->string('format',10);
            $t->foreignId('left_image_id')->constrained('cover_scan_images');$t->foreignId('right_image_id')->constrained('cover_scan_images');
            $t->string('status',16)->default('pending');$t->timestamps();
            $t->unique(['cover_scan_id','left_offer_id','right_offer_id'],'cover_pairs_unique');$t->index(['cover_scan_id','status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('cover_scan_pairs');Schema::dropIfExists('cover_scan_images');
        Schema::table('cover_matches',fn(Blueprint $t)=>$t->dropConstrainedForeignId('cover_scan_id'));
        Schema::table('cover_scans',fn(Blueprint $t)=>$t->dropColumn(['planned_at','total_pairs','total_images','compared_pairs','skipped_pairs','batch_cursor','batch_count']));
    }
};
