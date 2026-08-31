<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            
            // 🎯 LINKED: User relationship
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->string('bank_name');
            $table->string('account_role')->nullable();
            $table->decimal('current_balance', 10, 2)->default(0);
            $table->decimal('mab_required', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};