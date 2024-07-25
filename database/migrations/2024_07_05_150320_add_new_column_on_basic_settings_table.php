<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('basic_settings', function (Blueprint $table) {
            $table->string('agent_sms_verification')->nullable()->after('agent_email_verification');
            $table->string('agent_sms_notification')->nullable()->after('agent_email_notification');

            $table->string('merchant_sms_verification')->nullable()->after('merchant_email_verification');
            $table->string('merchant_sms_notification')->nullable()->after('merchant_email_notification');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
