<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beam_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();

            // A push endpoint is a URL with no useful upper bound, and one device
            // owns exactly one of them — so it is stored unindexed and identified
            // by its SHA-256. A unique index on the URL itself is what a first
            // migration cannot survive: varchar(1000) in utf8mb4 is 4000 bytes and
            // InnoDB refuses any key over 3072 (error 1071). The hash is 64 ASCII
            // characters on every driver, and DatabaseStore matches on it.
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();

            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beam_push_subscriptions');
    }
};
