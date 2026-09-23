<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // App\Enums\PaymentMethod.
            $table->string('method', 32);
            // The machine or QR code this was taken on, when the method names one — App\Enums\PaymentMethod::deviceKind().
            $table->foreignId('payment_device_id')->nullable()->constrained()->nullOnDelete();
            // Minor units, the whole transaction — before it is spread across the orders it settles.
            $table->integer('amount');
            // Transaction / UTR / approval number, when the method takes one — PaymentMethod::takesReference().
            $table->string('reference', 64)->nullable();
            $table->string('note', 200)->nullable();
            // Who took it from the panel; null for a write that goes around it.
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            // A payment is never hard-deleted, only voided — money deserves that more than a menu item does.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
