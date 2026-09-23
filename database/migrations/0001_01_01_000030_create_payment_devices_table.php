<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // App\Enums\PaymentDeviceKind: which payment methods this device may be named against.
            $table->string('kind', 32);
            // Staff-facing, not translated: "Counter machine 1", "Reception QR". No guest ever reads this.
            $table->string('name', 64);
            // Terminal ID or merchant VPA, for reconciling against the bank or the UPI app.
            $table->string('identifier', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_devices');
    }
};
