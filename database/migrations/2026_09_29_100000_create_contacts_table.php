<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->date('birthday')->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_thumb_path')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'last_name', 'first_name']);
            $table->index(['user_id', 'is_favorite']);
        });

        Schema::create('contact_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->default('mobile');
            $table->string('number', 50);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('contact_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->default('home');
            $table->string('email');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('contact_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->default('homepage');
            $table->string('url', 2048);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('contact_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->default('home');
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_addresses');
        Schema::dropIfExists('contact_urls');
        Schema::dropIfExists('contact_emails');
        Schema::dropIfExists('contact_phone_numbers');
        Schema::dropIfExists('contacts');
    }
};
