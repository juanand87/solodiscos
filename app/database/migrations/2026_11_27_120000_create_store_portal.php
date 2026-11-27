<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('store_accounts',function(Blueprint $t){
            $t->id();$t->foreignId('store_id')->constrained()->cascadeOnDelete();$t->string('name',100);
            $t->string('email')->unique();$t->timestamp('email_verified_at')->nullable();$t->string('password');$t->rememberToken();$t->timestamps();
        });
        Schema::create('store_password_reset_tokens',function(Blueprint $t){$t->string('email')->primary();$t->string('token');$t->timestamp('created_at')->nullable();});
        Schema::create('store_corrections',function(Blueprint $t){
            $t->id();$t->foreignId('store_id')->constrained();$t->foreignId('store_account_id')->constrained();$t->foreignId('offer_id')->constrained();
            $t->string('field',30);$t->text('message');$t->string('status',20)->default('pending');$t->text('response')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');$t->timestamp('reviewed_at')->nullable();$t->timestamps();$t->index(['store_id','status']);
        });
        Schema::create('smtp_settings',function(Blueprint $t){
            $t->unsignedInteger('id')->primary();$t->boolean('enabled')->default(false);$t->string('host');$t->unsignedSmallInteger('port');
            $t->string('scheme',10);$t->string('username');$t->text('password');$t->string('from_address');$t->string('from_name');$t->timestamps();
        });
    }
    public function down(): void {
        foreach(['smtp_settings','store_corrections','store_password_reset_tokens','store_accounts'] as $table)Schema::dropIfExists($table);
    }
};
