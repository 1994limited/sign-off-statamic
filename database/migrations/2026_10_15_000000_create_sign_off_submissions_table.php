<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('sign-off.drivers.database.connection') ?: parent::getConnection();
    }

    public function up(): void
    {
        Schema::create(config('sign-off.drivers.database.table', 'sign_off_submissions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entry_id')->index();
            $table->string('collection')->index();
            $table->string('site')->default('');
            $table->string('state')->index();
            $table->string('submitted_by')->nullable();
            $table->timestamp('submitted_at', 6)->nullable();
            $table->text('note')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->json('steps');
            $table->json('history');
            $table->json('snapshot')->nullable();
            $table->timestamp('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('sign-off.drivers.database.table', 'sign_off_submissions'));
    }
};
