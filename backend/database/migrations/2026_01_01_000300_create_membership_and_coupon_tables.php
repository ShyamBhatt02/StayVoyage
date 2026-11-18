<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('included_nights');
            // Booking rules
            $table->unsignedInteger('max_nights_per_booking')->default(7);
            $table->unsignedInteger('max_rooms_per_booking')->default(1);
            $table->unsignedInteger('max_guests_per_booking')->default(2);
            $table->unsignedInteger('min_advance_booking_days')->default(0);
            $table->unsignedInteger('max_advance_booking_days')->default(90);
            $table->boolean('all_hotels_eligible')->default(true); // true = every membership-eligible hotel
            $table->json('eligible_room_types')->nullable();       // null = all room types
            // Cancellation / no-show / expiry rules
            $table->unsignedInteger('cancellation_deadline_hours')->default(24);
            $table->string('late_cancellation_policy', 30)->default('forfeit_nights'); // forfeit_nights | refund_nights
            $table->string('no_show_policy', 30)->default('forfeit_nights');           // forfeit_nights | refund_nights
            $table->boolean('unused_nights_expire')->default(true);
            $table->boolean('nights_extend_past_expiry')->default(false); // stay may continue past membership expiry if booked before
            $table->boolean('auto_renew_available')->default(true);
            $table->boolean('charge_taxes_on_covered_amount')->default(true);
            $table->json('features')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('membership_plan_hotels', function (Blueprint $table) {
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->primary(['membership_plan_id', 'hotel_id']);
        });

        Schema::create('membership_blackout_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['membership_plan_id', 'start_date', 'end_date'], 'blackout_plan_dates_idx');
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->string('membership_number')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index(); // pending | active | expired | cancelled
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('total_nights');
            // Cached balance. The membership_transactions ledger is the source of truth; this column is only
            // ever changed inside the same DB transaction that writes a ledger row, under a row lock.
            $table->unsignedInteger('nights_balance')->default(0);
            $table->decimal('price_paid', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->boolean('auto_renew')->default(false);
            $table->json('plan_snapshot'); // rules frozen at purchase time
            $table->foreignId('renewed_from_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('expiry_reminder_sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
        });
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT chk_nights_balance CHECK (nights_balance >= 0 AND nights_balance <= total_nights)');

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('discount_type', 20); // percentage | fixed
            $table->decimal('discount_value', 12, 2);
            $table->decimal('min_purchase', 12, 2)->default(0);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->string('applies_to', 20)->default('all'); // all | hotels | tours | memberships
            $table->boolean('is_public')->default(false); // shown on the Offers page
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT chk_coupon_values CHECK (discount_value > 0 AND (discount_type <> 'percentage' OR discount_value <= 100))");

        Schema::create('coupon_hotels', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'hotel_id']);
        });

        Schema::create('coupon_membership_plans', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'membership_plan_id']);
        });
    }

    public function down(): void
    {
        foreach (['coupon_membership_plans', 'coupon_hotels', 'coupons', 'memberships', 'membership_blackout_dates', 'membership_plan_hotels', 'membership_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
