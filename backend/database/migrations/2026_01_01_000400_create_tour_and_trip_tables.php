<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            // sightseeing, adventure, museum, theme_park, safari, cruise, trekking, water_sports, cultural, attraction
            $table->string('category', 30)->index();
            $table->string('location');
            $table->string('short_description', 300)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->json('start_times')->nullable();     // ["09:00","14:00"]
            $table->json('available_days')->nullable();  // [0..6], null = every day
            $table->unsignedInteger('capacity_per_slot')->default(20);
            $table->decimal('price', 12, 2);              // per adult
            $table->decimal('child_price', 12, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->json('gallery')->nullable();
            $table->json('inclusions')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tour_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('languages');
            $table->text('bio')->nullable();
            $table->string('specialty')->nullable();
            $table->unsignedTinyInteger('experience_years')->default(1);
            $table->time('available_from')->default('08:00:00');
            $table->time('available_to')->default('20:00:00');
            $table->json('available_days')->nullable();
            $table->unsignedTinyInteger('min_hours')->default(2);
            $table->unsignedTinyInteger('max_hours')->default(10);
            $table->decimal('hourly_rate', 12, 2);
            $table->decimal('full_day_rate', 12, 2)->nullable(); // applied when hours >= 8
            $table->string('profile_image')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tour_transportations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
            // flight, train, bus, taxi, private_car, rental_car, airport_transfer, hotel_transfer
            $table->string('type', 30)->index();
            $table->string('name');
            $table->string('provider');
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->string('pricing_unit', 20)->default('per_person'); // per_person | per_vehicle | per_day
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('capacity')->default(4); // seats per vehicle (per_vehicle/per_day)
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->json('departure_times')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tour_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('meal_type', 20); // breakfast | lunch | dinner | experience
            $table->string('venue');
            $table->string('cuisine')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2); // per person
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tour_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('summary', 400);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('duration_days');
            $table->unsignedTinyInteger('duration_nights');
            $table->string('cities')->nullable(); // "Dubai · Abu Dhabi"
            $table->unsignedTinyInteger('default_adults')->default(2);
            $table->unsignedTinyInteger('max_travelers')->default(12);
            $table->decimal('price_from', 12, 2)->default(0); // per person, recalculated from items
            $table->string('image_path')->nullable();
            $table->json('gallery')->nullable();
            $table->json('highlights')->nullable();
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->string('difficulty', 20)->default('easy');
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->boolean('is_featured')->default(false)->index();
            $table->string('status', 20)->default('active')->index();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tour_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_package_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_number');
            $table->string('day_title')->nullable();
            $table->string('item_type', 30);
            $table->nullableMorphs('itemable');
            $table->string('title');
            $table->text('description')->nullable();
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedTinyInteger('nights')->nullable();
            $table->unsignedTinyInteger('hours')->nullable();
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tour_package_id', 'day_number', 'sort_order'], 'pkg_items_order_idx');
        });

        Schema::create('user_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('tour_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('destination_summary')->nullable();
            $table->date('start_date')->nullable();
            $table->unsignedTinyInteger('adults')->default(2);
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('status', 20)->default('draft')->index(); // draft | booked | cancelled
            $table->string('visibility', 20)->default('private');    // private | public
            $table->string('share_token', 64)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('currency', 3)->default('INR');
            // Server-computed price cache (never accepted from the client)
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->json('price_breakdown')->nullable();
            $table->timestamp('priced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('trip_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_trip_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_number');
            $table->string('title')->nullable();
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['user_trip_id', 'day_number']);
        });

        Schema::create('trip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_day_id')->constrained()->cascadeOnDelete();
            // accommodation, flight, train, bus, taxi, private_car, rental_car, airport_transfer,
            // hotel_transfer, activity, attraction, guide, meal, other
            $table->string('item_type', 30)->index();
            $table->nullableMorphs('itemable');
            $table->string('title');
            $table->string('provider')->nullable();
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);  // people / vehicles / rooms
            $table->unsignedTinyInteger('nights')->nullable();     // accommodation
            $table->unsignedTinyInteger('hours')->nullable();      // guides
            $table->string('meal_plan', 30)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);     // server computed
            $table->decimal('total_price', 12, 2)->default(0);    // server computed
            $table->boolean('is_available')->default(true);
            $table->string('availability_note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['trip_day_id', 'sort_order']);
        });

        Schema::create('trip_travelers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_trip_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('traveler_type', 10)->default('adult'); // adult | child
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_lead')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['trip_travelers', 'trip_items', 'trip_days', 'user_trips', 'tour_package_items', 'tour_packages', 'tour_meals', 'tour_transportations', 'tour_guides', 'tour_activities'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
