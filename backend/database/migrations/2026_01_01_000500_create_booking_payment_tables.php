<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_tour_packages', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tour_package_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'tour_package_id']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 10)->index();                  // hotel | tour
            $table->string('booking_type', 20)->default('regular'); // membership | regular (hotel bookings)
            $table->foreignId('hotel_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_trip_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('check_in')->nullable()->index();   // hotel: check-in, tour: trip start
            $table->date('check_out')->nullable();           // hotel: check-out, tour: trip end
            $table->unsignedSmallInteger('nights')->default(0);
            $table->unsignedTinyInteger('rooms_count')->default(0);
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            // pending | payment_pending | confirmed | cancelled | completed | failed
            $table->string('status', 20)->default('pending')->index();
            // pending | processing | paid | failed | refunded | partially_refunded
            $table->string('payment_status', 20)->default('pending')->index();
            $table->string('currency', 3)->default('INR');
            $table->decimal('room_amount', 12, 2)->default(0);          // hotel stay value (or itinerary subtotal for tours)
            $table->unsignedSmallInteger('membership_nights_used')->default(0);
            $table->decimal('membership_covered_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);             // amount after membership coverage, before tax/fees/discount
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_refunded', 12, 2)->default(0);
            $table->json('price_breakdown')->nullable();
            $table->json('itinerary_snapshot')->nullable();            // frozen trip for tour bookings
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone', 20);
            $table->text('special_requests')->nullable();
            $table->timestamp('hold_expires_at')->nullable()->index(); // inventory hold for unpaid bookings
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
            $table->index(['hotel_id', 'check_in']);
        });
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT chk_booking_amounts CHECK (total_amount >= 0 AND amount_paid >= 0 AND amount_refunded >= 0 AND amount_refunded <= amount_paid AND discount_amount >= 0)');

        // Inventory reserved by a booking. Tour bookings may hold several hotels with their own dates.
        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotel_room_id')->constrained()->restrictOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('quantity');
            $table->unsignedSmallInteger('nights');
            $table->json('nightly_rates');           // {"2026-12-01": 6500, ...} per room
            $table->decimal('amount', 12, 2);         // quantity * sum(nightly_rates)
            $table->boolean('inventory_released')->default(false);
            $table->timestamps();
        });

        Schema::create('booking_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('guest_type', 10)->default('adult');
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('booking_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete(); // one cancellation per booking
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_role', 20)->default('customer');
            $table->string('reason')->nullable();
            $table->boolean('within_free_window')->default(true);
            $table->decimal('penalty_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('nights_returned')->default(0);
            $table->unsignedSmallInteger('nights_forfeited')->default(0);
            $table->timestamps();
        });

        // Ledger. Never updated or deleted — corrections are new rows.
        Schema::create('membership_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            // membership_purchase | booking_deduction | booking_cancellation_refund | manual_adjustment | expiration | admin_adjustment
            $table->string('type', 40)->index();
            $table->integer('nights'); // signed: + credit / - debit
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Guarantees idempotency: e.g. "booking:42:deduct" / "booking:42:refund" can only exist once.
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['membership_id', 'id']);
        });
        DB::statement('ALTER TABLE membership_transactions ADD CONSTRAINT chk_ledger_math CHECK (CAST(balance_before AS SIGNED) + nights = CAST(balance_after AS SIGNED) AND nights <> 0)');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();     // our id, e.g. PAY-8F2K...
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->morphs('payable');                      // Booking | Membership
            $table->string('purpose', 30)->default('booking'); // booking | membership | additional
            $table->string('gateway', 20);                  // razorpay | stripe | sandbox
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('method', 40)->nullable();       // card, upi, netbanking... (never card data)
            // pending | processing | paid | failed | refunded | partially_refunded
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('amount_refunded', 12, 2)->default(0);
            $table->string('failure_reason')->nullable();
            $table->json('gateway_metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'gateway_payment_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('pending')->index(); // pending | processed | failed
            $table->string('gateway_refund_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('gateway_metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_id');
            $table->string('event_type', 100);
            $table->json('payload');
            $table->string('status', 20)->default('received'); // received | processed | ignored | failed
            $table->string('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'event_id']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->decimal('discount_amount', 12, 2);
            $table->string('status', 20)->default('reserved')->index(); // reserved | applied | released
            $table->timestamps();
            $table->index(['coupon_id', 'user_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->morphs('invoiceable'); // Booking | Membership
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('issued_at');
            $table->json('billing_details');
            $table->json('items');
            $table->string('currency', 3)->default('INR');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('payment_status', 20);
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['invoices', 'coupon_usages', 'payment_webhook_events', 'refunds', 'payments', 'membership_transactions', 'booking_cancellations', 'booking_guests', 'booking_rooms', 'bookings', 'coupon_tour_packages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
