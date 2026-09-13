<?php

use App\Models\TenantSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tax and the charges a restaurant applies to every order.
     *
     * The default GST rate lives here rather than on each dish, so a restaurant
     * sets 5% once and only the genuinely different lines — a sealed bottle
     * taxed as goods — override it. menu_items, menu_item_additions and
     * menu_combos each carry a nullable rate that falls back to this one.
     *
     * `prices_include_tax` decides which way the arithmetic runs, and it is a
     * real question rather than a preference: an Indian menu may print prices
     * with GST already inside them or add it on the bill, and the same ₹100
     * means a different amount of tax under each. Default false — tax added at
     * the bill — which is what the aggregators and most restaurants do, and
     * what the price breakdown in the brief describes.
     *
     * Service and parcel charges are each a pair: a switch and an amount. The
     * switch is what makes them optional rather than a zero, which matters for
     * the service charge in particular — the CCPA's 2022 guidelines make it
     * voluntary, so a restaurant that does not levy one should be able to say
     * so rather than set it to nothing. The service charge is a percentage of
     * the order (basis points, exactly like a tax rate); the parcel charge is a
     * flat amount in minor units added to a takeaway order.
     *
     * `gstin` is the restaurant's 15-character GST registration number, which
     * has to appear on every tax invoice it issues. Nullable: a restaurant
     * under the registration threshold has none.
     */
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table): void {
            $table->string('gstin', 15)->nullable()->after('currency');

            $table->unsignedSmallInteger('tax_rate_basis_points')
                ->default(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS)
                ->after('gstin');

            $table->boolean('prices_include_tax')->default(false)->after('tax_rate_basis_points');

            $table->boolean('service_charge_enabled')->default(false)->after('prices_include_tax');
            $table->unsignedSmallInteger('service_charge_basis_points')->default(0)->after('service_charge_enabled');

            $table->boolean('parcel_charge_enabled')->default(false)->after('service_charge_basis_points');
            $table->unsignedInteger('parcel_charge_minor_units')->default(0)->after('parcel_charge_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'gstin',
                'tax_rate_basis_points',
                'prices_include_tax',
                'service_charge_enabled',
                'service_charge_basis_points',
                'parcel_charge_enabled',
                'parcel_charge_minor_units',
            ]);
        });
    }
};
