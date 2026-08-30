<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        $table->string('bank_name');
        $table->string('account_role');
        $table->decimal('current_balance', 10, 2)->default(0);
        $table->decimal('mab_required', 10, 2)->default(0);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
