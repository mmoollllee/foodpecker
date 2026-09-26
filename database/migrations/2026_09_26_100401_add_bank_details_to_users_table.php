<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account a lead collects the payments on — shown with a GiroCode to
     * everybody who pays for a round they lead.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bank_account_holder', 70)->nullable()->after('household_size');
            $table->string('iban', 34)->nullable()->after('bank_account_holder');
            $table->string('bic', 11)->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bank_account_holder', 'iban', 'bic']);
        });
    }
};
