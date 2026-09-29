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
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('occurred_at');
            $table->string('title')->nullable();
            $table->text('note')->nullable();
            $table->string('mood', 20)->nullable();
            $table->text('thoughts')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->text('issues')->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'occurred_at']);
        });

        // An interaction can involve one or more contacts.
        Schema::create('contact_interaction', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->nullable();

            $table->primary(['contact_id', 'interaction_id']);
            $table->index('interaction_id');
        });

        // Outbound links of an interaction: services, shops or other people.
        Schema::create('interaction_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            // Denormalized owner, so exports can be queried without joins.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label');
            $table->string('url', 2048)->nullable();
            $table->string('url_host')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('linked_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'linked_contact_id']);
            $table->index(['user_id', 'url_host']);
            $table->index(['user_id', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interaction_links');
        Schema::dropIfExists('contact_interaction');
        Schema::dropIfExists('interactions');
    }
};
