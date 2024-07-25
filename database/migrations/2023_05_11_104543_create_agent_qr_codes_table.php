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
        Schema::create('agent_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("agent_id");
            $table->string('sender_type')->comment('User')->nullable();
            $table->string('receiver_type')->nullable();
            $table->decimal('amount', 28, 8)->nullable();
            $table->string('qr_code')->nullable();
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_qr_codes');
    }
};
