<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('cover_fingerprints',function(Blueprint $t) {
            $t->id();$t->char('url_hash',64)->unique();$t->text('url');$t->string('algorithm',40);$t->string('status',20);
            $t->char('phash',16)->nullable();$t->char('content_hash',64)->nullable();$t->unsignedInteger('width')->nullable();$t->unsignedInteger('height')->nullable();
            $t->text('error')->nullable();$t->timestamp('checked_at')->nullable();$t->timestamps();
        });
        Schema::create('cover_scans',function(Blueprint $t) {
            $t->id();$t->string('status',20)->default('queued');$t->string('artist')->nullable();$t->unsignedInteger('image_limit')->default(100);
            $t->unsignedInteger('processed')->default(0);$t->unsignedInteger('downloaded')->default(0);$t->unsignedInteger('suggestions')->default(0);$t->unsignedInteger('errors')->default(0);
            $t->text('message')->nullable();$t->timestamp('started_at')->nullable();$t->timestamp('heartbeat_at')->nullable();$t->timestamp('finished_at')->nullable();$t->timestamps();
        });
        Schema::create('cover_matches',function(Blueprint $t) {
            $t->id();$t->char('signature',64)->unique();$t->foreignId('left_offer_id')->constrained('offers');$t->foreignId('right_offer_id')->constrained('offers');
            $t->foreignId('left_cover_id')->constrained('cover_fingerprints');$t->foreignId('right_cover_id')->constrained('cover_fingerprints');
            $t->foreignId('left_album_id')->constrained('albums');$t->foreignId('right_album_id')->constrained('albums');
            $t->unsignedTinyInteger('distance');$t->decimal('title_similarity',5,4);$t->string('format',10);$t->string('status',20)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');$t->timestamp('reviewed_at')->nullable();$t->json('changes')->nullable();$t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('cover_matches');Schema::dropIfExists('cover_scans');Schema::dropIfExists('cover_fingerprints'); }
};
