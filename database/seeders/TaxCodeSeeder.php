<?php

namespace Database\Seeders;

use App\Models\TaxCode;
use Illuminate\Database\Seeder;

/**
 * The starting catalogue of HSN and SAC codes every tenant is offered.
 *
 * **These rates are a starting point a tenant confirms, not tax policy this
 * application asserts.** The standing instruction that no tax information is
 * hardcoded still holds where it was aimed: nothing here is applied to anything
 * on its own. A rate reaches a bill only once someone picks the code on an item
 * and saves it, and the item's own rate stays editable afterwards — so a row
 * that is out of date is corrected on the item, in the panel, without a deploy.
 *
 * That is also why these are rows and not an enum. `App\Enums\TaxRate` was
 * tried and reverted (`.ai/rules/enums.md`) because GST 2.0 restructured the
 * slabs on 22 September 2025 and a list in code was wrong the day it shipped.
 * A row can be edited; a case has to be deployed.
 *
 * Accommodation and restaurant service both turn on the room tariff, which is
 * why each is listed twice rather than averaged into one line a tenant would
 * have to know to correct.
 */
class TaxCodeSeeder extends Seeder
{
    /**
     * @var list<array{code: string, description: string, tax_rate: int}>
     */
    private const array CATALOGUE = [
        ['code' => '996311', 'description' => 'Room or unit accommodation — tariff up to ₹7,500 a day', 'tax_rate' => 500],
        ['code' => '996311', 'description' => 'Room or unit accommodation — tariff above ₹7,500 a day', 'tax_rate' => 1800],
        ['code' => '996331', 'description' => 'Restaurant service — standalone outlet', 'tax_rate' => 500],
        ['code' => '996331', 'description' => 'Restaurant service — in a hotel with a room above ₹7,500 a day', 'tax_rate' => 1800],
        ['code' => '996332', 'description' => 'Room service and in-room dining', 'tax_rate' => 500],
        ['code' => '996337', 'description' => 'Outdoor catering', 'tax_rate' => 500],
        ['code' => '999799', 'description' => 'Other services not classified elsewhere', 'tax_rate' => 1800],
        ['code' => '2201', 'description' => 'Packaged drinking water', 'tax_rate' => 500],
        ['code' => '2202', 'description' => 'Aerated and flavoured drinks', 'tax_rate' => 4000],
        ['code' => '1905', 'description' => 'Biscuits, rusks and baked goods', 'tax_rate' => 500],
        ['code' => '3401', 'description' => 'Soap and toilet preparations', 'tax_rate' => 500],
        ['code' => '6302', 'description' => 'Bed and table linen', 'tax_rate' => 500],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $entry) {
            TaxCode::query()->firstOrCreate(
                ['tenant_id' => null, 'code' => $entry['code'], 'description' => $entry['description']],
                ['tax_rate' => $entry['tax_rate']],
            );
        }
    }
}
