<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete(); // one review per stay
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('overall');
            $table->unsignedTinyInteger('cleanliness');
            $table->unsignedTinyInteger('location');
            $table->unsignedTinyInteger('service');
            $table->unsignedTinyInteger('value');
            $table->string('title', 150);
            $table->text('comment');
            $table->json('images')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected | hidden
            $table->string('moderation_note')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['hotel_id', 'status']);
        });
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT chk_review_scores CHECK (overall BETWEEN 1 AND 5 AND cleanliness BETWEEN 1 AND 5 AND location BETWEEN 1 AND 5 AND service BETWEEN 1 AND 5 AND value BETWEEN 1 AND 5)');

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_hotels', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'hotel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_hotels');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('reviews');
    }
};
