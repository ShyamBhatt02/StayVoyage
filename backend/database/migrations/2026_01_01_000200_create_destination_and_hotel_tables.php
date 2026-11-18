<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('country', 100);
            $table->string('state', 100)->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->json('gallery')->nullable();
            $table->json('highlights')->nullable();
            $table->string('best_time_to_visit')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_featured')->default(false)->index();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon', 50)->nullable();
            $table->string('scope', 20)->default('both')->index(); // hotel | room | both
            $table->timestamps();
        });

        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('property_type', 30)->default('hotel')->index();
            $table->unsignedTinyInteger('star_rating')->default(3)->index();
            $table->string('short_description', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('address');
            $table->string('city', 100)->index();
            $table->string('state', 100)->nullable();
            $table->string('country', 100);
            $table->string('postal_code', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('distance_from_center_km', 6, 2)->nullable();
            $table->time('check_in_time')->default('14:00:00');
            $table->time('check_out_time')->default('11:00:00');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0)->index();
            $table->unsignedInteger('review_count')->default(0);
            $table->decimal('min_price', 12, 2)->nullable()->index(); // denormalised "from" price
            $table->boolean('is_membership_eligible')->default(false)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->string('status', 20)->default('active')->index(); // active | inactive | draft
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hotel_user', function (Blueprint $table) {
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['hotel_id', 'user_id']);
        });

        Schema::create('hotel_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hotel_amenities', function (Blueprint $table) {
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['hotel_id', 'amenity_id']);
        });

        Schema::create('hotel_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('cancellation_policy')->nullable();
            $table->text('children_policy')->nullable();
            $table->text('pet_policy')->nullable();
            $table->text('smoking_policy')->nullable();
            $table->text('extra_policies')->nullable();
            $table->boolean('pets_allowed')->default(false);
            $table->boolean('smoking_allowed')->default(false);
            $table->timestamps();
        });

        Schema::create('hotel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('room_type', 30)->index(); // standard, deluxe, premium, suite, family, villa
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('max_guests');
            $table->unsignedTinyInteger('max_adults');
            $table->unsignedTinyInteger('max_children')->default(0);
            $table->string('bed_type', 50);
            $table->unsignedInteger('size_sqft')->nullable();
            $table->unsignedInteger('total_rooms');
            $table->decimal('base_price', 12, 2);
            $table->decimal('weekend_price', 12, 2)->nullable();
            $table->boolean('breakfast_included')->default(false);
            $table->boolean('free_cancellation')->default(true);
            $table->unsignedInteger('free_cancellation_hours')->default(48);
            $table->text('cancellation_policy')->nullable();
            $table->boolean('is_membership_eligible')->default(false)->index();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('ALTER TABLE hotel_rooms ADD CONSTRAINT chk_room_prices CHECK (base_price >= 0 AND (weekend_price IS NULL OR weekend_price >= 0))');

        Schema::create('hotel_room_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_room_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hotel_room_amenities', function (Blueprint $table) {
            $table->foreignId('hotel_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['hotel_room_id', 'amenity_id']);
        });

        Schema::create('hotel_room_seasonal_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_room_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('price', 12, 2);
            $table->decimal('weekend_price', 12, 2)->nullable();
            $table->timestamps();
            $table->index(['hotel_room_id', 'start_date', 'end_date'], 'seasonal_room_dates_idx');
        });

        // One row per room type per date. Rows are created lazily and locked FOR UPDATE while booking.
        Schema::create('hotel_room_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_room_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('total_inventory');
            $table->unsignedInteger('booked_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->decimal('price_override', 12, 2)->nullable();
            $table->boolean('is_closed')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['hotel_room_id', 'date']);
            $table->index('date');
        });
        // Database-level guard: inventory can never be oversold, whatever the application does.
        DB::statement('ALTER TABLE hotel_room_availability ADD CONSTRAINT chk_inventory_not_oversold CHECK (booked_count + blocked_count <= total_inventory)');
    }

    public function down(): void
    {
        foreach (['hotel_room_availability', 'hotel_room_seasonal_prices', 'hotel_room_amenities', 'hotel_room_images', 'hotel_rooms', 'hotel_policies', 'hotel_amenities', 'hotel_images', 'hotel_user', 'hotels', 'amenities', 'destinations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
