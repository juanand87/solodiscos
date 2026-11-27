<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('offers', fn (Blueprint $table) => $table->mediumText('description')->nullable()->change());
    }

    public function down(): void
    {
        // Keep the larger column on rollback to avoid truncating imported tracklists.
    }
};
