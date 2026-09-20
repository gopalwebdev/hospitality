<?php

namespace Database\Seeders;

use App\Enums\ChargeCalculation;
use App\Enums\CountryCallingCode;
use App\Enums\Currency;
use App\Enums\Diet;
use App\Enums\GstTreatment;
use App\Enums\HomeRowLayout;
use App\Enums\HomeTileAction;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\MenuItemKind;
use App\Enums\MenuRailType;
use App\Enums\Role;
use App\Enums\TenantType;
use App\Enums\Weekday;
use App\Models\Charge;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\MenuAddOnGroup;
use App\Models\MenuAddOnOption;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\MenuItemAddOnGroup;
use App\Models\MenuRail;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * The seeded tenants, their settings, the people who run them, their menus and their charges.
 *
 * The owner address uses plus-addressing so every tenant gets a distinct
 * account while the sign-in codes all land in the same real inbox.
 */
class TenantSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The tenants to seed, keyed by the slug that becomes their subdomain.
     *
     * `menus` names the cards in CARDS each one gets, in the order its guests
     * read them: a restaurant and a hotel serve different things, and the
     * hotel is what shows Consumable, Goods and Service items sharing one menu.
     *
     * The GST fields make the three demonstrate every `GstTreatment` and both
     * `prices_include_tax` states, because a fresh install otherwise shows
     * "GST 0%" everywhere and neither tax display path is visible:
     * - **Spice Garden** (Chennai, Tamil Nadu) is the plain case — intra-state,
     *   5% the way a standalone restaurant usually is, prices quoted before
     *   tax the way a printed menu usually is.
     * - **Seaview Residency** sits in Puducherry, a union territory, so its
     *   own statement is `UnionTerritory` (CGST + UTGST, the same money as
     *   SGST under a different name — `.ai/rules/enums.md`) rather than
     *   picked for variety's sake. Its rate is 18%, the hotel-tariff slab, and
     *   its prices already include it, the way an in-room rate card usually
     *   quotes.
     * - **Sunrise Multispecialty Hospital** rounds out `TenantType` — hospital
     *   was wholly unseeded — and states `InterState`, so an order there
     *   shows one IGST line rather than a CGST/SGST split. It also switches
     *   `tax_overrides_item_rates` on, so every line on its bill is taxed at
     *   its own 5% however an item's own `tax_rate` reads — the one seeded
     *   tenant demonstrating that override.
     *
     * @var list<array{slug: string, name: string, type: TenantType, address: string, pincode: string, email: string, phone: string, owner_name: string, owner_email: string, staff_name: string, staff_email: string, menus: list<string>, gstin: string, gst_treatment: GstTreatment, cgst_rate: int, sgst_rate: int, tax_overrides_item_rates: bool, prices_include_tax: bool}>
     */
    public const array TENANTS = [
        [
            'slug' => 'spice',
            'type' => TenantType::Restaurant,
            'name' => 'Spice Garden',
            'address' => '12 Mount Road, Chennai',
            'pincode' => '600002',
            'email' => 'hello@spicegarden.example.com',
            'phone' => '9876543210',
            'owner_name' => 'Spice Garden Owner',
            'owner_email' => 'gopalwebdev+spice@gmail.com',
            'staff_name' => 'Spice Garden Staff',
            'staff_email' => 'gopalwebdev+spice-staff@gmail.com',
            'menus' => ['main', 'drinks', 'breakfast'],
            // Tamil Nadu (state code 33). Intra-state, 5%, quoted before tax.
            'gstin' => '33AAACS1429K1Z1',
            'gst_treatment' => GstTreatment::IntraState,
            'cgst_rate' => 250,
            'sgst_rate' => 250,
            'tax_overrides_item_rates' => false,
            'prices_include_tax' => false,
        ],
        [
            'slug' => 'seaview',
            'type' => TenantType::Hotel,
            'name' => 'Seaview Residency',
            'address' => '4 Beach Road, Puducherry',
            'pincode' => '605001',
            'email' => 'hello@seaview.example.com',
            'phone' => '9876501234',
            'owner_name' => 'Seaview Residency Owner',
            'owner_email' => 'gopalwebdev+seaview@gmail.com',
            'staff_name' => 'Seaview Residency Staff',
            'staff_email' => 'gopalwebdev+seaview-staff@gmail.com',
            'menus' => ['in_room_dining', 'breakfast', 'room_requests'],
            // Puducherry (state code 34), a union territory with no
            // legislature — CGST + UTGST, riding the SGST columns. 18%, the
            // hotel-tariff slab, already inside the rate card's prices.
            'gstin' => '34AAACS5821H1Z7',
            'gst_treatment' => GstTreatment::UnionTerritory,
            'cgst_rate' => 900,
            'sgst_rate' => 900,
            'tax_overrides_item_rates' => false,
            'prices_include_tax' => true,
        ],
        [
            'slug' => 'sunrise',
            'type' => TenantType::Hospital,
            'name' => 'Sunrise Multispecialty Hospital',
            'address' => '45 Residency Road, Bengaluru',
            'pincode' => '560025',
            'email' => 'hello@sunrisehospital.example.com',
            'phone' => '9945012345',
            'owner_name' => 'Sunrise Hospital Owner',
            'owner_email' => 'gopalwebdev+sunrise@gmail.com',
            'staff_name' => 'Sunrise Hospital Staff',
            'staff_email' => 'gopalwebdev+sunrise-staff@gmail.com',
            'menus' => ['patient_care'],
            // Karnataka (state code 29). Its own statement is inter-state —
            // one IGST line, no CGST/SGST split — and it taxes its whole bill
            // at this rate whatever an item's own tax_rate says.
            'gstin' => '29AAACS7734L1Z4',
            'gst_treatment' => GstTreatment::InterState,
            'cgst_rate' => 250,
            'sgst_rate' => 250,
            'tax_overrides_item_rates' => true,
            'prices_include_tax' => false,
        ],
    ];

    /**
     * Every card a tenant can be seeded with, keyed by the name TENANTS uses.
     *
     * Each is a menu's name in both languages, its sections, whether the combos
     * are seeded onto it, an optional service window, and where its rails —
     * its featured items, its combos — are placed among its categories.
     *
     * `rails` is deliberately arranged differently from one card to the next,
     * so `Menu::readingOrder()` is exercised beyond the "nobody has arranged
     * this" default it falls back to when a rail has no row at all: leading
     * on one card, sitting mid-card on another, closing a third. See
     * `seedRails()`.
     *
     * @var array<string, array{name: array<string, string>, sections: list<array<string, mixed>>, combos?: bool, available_from?: string, available_until?: string, rails: list<array{type: MenuRailType, position: int}>}>
     */
    public const array CARDS = [
        'main' => [
            'name' => self::MENU_NAME,
            'sections' => self::MENU,
            'combos' => true,
            // Untouched at the top, the way a menu nobody has dragged reads
            // anyway — and the combos rail pulled into the middle of the
            // card, ahead of Curries, so the two cards sharing this content
            // (this one and in_room_dining below) read differently.
            'rails' => [
                ['type' => MenuRailType::Featured, 'position' => 0],
                ['type' => MenuRailType::Combos, 'position' => 3],
            ],
        ],
        'in_room_dining' => [
            'name' => self::IN_ROOM_DINING_MENU_NAME,
            'sections' => self::MENU,
            'combos' => true,
            // The opposite arrangement: combos mid-card, featured items
            // closing it out after Desserts.
            'rails' => [
                ['type' => MenuRailType::Combos, 'position' => 5],
                ['type' => MenuRailType::Featured, 'position' => 6],
            ],
        ],
        'drinks' => [
            'name' => self::DRINKS_MENU_NAME,
            'sections' => self::DRINKS,
            // Between Hot and Cold.
            'rails' => [['type' => MenuRailType::Featured, 'position' => 1]],
        ],
        'breakfast' => [
            'name' => self::BREAKFAST_MENU_NAME,
            'sections' => self::BREAKFAST,
            'available_from' => self::BREAKFAST_FROM,
            'available_until' => self::BREAKFAST_UNTIL,
            // Between Tiffin and Egg Specials.
            'rails' => [['type' => MenuRailType::Featured, 'position' => 1]],
        ],
        'room_requests' => [
            'name' => self::ROOM_REQUESTS_MENU_NAME,
            'sections' => self::ROOM_REQUESTS,
            // Closing the card, after Bathroom and Drinks.
            'rails' => [['type' => MenuRailType::Featured, 'position' => 2]],
        ],
        'patient_care' => [
            'name' => self::PATIENT_CARE_MENU_NAME,
            'sections' => self::PATIENT_CARE,
            // Between Meals and Requests.
            'rails' => [['type' => MenuRailType::Featured, 'position' => 1]],
        ],
    ];

    /**
     * What each tenant adds to a bill, keyed by the slug in TENANTS.
     *
     * A charge with a rate is a share of the bill and one with an amount is a
     * fixed sum. `menus` names the cards in CARDS it is limited to; without it
     * the charge is on every menu. The restaurant's service charge is on
     * everything and its packing charge only on the main card; the hotel's
     * room-service fee is on what is brought to a room, and its room requests
     * carry nothing at all.
     *
     * @var array<string, list<array{name: array<string, string>, rate?: int, amount?: int, menus?: list<string>}>>
     */
    public const array CHARGES = [
        'spice' => [
            [
                'name' => ['en' => 'Service Charge', 'ta' => 'சேவைக் கட்டணம்'],
                'rate' => 1000,
            ],
            [
                'name' => ['en' => 'Packing Charge', 'ta' => 'பொதியிடல் கட்டணம்'],
                'amount' => 2000,
                'menus' => ['main'],
            ],
        ],
        'seaview' => [
            [
                'name' => ['en' => 'Room Service Fee', 'ta' => 'அறை சேவைக் கட்டணம்'],
                'amount' => 5000,
                'menus' => ['in_room_dining', 'breakfast'],
            ],
        ],
        'sunrise' => [
            [
                'name' => ['en' => 'Tray Delivery Charge', 'ta' => 'தட்டு விநியோக கட்டணம்'],
                'amount' => 1500,
            ],
        ],
    ];

    /**
     * The add-on groups items are customised with, and the items each is offered on.
     *
     * A group is a tenant's, so each tenant gets the groups whose items it has,
     * matched by English name wherever those items sit — a hotel's in-room card
     * and a restaurant's main card are the same card here. Each group reads
     * like the real thing: a required single choice (a spice level, a portion,
     * which pillow), an optional handful of extras, and options a guest may take
     * two of. `items` is the order a group is linked in, and a group earlier in
     * this list comes first on an item that has several.
     *
     * `item_max_picks` is the subtle path: `MenuItemAddOnGroup::max_picks`,
     * set only for the item named, tighter than the group's own — an
     * override rather than the group's default, which every other item
     * offering the group leaves at null and simply inherits.
     *
     * @var list<array{name: array<string, string>, is_required: bool, max_picks: int|null, options: list<array{name: array<string, string>, price: int, max_per_item?: int, is_default?: bool, stock_quantity?: int}>, items: list<string>, item_max_picks?: array<string, int>}>
     */
    public const array ADD_ON_GROUPS = [
        [
            'name' => ['en' => 'Portion', 'ta' => 'அளவு'],
            'is_required' => true,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Half', 'ta' => 'அரை'], 'price' => 0, 'is_default' => true],
                ['name' => ['en' => 'Full', 'ta' => 'முழு'], 'price' => 15000],
            ],
            'items' => ['Hyderabadi Chicken Biryani', 'Chicken 65 Biryani', 'Mutton Dum Biryani', 'Vegetable Dum Biryani', 'Paneer Biryani', 'Egg Biryani'],
        ],
        [
            'name' => ['en' => 'Spice level', 'ta' => 'காரம்'],
            'is_required' => true,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Mild', 'ta' => 'குறைவு'], 'price' => 0],
                ['name' => ['en' => 'Medium', 'ta' => 'நடுத்தரம்'], 'price' => 0, 'is_default' => true],
                ['name' => ['en' => 'Hot', 'ta' => 'அதிகம்'], 'price' => 0],
            ],
            'items' => ['Paneer Tikka', 'Gobi Manchurian', 'Chicken 65', 'Chettinad Chicken', 'Egg Bhurji'],
        ],
        [
            'name' => ['en' => 'Choose your bread', 'ta' => 'ரொட்டியைத் தேர்ந்தெடுக்கவும்'],
            'is_required' => true,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Butter naan', 'ta' => 'பட்டர் நான்'], 'price' => 0],
                ['name' => ['en' => 'Garlic naan', 'ta' => 'பூண்டு நான்'], 'price' => 2000],
                ['name' => ['en' => 'Tandoori roti', 'ta' => 'தந்தூரி ரொட்டி'], 'price' => 0],
            ],
            'items' => ['Paneer Butter Masala', 'Dal Tadka', 'Butter Chicken', 'Mutton Rogan Josh'],
        ],
        [
            'name' => ['en' => 'Extras', 'ta' => 'கூடுதல்'],
            'is_required' => false,
            'max_picks' => 3,
            'options' => [
                ['name' => ['en' => 'Extra cheese', 'ta' => 'கூடுதல் சீஸ்'], 'price' => 4000, 'max_per_item' => 2],
                ['name' => ['en' => 'Extra paneer', 'ta' => 'கூடுதல் பன்னீர்'], 'price' => 6000, 'stock_quantity' => 15],
                ['name' => ['en' => 'Raita', 'ta' => 'ராய்தா'], 'price' => 3000],
            ],
            'items' => ['Paneer Tikka', 'Paneer Butter Masala', 'Hyderabadi Chicken Biryani', 'Vegetable Dum Biryani'],
            // A vegetable biryani already comes heavier on vegetables than the
            // meat ones, so this one item caps the group's usual three picks
            // down to one — MenuItemAddOnGroup::max_picks, tighter than
            // MenuAddOnGroup::max_picks, rather than the group's own default.
            'item_max_picks' => ['Vegetable Dum Biryani' => 1],
        ],
        [
            'name' => ['en' => 'Dosa sides', 'ta' => 'தோசை துணைகள்'],
            'is_required' => false,
            // Null rather than a number: a dosa may take as much of each side
            // as its own max_per_item allows, with nothing capping the total.
            'max_picks' => null,
            'options' => [
                ['name' => ['en' => 'Extra chutney', 'ta' => 'கூடுதல் சட்னி'], 'price' => 1500, 'max_per_item' => 2],
                ['name' => ['en' => 'Extra sambar', 'ta' => 'கூடுதல் சாம்பார்'], 'price' => 1500, 'max_per_item' => 2],
                ['name' => ['en' => 'Ghee', 'ta' => 'நெய்'], 'price' => 2500],
            ],
            'items' => ['Masala Dosa', 'Ghee Roast', 'Idli Plate'],
        ],
        [
            'name' => ['en' => 'Sugar', 'ta' => 'சர்க்கரை'],
            'is_required' => true,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Regular', 'ta' => 'வழக்கம்'], 'price' => 0, 'is_default' => true],
                ['name' => ['en' => 'Less sugar', 'ta' => 'குறைந்த சர்க்கரை'], 'price' => 0],
                ['name' => ['en' => 'No sugar', 'ta' => 'சர்க்கரை இல்லை'], 'price' => 0],
            ],
            'items' => ['Filter Coffee', 'Masala Chai', 'Badam Milk'],
        ],
        [
            'name' => ['en' => 'Strength', 'ta' => 'கடுமை'],
            'is_required' => false,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Extra strong', 'ta' => 'கூடுதல் கடுமையான'], 'price' => 1000],
            ],
            'items' => ['Filter Coffee'],
        ],
        [
            'name' => ['en' => 'Sweet or salted', 'ta' => 'இனிப்பு அல்லது உப்பு'],
            'is_required' => true,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Sweet', 'ta' => 'இனிப்பு'], 'price' => 0, 'is_default' => true],
                ['name' => ['en' => 'Salted', 'ta' => 'உப்பு'], 'price' => 0],
            ],
            'items' => ['Fresh Lime Soda'],
        ],
        [
            // Goods customised like anything else: which pillow.
            'name' => ['en' => 'Pillow type', 'ta' => 'தலையணை வகை'],
            'is_required' => true,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Feather', 'ta' => 'இறகு'], 'price' => 0],
                ['name' => ['en' => 'Memory foam', 'ta' => 'மெமரி ஃபோம்'], 'price' => 0],
            ],
            'items' => ['Extra Pillow'],
        ],
        [
            'name' => ['en' => 'Delivery time', 'ta' => 'கொண்டுவரும் நேரம்'],
            'is_required' => false,
            'max_picks' => 1,
            'options' => [
                ['name' => ['en' => 'Now', 'ta' => 'இப்போது'], 'price' => 0],
                ['name' => ['en' => 'In 30 minutes', 'ta' => '30 நிமிடங்களில்'], 'price' => 0],
            ],
            'items' => ['Extra Blanket', 'Bedsheet Change', 'Towel Set'],
        ],
    ];

    /**
     * The restaurant's main card, in both languages.
     *
     * Seeded copy is bilingual on purpose: it is the only way to see that the
     * language toggle in the guest app really does anything without
     * typing a Tamil menu out by hand first.
     *
     * @var array<string, string>
     */
    public const array MENU_NAME = ['en' => 'Main Menu', 'ta' => 'முதன்மை மெனு'];

    /**
     * The same card under the name a hotel gives it.
     *
     * @var array<string, string>
     */
    public const array IN_ROOM_DINING_MENU_NAME = ['en' => 'In-room Dining', 'ta' => 'அறை உணவு'];

    /**
     * The restaurant's second card.
     *
     * One menu was enough to read a storefront but not enough to work the
     * panel: moving a category to another menu, and filing one under the right
     * card, both need somewhere to move it to.
     *
     * @var array<string, string>
     */
    public const array DRINKS_MENU_NAME = ['en' => 'Drinks', 'ta' => 'பானங்கள்'];

    /**
     * A card served only between two times of day, which both tenants get.
     *
     * It exists to make the two things that need somewhere to go actually
     * testable: a menu with a service window, and a third card to move a
     * category onto.
     *
     * @var array<string, string>
     */
    public const array BREAKFAST_MENU_NAME = ['en' => 'Breakfast', 'ta' => 'காலை உணவு'];

    /**
     * The hotel's card of things asked for from the room.
     *
     * @var array<string, string>
     */
    public const array ROOM_REQUESTS_MENU_NAME = ['en' => 'Room Requests', 'ta' => 'அறை கோரிக்கைகள்'];

    /**
     * What a hotel guest asks for from the room: housekeeping, and a few things
     * to drink beside it.
     *
     * Most of it is Goods and most of that is complimentary, which is what the
     * card is for — it shows a pillow and a bottle of water on one menu, one
     * with no diet mark and no price, the other with both. Laundry Pickup is
     * the one genuine Service on the card, charged for and carrying a SAC
     * rather than an HSN.
     *
     * @var list<array<string, mixed>>
     */
    public const array ROOM_REQUESTS = [
        [
            'name' => ['en' => 'Housekeeping', 'ta' => 'வீட்டு பராமரிப்பு'],
            'items' => [
                [
                    'name' => ['en' => 'Extra Pillow', 'ta' => 'கூடுதல் தலையணை'],
                    'kind' => MenuItemKind::Goods,
                    'price' => 0,
                    // Two to an order, however they are split between kinds.
                    'max_per_order' => 2,
                    // Counted: the linen room has only so many.
                    'stock_quantity' => 30,
                    'is_featured' => true,
                    'featured_position' => 1,
                    // Mattress supports, bedding and pillows.
                    'hsn_sac_code' => '9404',
                ],
                [
                    'name' => ['en' => 'Extra Blanket', 'ta' => 'கூடுதல் போர்வை'],
                    'kind' => MenuItemKind::Goods,
                    'price' => 0,
                    'max_per_order' => 2,
                    // Blankets and travelling rugs.
                    'hsn_sac_code' => '6301',
                ],
                [
                    'name' => ['en' => 'Bedsheet Change', 'ta' => 'படுக்கை விரிப்பு மாற்றம்'],
                    'kind' => MenuItemKind::Goods,
                    'price' => 0,
                    'max_per_order' => 1,
                    // Bed linen, table linen, toilet linen and kitchen linen.
                    'hsn_sac_code' => '6302',
                ],
                [
                    'name' => ['en' => 'Towel Set', 'ta' => 'துண்டு தொகுப்பு'],
                    'kind' => MenuItemKind::Goods,
                    'price' => 0,
                    // Toilet linen, the same heading a bedsheet carries.
                    'hsn_sac_code' => '6302',
                ],
                [
                    // A Service that is charged for, at the rate services pay.
                    'name' => ['en' => 'Laundry Pickup', 'ta' => 'சலவை சேகரிப்பு'],
                    'kind' => MenuItemKind::Service,
                    'price' => 15000,
                    'tax_rate' => 1800,
                    // A SAC, not an HSN: this is a service, not a good.
                    'hsn_sac_code' => '999721',
                ],
            ],
        ],
        [
            'name' => ['en' => 'Bathroom and Drinks', 'ta' => 'குளியலறை மற்றும் பானங்கள்'],
            'items' => [
                [
                    'name' => ['en' => 'Toiletry Kit', 'ta' => 'கழிப்பறை பொருட்கள் தொகுப்பு'],
                    'kind' => MenuItemKind::Goods,
                    'price' => 0,
                    // Travel sets for personal toilet.
                    'hsn_sac_code' => '9605',
                ],
                [
                    // Something to order on the same card as the pillows: a
                    // bottle of water carries its diet mark and a price.
                    'name' => ['en' => 'Water Bottle (1 L)', 'ta' => 'தண்ணீர் பாட்டில் (1 லி)'],
                    'price' => 4000,
                    'diets' => [Diet::Vegetarian],
                    'tax_rate' => 1800,
                    // A sealed good, not a served drink: HSN, not SAC.
                    'hsn_sac_code' => '2201',
                ],
            ],
        ],
    ];

    /**
     * The hospital's one card, in both languages.
     *
     * @var array<string, string>
     */
    public const array PATIENT_CARE_MENU_NAME = ['en' => 'Patient Care', 'ta' => 'நோயாளர் பராமரிப்பு'];

    /**
     * What the hospital's card carries: meals a ward orders, and a handful of
     * requests beside them — the same "Consumable, Goods and Service items side
     * by side" shape the hotel's room requests card shows, in a hospital's own words
     * rather than a hotel's.
     *
     * Reuses a few of the restaurant and hotel's own English names on
     * purpose — Filter Coffee, Extra Pillow — so `seedAddOnGroups()` links
     * this tenant's own copy of those items to its own copy of the Sugar,
     * Strength and Pillow type groups, exactly as the breakfast card both
     * tenants already share does.
     *
     * @var list<array<string, mixed>>
     */
    public const array PATIENT_CARE = [
        [
            'name' => ['en' => 'Meals', 'ta' => 'உணவுகள்'],
            'items' => [
                [
                    'name' => ['en' => 'Diabetic Thali', 'ta' => 'நீரிழிவு தாலி'],
                    'price' => 9000,
                    'diets' => [Diet::Vegetarian],
                    'stock_quantity' => 20,
                ],
                [
                    'name' => ['en' => 'Regular Thali', 'ta' => 'சாதாரண தாலி'],
                    'price' => 8000,
                    'diets' => [Diet::Vegetarian],
                ],
                [
                    'name' => ['en' => 'Filter Coffee', 'ta' => 'ஃபில்டர் காபி'],
                    'price' => 5000,
                    'diets' => [Diet::Vegetarian],
                    'is_featured' => true,
                    'featured_position' => 1,
                ],
            ],
        ],
        [
            'name' => ['en' => 'Requests', 'ta' => 'கோரிக்கைகள்'],
            'items' => [
                [
                    // Complimentary, like the hotel's housekeeping requests —
                    // and still worth a SAC, because a support service is
                    // billed to the stay even at a price of nothing.
                    'name' => ['en' => 'Wheelchair Assistance', 'ta' => 'சக்கர நாற்காலி உதவி'],
                    'kind' => MenuItemKind::Service,
                    'price' => 0,
                    'max_per_order' => 1,
                    'hsn_sac_code' => '999312',
                ],
                [
                    'name' => ['en' => 'Extra Pillow', 'ta' => 'கூடுதல் தலையணை'],
                    'kind' => MenuItemKind::Goods,
                    'price' => 0,
                    'max_per_order' => 2,
                    'stock_quantity' => 15,
                    // Mattress supports, bedding and pillows.
                    'hsn_sac_code' => '9404',
                ],
                [
                    // Something to order beside the requests, the way the
                    // hotel's water bottle sits beside its pillows.
                    'name' => ['en' => 'Attender Meal Tray', 'ta' => 'உதவியாளர் உணவு தட்டு'],
                    'price' => 7000,
                    'diets' => [Diet::Vegetarian],
                ],
            ],
        ],
    ];

    /**
     * What the breakfast card is served between, as HH:MM.
     */
    public const string BREAKFAST_FROM = '07:00';

    public const string BREAKFAST_UNTIL = '11:00';

    /**
     * The breakfast card: short, and one of its categories is subdivided.
     *
     * @var list<array<string, mixed>>
     */
    public const array BREAKFAST = [
        [
            'name' => ['en' => 'Tiffin', 'ta' => 'டிபன்'],
            'sub_categories' => [
                [
                    'name' => ['en' => 'Dosa', 'ta' => 'தோசை'],
                    'items' => [
                        [
                            'name' => ['en' => 'Masala Dosa', 'ta' => 'மசாலா தோசை'],
                            'price' => 11000,
                            'diets' => [Diet::Vegetarian],
                            'is_featured' => true,
                            'featured_position' => 1,
                        ],
                        [
                            'name' => ['en' => 'Ghee Roast', 'ta' => 'நெய் ரோஸ்ட்'],
                            'price' => 13000,
                            'diets' => [Diet::Vegetarian],
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Idli and Vada', 'ta' => 'இட்லி மற்றும் வடை'],
                    'items' => [
                        [
                            // Rice and lentils and nothing else, so both marks:
                            // vegetarian like the rest of the card, and vegan
                            // too. The Ghee Roast above it is only the first.
                            'name' => ['en' => 'Idli Plate', 'ta' => 'இட்லி பிளேட்'],
                            'price' => 8000,
                            'diets' => [Diet::Vegetarian, Diet::Vegan],
                            // A staple steamed in the merit slab, called out
                            // explicitly rather than left to whatever the
                            // tenant's own rate happens to be.
                            'tax_rate' => 500,
                        ],
                        [
                            'name' => ['en' => 'Medu Vada', 'ta' => 'மெது வடை'],
                            'price' => 7000,
                            'diets' => [Diet::Vegetarian, Diet::Vegan],
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Egg Specials', 'ta' => 'முட்டை ஸ்பெஷல்'],
            'items' => [
                [
                    'name' => ['en' => 'Egg Bhurji', 'ta' => 'முட்டை பூர்ஜி'],
                    'price' => 12000,
                    'diets' => [Diet::Egg],
                ],
                [
                    'name' => ['en' => 'Omelette', 'ta' => 'ஆம்லெட்'],
                    'price' => 9000,
                    'diets' => [Diet::Egg],
                ],
            ],
        ],
    ];

    /**
     * The bundles the main menu leads with, beside its featured items.
     *
     * Priced below what the items come to separately, which is the whole
     * point of a combo — the contents are named so a guest can see what they
     * are getting, never to be added up.
     *
     * @var list<array{
     *     name: array<string, string>,
     *     description: array<string, string>,
     *     price: int,
     *     original_price: int,
     *     contents: list<array{name: array<string, string>, quantity: int}>,
     *     tax_rate?: int,
     *     hsn_sac_code?: string,
     *     max_per_order?: int
     * }>
     */
    public const array COMBOS = [
        [
            'name' => ['en' => 'Biryani Feast', 'ta' => 'பிரியாணி விருந்து'],
            'description' => [
                'en' => 'Chicken biryani, a starter and two breads.',
                'ta' => 'சிக்கன் பிரியாணி, ஒரு தொடக்கம், இரண்டு ரொட்டிகள்.',
            ],
            'price' => 59900,
            'original_price' => 75900,
            'contents' => [
                ['name' => ['en' => 'Hyderabadi Chicken Biryani'], 'quantity' => 1],
                ['name' => ['en' => 'Chicken 65'], 'quantity' => 1],
                ['name' => ['en' => 'Butter Naan'], 'quantity' => 2],
            ],
        ],
        [
            'name' => ['en' => 'Veg Thali', 'ta' => 'சைவ தாலி'],
            'description' => [
                'en' => 'Paneer butter masala, dal, two breads and a sweet.',
                'ta' => 'பன்னீர் பட்டர் மசாலா, தால், இரண்டு ரொட்டிகள், ஒரு இனிப்பு.',
            ],
            'price' => 44900,
            'original_price' => 58800,
            'contents' => [
                ['name' => ['en' => 'Paneer Butter Masala'], 'quantity' => 1],
                ['name' => ['en' => 'Dal Tadka'], 'quantity' => 1],
                ['name' => ['en' => 'Tandoori Roti'], 'quantity' => 2],
                ['name' => ['en' => 'Gulab Jamun'], 'quantity' => 1],
            ],
        ],
        [
            'name' => ['en' => 'Family Pack', 'ta' => 'குடும்பப் பொதி'],
            'description' => [
                'en' => 'Enough biryani, curry and bread for four.',
                'ta' => 'நான்கு பேருக்கு போதுமான பிரியாணி, கிரேவி, ரொட்டி.',
            ],
            'price' => 129900,
            'original_price' => 159600,
            'contents' => [
                ['name' => ['en' => 'Mutton Dum Biryani'], 'quantity' => 2],
                ['name' => ['en' => 'Butter Chicken'], 'quantity' => 1],
                ['name' => ['en' => 'Butter Naan'], 'quantity' => 4],
            ],
            // Feeds four, so more than a handful of these on one order reads
            // as a mistake rather than a large table — a larger cap than the
            // 1 or 2 the other combos carry.
            'max_per_order' => 3,
        ],
        [
            'name' => ['en' => 'Lunch Box', 'ta' => 'மதிய உணவுப் பெட்டி'],
            'description' => [
                'en' => 'One veg biryani and a soup, packed to go.',
                'ta' => 'ஒரு சைவ பிரியாணி, ஒரு சூப் — பார்சலாக.',
            ],
            'price' => 39900,
            'original_price' => 44900,
            'contents' => [
                ['name' => ['en' => 'Vegetable Dum Biryani'], 'quantity' => 1],
                ['name' => ['en' => 'Sweet Corn Soup'], 'quantity' => 1],
            ],
            // Packed and sealed to go rather than served, so it is billed
            // under a different slab from the rest of the card.
            'tax_rate' => 1200,
            'hsn_sac_code' => '2106',
            // One box per order: it is meant for one person to carry out.
            'max_per_order' => 1,
        ],
    ];

    public const array DRINKS = [
        [
            'name' => ['en' => 'Hot', 'ta' => 'சூடானவை'],
            'items' => [
                [
                    'name' => ['en' => 'Filter Coffee', 'ta' => 'ஃபில்டர் காபி'],
                    'price' => 5000,
                    'diets' => [Diet::Vegetarian],
                    'is_featured' => true,
                    'featured_position' => 1,
                ],
                [
                    'name' => ['en' => 'Masala Chai', 'ta' => 'மசாலா டீ'],
                    'price' => 4000,
                    'diets' => [Diet::Vegetarian],
                ],
                [
                    'name' => ['en' => 'Badam Milk', 'ta' => 'பாதாம் பால்'],
                    'price' => 7000,
                    'diets' => [Diet::Vegetarian],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Cold', 'ta' => 'குளிர்பானங்கள்'],
            'sub_categories' => [
                [
                    'name' => ['en' => 'Juices', 'ta' => 'பழச்சாறுகள்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Fresh Lime Soda', 'ta' => 'ஃபிரெஷ் லைம் சோடா'],
                            'price' => 8000,
                            'diets' => [Diet::Vegetarian],
                        ],
                        [
                            'name' => ['en' => 'Watermelon Juice', 'ta' => 'தர்பூசணி ஜூஸ்'],
                            'price' => 9000,
                            'diets' => [Diet::Vegetarian],
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Shakes', 'ta' => 'ஷேக்குகள்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Mango Lassi', 'ta' => 'மாம்பழ லஸ்ஸி'],
                            'price' => 11000,
                            'original_price' => 13000,
                            'diets' => [Diet::Vegetarian],
                            'is_featured' => true,
                            'featured_position' => 2,
                        ],
                        [
                            'name' => ['en' => 'Cold Coffee', 'ta' => 'கோல்ட் காபி'],
                            'price' => 12000,
                            'diets' => [Diet::Vegetarian],
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Bottled', 'ta' => 'பாட்டில்'],
                    'items' => [
                        [
                            // Sealed and taxed the way packaged goods are, and
                            // an aerated one at that — 40% under GST 2.0 — but
                            // still a Consumable: a guest drinks it, so it keeps
                            // its diet mark, unlike the masala powder below.
                            'name' => ['en' => 'Cola', 'ta' => 'கோலா'],
                            'price' => 6000,
                            'diets' => [Diet::Vegetarian],
                            'tax_rate' => 4000,
                            'hsn_sac_code' => '2202',
                        ],
                        [
                            'name' => ['en' => 'Mineral Water', 'ta' => 'மினரல் வாட்டர்'],
                            'price' => 2000,
                            'diets' => [Diet::Vegetarian],
                            'tax_rate' => 1800,
                            'hsn_sac_code' => '2201',
                        ],
                        [
                            // Genuinely Goods, unlike the drinks above it: a
                            // branded tin to take home rather than something
                            // drunk here, so it carries no diet mark.
                            'name' => ['en' => 'House Masala Powder (200 g)', 'ta' => 'ஹவுஸ் மசாலா பொடி (200 கி)'],
                            'kind' => MenuItemKind::Goods,
                            'price' => 15000,
                            'hsn_sac_code' => '0910',
                        ],
                    ],
                ],
            ],
        ],
    ];

    public const array MENU = [
        [
            'name' => ['en' => 'Starters', 'ta' => 'தொடக்கங்கள்'],
            'sub_categories' => [
                [
                    'name' => ['en' => 'Vegetarian', 'ta' => 'சைவம்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Paneer Tikka', 'ta' => 'பன்னீர் டிக்கா'],
                            'price' => 24950,
                            'diets' => [Diet::Vegetarian],
                            'is_featured' => true,
                            'featured_position' => 1,
                        ],
                        [
                            'name' => ['en' => 'Gobi Manchurian', 'ta' => 'கோபி மஞ்சூரியன்'],
                            'price' => 21000,
                            'diets' => [Diet::Vegetarian],
                        ],
                        [
                            'name' => ['en' => 'Mushroom 65', 'ta' => 'காளான் 65'],
                            'price' => 23000,
                            'diets' => [Diet::Vegetarian],
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Non-vegetarian', 'ta' => 'அசைவம்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Chicken 65', 'ta' => 'சிக்கன் 65'],
                            'price' => 29900,
                            'original_price' => 34900,
                            'diets' => [Diet::NonVegetarian],
                            'is_featured' => true,
                            'featured_position' => 2,
                        ],
                        [
                            'name' => ['en' => 'Apollo Fish', 'ta' => 'அப்பல்லோ மீன்'],
                            'price' => 34900,
                            'diets' => [Diet::NonVegetarian],
                        ],
                        [
                            'name' => ['en' => 'Prawn Koliwada', 'ta' => 'இறால் கோலிவாடா'],
                            'price' => 39900,
                            'diets' => [Diet::NonVegetarian],
                            'availability' => ItemAvailability::OutOfStock,
                        ],
                        [
                            'name' => ['en' => 'Egg Pepper Fry', 'ta' => 'முட்டை மிளகு வறுவல்'],
                            'price' => 19900,
                            'diets' => [Diet::Egg],
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Soups', 'ta' => 'சூப்புகள்'],
            'items' => [
                [
                    'name' => ['en' => 'Sweet Corn Soup', 'ta' => 'ஸ்வீட் கார்ன் சூப்'],
                    'price' => 14900,
                    'diets' => [Diet::Vegetarian],
                ],
                [
                    'name' => ['en' => 'Hot and Sour Soup', 'ta' => 'ஹாட் அண்ட் சார் சூப்'],
                    'price' => 15900,
                    'diets' => [Diet::Vegetarian],
                ],
                [
                    'name' => ['en' => 'Mutton Paya Soup', 'ta' => 'மட்டன் பாயா சூப்'],
                    'price' => 21900,
                    'diets' => [Diet::NonVegetarian],
                ],
            ],
        ],
        [
            // The category that shows what subdivisions are for: three of them,
            // each with items, plus one item filed straight under the section.
            'name' => ['en' => 'Biryani', 'ta' => 'பிரியாணி'],
            'items' => [
                [
                    'name' => ['en' => 'Egg Biryani', 'ta' => 'முட்டை பிரியாணி'],
                    'price' => 27500,
                    'diets' => [Diet::Egg],
                ],
            ],
            'sub_categories' => [
                [
                    'name' => ['en' => 'Chicken', 'ta' => 'சிக்கன்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Hyderabadi Chicken Biryani', 'ta' => 'ஹைதராபாதி சிக்கன் பிரியாணி'],
                            'price' => 38000,
                            'original_price' => 45000,
                            'diets' => [Diet::NonVegetarian],
                            'is_featured' => true,
                            'featured_position' => 3,
                            // Counted too, unlike most of the card, so an
                            // order and the Biryani Feast combo both draw on
                            // the same pot without either running it dry.
                            'stock_quantity' => 25,
                        ],
                        [
                            'name' => ['en' => 'Chicken 65 Biryani', 'ta' => 'சிக்கன் 65 பிரியாணி'],
                            'price' => 41000,
                            'diets' => [Diet::NonVegetarian],
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Mutton', 'ta' => 'மட்டன்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Mutton Dum Biryani', 'ta' => 'மட்டன் தம் பிரியாணி'],
                            'price' => 46000,
                            'diets' => [Diet::NonVegetarian],
                            // Made in one pot a day, so it is counted — and the
                            // biryani combo draws on the same count. Today's
                            // pot sold out before service even opened, at
                            // exactly zero rather than merely marked off, so
                            // both `ItemAvailability::OutOfStock` and "a combo
                            // holding a counted item with none left is
                            // unorderable" are visible on a fresh install —
                            // the Family Pack combo above holds this item and
                            // so is unorderable until it is restocked.
                            'stock_quantity' => 0,
                            'availability' => ItemAvailability::OutOfStock,
                        ],
                        [
                            'name' => ['en' => 'Mutton Keema Biryani', 'ta' => 'மட்டன் கீமா பிரியாணி'],
                            'price' => 44000,
                            'diets' => [Diet::NonVegetarian],
                            'availability' => ItemAvailability::TemporarilyUnavailable,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Vegetable', 'ta' => 'காய்கறி'],
                    'items' => [
                        [
                            'name' => ['en' => 'Vegetable Dum Biryani', 'ta' => 'வெஜிடபிள் தம் பிரியாணி'],
                            'price' => 30000,
                            'diets' => [Diet::Vegetarian],
                        ],
                        [
                            'name' => ['en' => 'Paneer Biryani', 'ta' => 'பன்னீர் பிரியாணி'],
                            'price' => 33000,
                            'diets' => [Diet::Vegetarian],
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Curries', 'ta' => 'கிரேவிகள்'],
            'sub_categories' => [
                [
                    'name' => ['en' => 'Vegetarian', 'ta' => 'சைவம்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Paneer Butter Masala', 'ta' => 'பன்னீர் பட்டர் மசாலா'],
                            'price' => 28900,
                            'diets' => [Diet::Vegetarian],
                        ],
                        [
                            'name' => ['en' => 'Dal Tadka', 'ta' => 'தால் தட்கா'],
                            'price' => 21900,
                            'diets' => [Diet::Vegetarian],
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Non-vegetarian', 'ta' => 'அசைவம்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Butter Chicken', 'ta' => 'பட்டர் சிக்கன்'],
                            'price' => 36900,
                            'diets' => [Diet::NonVegetarian],
                            'is_featured' => true,
                            'featured_position' => 4,
                        ],
                        [
                            'name' => ['en' => 'Chettinad Chicken', 'ta' => 'செட்டிநாடு சிக்கன்'],
                            'price' => 35900,
                            'diets' => [Diet::NonVegetarian],
                        ],
                        [
                            'name' => ['en' => 'Mutton Rogan Josh', 'ta' => 'மட்டன் ரோகன் ஜோஷ்'],
                            'price' => 44900,
                            'diets' => [Diet::NonVegetarian],
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Breads', 'ta' => 'ரொட்டிகள்'],
            'items' => [
                [
                    'name' => ['en' => 'Butter Naan', 'ta' => 'பட்டர் நான்'],
                    'price' => 8000,
                    'diets' => [Diet::Vegetarian],
                    // A table sharing curries orders these by the half-dozen,
                    // so its cap is a genuinely large one rather than the 1 or
                    // 2 the rest of the card carries.
                    'max_per_order' => 10,
                ],
                [
                    'name' => ['en' => 'Tandoori Roti', 'ta' => 'தந்தூரி ரொட்டி'],
                    'price' => 5000,
                    'diets' => [Diet::Vegetarian],
                ],
                [
                    'name' => ['en' => 'Laccha Paratha', 'ta' => 'லச்சா பராத்தா'],
                    'price' => 7000,
                    'diets' => [Diet::Vegetarian],
                ],
                [
                    'name' => ['en' => 'Kerala Parotta', 'ta' => 'கேரள பரோட்டா'],
                    'price' => 4500,
                    'diets' => [Diet::Vegetarian],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Desserts', 'ta' => 'இனிப்புகள்'],
            'items' => [
                [
                    'name' => ['en' => 'Gulab Jamun', 'ta' => 'குலாப் ஜாமூன்'],
                    'price' => 12000,
                    'diets' => [Diet::Vegetarian],
                    // The seeded 12% example: a rate this application never
                    // hardcodes as a slab, but one a tenant's own accountant
                    // may still type in on an item priced before GST 2.0
                    // collapsed the public rate list (.ai/rules/enums.md).
                    'tax_rate' => 1200,
                    'hsn_sac_code' => '2106',
                ],
                [
                    'name' => ['en' => 'Rasmalai', 'ta' => 'ரஸ்மலாய்'],
                    'price' => 14000,
                    'diets' => [Diet::Vegetarian],
                    'is_featured' => true,
                    'featured_position' => 5,
                ],
                [
                    'name' => ['en' => 'Double Ka Meetha', 'ta' => 'டபுள் கா மீதா'],
                    'price' => 13000,
                    'diets' => [Diet::Vegetarian],
                ],
                [
                    // Sold as a sealed tub rather than served, so it carries a
                    // rate of its own — 18%, not the 5% the rest of the card
                    // follows.
                    'name' => ['en' => 'Ice Cream Tub', 'ta' => 'ஐஸ்கிரீம் டப்'],
                    'price' => 18000,
                    'diets' => [Diet::Vegetarian],
                    'tax_rate' => 1800,
                    'hsn_sac_code' => '2105',
                ],
            ],
        ],
    ];

    /**
     * Seed every tenant, its people, the menus it serves and what it adds to a bill.
     */
    public function run(): void
    {
        foreach (self::TENANTS as $definition) {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'address' => $definition['address'],
                    'pincode' => $definition['pincode'],
                    'email' => $definition['email'],
                    'phone_country_code' => CountryCallingCode::India,
                    'phone' => $definition['phone'],
                    'is_active' => true,
                ],
            );

            $tenant->settings()->firstOrCreate([], [
                'contact_email' => "hello@{$definition['slug']}.example.com",
                'contact_phone' => '+91 98765 43210',
                'alternate_phone' => '+91 98765 43211',
                'landline_phone' => '+91 44 2345 6789',
                'currency' => Currency::IndianRupee,
                'gstin' => $definition['gstin'],
                'gst_treatment' => $definition['gst_treatment'],
                'cgst_rate' => $definition['cgst_rate'],
                'sgst_rate' => $definition['sgst_rate'],
                'tax_overrides_item_rates' => $definition['tax_overrides_item_rates'],
                'prices_include_tax' => $definition['prices_include_tax'],
            ]);

            $this->seedOpeningHours($tenant);

            // The owner belongs to this tenant, not the product team: a
            // null tenant_id would file them under "Product team" in the
            // platform panel, which they are not.
            $owner = User::query()->firstOrCreate(
                ['email' => $definition['owner_email']],
                ['name' => $definition['owner_name'], 'tenant_id' => $tenant->getKey(), 'email_verified_at' => now()],
            );

            $owner->syncRoles([Role::Owner->value]);
            $tenant->users()->syncWithoutDetaching([$owner->getKey()]);

            // A staff account for the roster and its limits. Plus-addressing means every
            // seeded account's sign-in code lands in the same real inbox.
            $staff = User::query()->firstOrCreate(
                ['email' => $definition['staff_email']],
                ['name' => $definition['staff_name'], 'tenant_id' => $tenant->getKey(), 'email_verified_at' => now()],
            );

            $staff->syncRoles([Role::Staff->value]);
            $tenant->users()->syncWithoutDetaching([$staff->getKey()]);

            $menus = $this->seedMenus($tenant, $definition['menus']);

            // After the menus, because a group is linked to items that have to exist.
            $this->seedAddOnGroups($tenant);

            $this->seedCharges($tenant, self::CHARGES[$definition['slug']], $menus);
        }
    }

    /**
     * The week a tenant keeps its doors open, with one day off.
     *
     * Monday is the holiday, so the guest app's closed state and the refusal
     * that goes with it are visible on a fresh install without anyone editing
     * the settings first.
     */
    private function seedOpeningHours(Tenant $tenant): void
    {
        foreach (Weekday::week() as $weekday) {
            $isHoliday = $weekday === Weekday::Monday;

            $tenant->openingHours()->firstOrCreate(
                ['weekday' => $weekday],
                [
                    'is_closed' => $isHoliday,
                    'opens_at' => $isHoliday ? null : '09:00:00',
                    'closes_at' => $isHoliday ? null : '23:00:00',
                ],
            );
        }
    }

    /**
     * A tenant's bilingual cards, so a fresh install has something to look at.
     *
     * Prices are in the minor unit, as the column is: 24950 is ₹249.50.
     *
     * Every lookup matches on the English name rather than the whole translated
     * column, because a JSON document only compares equal when every language
     * in it does — which would make this seeder duplicate its own menu the
     * first time a tenant translated one item.
     *
     * @param  list<string>  $cards  keys of CARDS, in the order a guest reads them
     * @return array<string, Menu> the seeded menus, keyed by card
     */
    private function seedMenus(Tenant $tenant, array $cards): array
    {
        $menus = [];

        foreach ($cards as $key) {
            $card = self::CARDS[$key];

            $menu = $this->firstOrCreateByEnglishName(
                Menu::query()->where('tenant_id', $tenant->getKey()),
                $card['name'],
                fn (): Menu => new Menu([
                    'is_active' => true,
                    'available_from' => $card['available_from'] ?? null,
                    'available_until' => $card['available_until'] ?? null,
                ]),
                ['tenant_id' => $tenant->getKey()],
            );

            $this->seedCard($tenant, $menu, $card['sections']);

            // After the card, because a combo names items that have to exist.
            if ($card['combos'] ?? false) {
                $this->seedCombos($tenant, $menu);
            }

            // After the card and its combos, because a rail's position is
            // read against the categories it is dragged among.
            $this->seedRails($tenant, $menu, $card['rails']);

            $menus[$key] = $menu;
        }

        // Every menu gets a way in. A card a guest cannot reach is a card that
        // may as well not be there.
        $this->seedHomeScreen($tenant, array_values($menus));

        return $menus;
    }

    /**
     * What one tenant adds to a bill, each charge limited to the menus it names.
     *
     * Matched on the English name, like everything else here, so seeding again
     * finds a charge rather than adding a second one. The menus are synced
     * straight onto the pivot rather than through SetChargeMenus: this seeder
     * runs without model events, and every menu here is the tenant's own.
     *
     * @param  list<array{name: array<string, string>, rate?: int, amount?: int, menus?: list<string>}>  $charges
     * @param  array<string, Menu>  $menus  this tenant's menus, keyed by card
     */
    private function seedCharges(Tenant $tenant, array $charges, array $menus): void
    {
        foreach ($charges as $position => $definition) {
            // A charge that names no menus goes on every menu this tenant has.
            $limitedTo = $definition['menus'] ?? array_keys($menus);

            $charge = $this->firstOrCreateByEnglishName(
                Charge::query()->where('tenant_id', $tenant->getKey()),
                $definition['name'],
                fn (): Charge => new Charge([
                    'calculation' => isset($definition['rate'])
                        ? ChargeCalculation::Percentage
                        : ChargeCalculation::FixedAmount,
                    'rate' => $definition['rate'] ?? null,
                    'amount' => $definition['amount'] ?? null,
                    'is_active' => true,
                    'position' => $position,
                ]),
                ['tenant_id' => $tenant->getKey()],
            );

            $charge->menus()->sync(array_values(array_map(
                static fn (string $card): int => $menus[$card]->getKey(),
                array_filter($limitedTo, static fn (string $card): bool => isset($menus[$card])),
            )));
        }
    }

    /**
     * One menu's categories and the items in each.
     *
     * @param  list<array<string, mixed>>  $card
     */
    private function seedCard(Tenant $tenant, Menu $menu, array $card): void
    {
        foreach ($card as $position => $section) {
            $category = $this->seedCategory($tenant, $menu, $section['name'], $position);

            foreach ($section['items'] ?? [] as $itemPosition => $item) {
                $this->seedItem($tenant, $category, $item, $itemPosition);
            }

            // Subdivisions are rows of the same table with a parent, so they
            // are seeded by the same call — only `parent` differs.
            foreach ($section['sub_categories'] ?? [] as $subPosition => $subSection) {
                $subCategory = $this->seedCategory($tenant, $menu, $subSection['name'], $subPosition, $category);

                foreach ($subSection['items'] as $itemPosition => $item) {
                    $this->seedItem($tenant, $subCategory, $item, $itemPosition);
                }
            }
        }
    }

    /**
     * One category, at either level of the menu.
     *
     * Uniqueness is per level, so an existing row is looked for among its own
     * siblings — the top-level categories of the menu, or the children of one.
     *
     * @param  array<string, string>  $name
     */
    private function seedCategory(
        Tenant $tenant,
        Menu $menu,
        array $name,
        int $position,
        ?MenuCategory $parent = null,
    ): MenuCategory {
        $siblings = MenuCategory::query()
            ->where('menu_id', $menu->getKey())
            ->when(
                $parent instanceof MenuCategory,
                fn ($query) => $query->where('parent_id', $parent?->getKey()),
                fn ($query) => $query->whereNull('parent_id'),
            );

        return $this->firstOrCreateByEnglishName(
            $siblings,
            $name,
            fn (): MenuCategory => new MenuCategory(['position' => $position, 'is_active' => true]),
            [
                'tenant_id' => $tenant->getKey(),
                'menu_id' => $menu->getKey(),
                'parent_id' => $parent?->getKey(),
            ],
        );
    }

    /**
     * One item, and its offer if it has one.
     *
     * The category may be a section or one of its subdivisions; an item is filed
     * under exactly one either way. An item defaults to Consumable, the only
     * kind that carries a diet mark; a card marks one Goods or Service instead,
     * and both of those go without one.
     *
     * @param  array<string, mixed>  $item
     */
    private function seedItem(
        Tenant $tenant,
        MenuCategory $category,
        array $item,
        int $position,
    ): void {
        $this->firstOrCreateByEnglishName(
            MenuItem::query()->where('menu_category_id', $category->getKey()),
            $item['name'],
            fn (): MenuItem => new MenuItem([
                'price' => $item['price'],
                // Null on almost every item: not on offer. A zero would be a
                // price of nothing.
                'original_price' => $item['original_price'] ?? null,
                'kind' => $item['kind'] ?? MenuItemKind::Consumable,
                'diets' => $item['diets'] ?? null,
                'availability' => $item['availability'] ?? ItemAvailability::Available,
                // Null is no limit.
                'max_per_order' => $item['max_per_order'] ?? null,
                // Null is nobody counting.
                'stock_quantity' => $item['stock_quantity'] ?? null,
                'is_featured' => $item['is_featured'] ?? false,
                'featured_position' => $item['featured_position'] ?? 0,
                // Null falls back to the tenant's own rate — almost every item.
                'tax_rate' => $item['tax_rate'] ?? null,
                // HSN for goods, SAC for a service; null on most items, which
                // is a fair invoice with no code rather than a wrong one.
                'hsn_sac_code' => $item['hsn_sac_code'] ?? null,
                'position' => $position,
            ]),
            [
                'tenant_id' => $tenant->getKey(),
                'menu_category_id' => $category->getKey(),
            ],
        );
    }

    /**
     * The tenant's add-on groups, their options, and the items each is offered on.
     *
     * A group is only seeded for a tenant that has at least one of its items, so
     * a restaurant's library does not fill up with pillow types. Everything is
     * matched rather than inserted — a group and an option on the English name,
     * a link on its item and group — so seeding again adds nothing. Items are
     * found by English name across all of the tenant's menus: the breakfast card
     * both tenants share gets its groups on each.
     */
    private function seedAddOnGroups(Tenant $tenant): void
    {
        foreach (self::ADD_ON_GROUPS as $position => $definition) {
            $items = MenuItem::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('name->'.Locale::English->value, $definition['items'])
                ->get(['id', 'name']);

            if ($items->isEmpty()) {
                continue;
            }

            $itemMaxPicks = $definition['item_max_picks'] ?? [];

            $group = $this->firstOrCreateByEnglishName(
                MenuAddOnGroup::query()->where('tenant_id', $tenant->getKey()),
                $definition['name'],
                fn (): MenuAddOnGroup => new MenuAddOnGroup([
                    'is_required' => $definition['is_required'],
                    'max_picks' => $definition['max_picks'],
                ]),
                ['tenant_id' => $tenant->getKey()],
            );

            foreach ($definition['options'] as $optionPosition => $option) {
                $this->firstOrCreateByEnglishName(
                    MenuAddOnOption::query()->where('menu_add_on_group_id', $group->getKey()),
                    $option['name'],
                    fn (): MenuAddOnOption => new MenuAddOnOption([
                        'price' => $option['price'],
                        'max_per_item' => $option['max_per_item'] ?? 1,
                        'is_default' => $option['is_default'] ?? false,
                        'is_available' => true,
                        // Null is nobody counting.
                        'stock_quantity' => $option['stock_quantity'] ?? null,
                        'position' => $optionPosition,
                    ]),
                    ['tenant_id' => $tenant->getKey(), 'menu_add_on_group_id' => $group->getKey()],
                );
            }

            foreach ($items as $item) {
                MenuItemAddOnGroup::query()->firstOrCreate(
                    ['menu_item_id' => $item->getKey(), 'menu_add_on_group_id' => $group->getKey()],
                    [
                        'tenant_id' => $tenant->getKey(),
                        'position' => $position,
                        // Null follows the group's own maximum; only the item
                        // named in item_max_picks caps it tighter.
                        'max_picks' => $itemMaxPicks[$item->getTranslation('name', Locale::English->value)] ?? null,
                    ],
                );
            }
        }
    }

    /**
     * The bundles one menu leads with, and what is in each.
     *
     * A combo's contents are looked up by the English name of an item already
     * seeded onto this menu. A name that finds nothing is skipped rather than
     * failing the seed: the combo is still a working combo one line shorter,
     * and a half-seeded database is worse than a slightly smaller one.
     */
    private function seedCombos(Tenant $tenant, Menu $menu): void
    {
        foreach (self::COMBOS as $position => $definition) {
            $combo = $this->firstOrCreateByEnglishName(
                MenuCombo::query()->where('menu_id', $menu->getKey()),
                $definition['name'],
                fn (): MenuCombo => new MenuCombo([
                    'description' => $definition['description'],
                    'price' => $definition['price'],
                    'original_price' => $definition['original_price'],
                    'tax_rate' => $definition['tax_rate'] ?? null,
                    'hsn_sac_code' => $definition['hsn_sac_code'] ?? null,
                    'availability' => ItemAvailability::Available,
                    // Null is no limit.
                    'max_per_order' => $definition['max_per_order'] ?? null,
                    'position' => $position,
                ]),
                ['tenant_id' => $tenant->getKey(), 'menu_id' => $menu->getKey()],
            );

            foreach ($definition['contents'] as $contentPosition => $content) {
                $item = MenuItem::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->onMenu($menu->getKey())
                    ->where('name->'.Locale::English->value, $content['name'][Locale::English->value])
                    ->first();

                if (! $item instanceof MenuItem) {
                    continue;
                }

                MenuComboItem::query()->firstOrCreate(
                    ['menu_combo_id' => $combo->getKey(), 'menu_item_id' => $item->getKey()],
                    [
                        'tenant_id' => $tenant->getKey(),
                        'quantity' => $content['quantity'],
                        'position' => $contentPosition,
                    ],
                );
            }
        }
    }

    /**
     * Place a menu's featured and combos rails among its categories.
     *
     * `menu_rails.position` shares the same number space as
     * `menu_categories.position` (`Menu::readingOrder()`), and ties break a
     * rail before a category sharing its number, in `MenuRailType` order
     * (`ApplyMenuArrangement`) — so a rail placed at the same position as an
     * existing category sits immediately ahead of it rather than needing the
     * category renumbered to make room. That is what lets CARDS give each
     * card its own arrangement — leading, mid-card, closing — by naming a
     * position alone.
     *
     * A rail every menu is entitled to but nobody has placed reads at the top
     * regardless (`Menu::readingOrder()`), so a card left out of CARDS'
     * `rails` — one with nothing featured and no combos — is not missing
     * anything a guest would notice; it is simply one this seeder leaves for
     * `ApplyMenuArrangement` to write the first time someone drags it.
     *
     * Loosely typed like every other CARDS-shaped parameter here
     * (`seedCard()`, `seedCategory()`): CARDS is looked up by a variable key
     * (`self::CARDS[$key]`), which is as far as static analysis can follow
     * the precise shape documented on the constant itself.
     *
     * @param  list<array<string, mixed>>  $rails  each a MenuRailType and the position to place it at
     */
    private function seedRails(Tenant $tenant, Menu $menu, array $rails): void
    {
        foreach ($rails as $rail) {
            // MenuRail::type casts to MenuRailType, and Eloquent's query
            // builder reads a backed enum's own value when it is used in a
            // where clause — the same way seedOpeningHours() hands Weekday
            // straight to firstOrCreate().
            MenuRail::query()->firstOrCreate(
                ['tenant_id' => $tenant->getKey(), 'menu_id' => $menu->getKey(), 'type' => $rail['type']],
                ['position' => $rail['position']],
            );
        }
    }

    /**
     * The tiles a freshly seeded tenant's guests land on: one per menu.
     *
     * A guest only ever reaches a menu through a tile, so seeding one tile left
     * the drinks and breakfast cards with no way in — they existed, and nobody
     * arriving at a table could get to them.
     *
     * Matched on the menu each tile opens rather than on its label, so
     * re-seeding never doubles up and the tile a tenant has already
     * relabelled keeps its own words.
     *
     * No picture: there is no photography to seed, and a tile without one is a
     * working tile — the guest app draws the label on the brand colour.
     *
     * @param  list<Menu>  $menus  in the order a guest should read them
     */
    private function seedHomeScreen(Tenant $tenant, array $menus): void
    {
        // One banner row: full-width rectangles, one tap target per line, which
        // is the shape a menu tile wants. A rail of photographs beside it is
        // something a tenant adds from the panel.
        $row = HomeRow::query()->firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'position' => 0],
            ['layout' => HomeRowLayout::Banner, 'is_active' => true],
        );

        foreach ($menus as $position => $menu) {
            $existing = HomeTile::query()
                ->where('home_row_id', $row->getKey())
                ->where('menu_id', $menu->getKey())
                ->exists();

            if ($existing) {
                continue;
            }

            HomeTile::query()->create([
                // The menu's own name, in every language it is stored in, so
                // the tile reads as the card it opens.
                'label' => $menu->getTranslations('name'),
                'action' => HomeTileAction::Menu,
                'position' => $position,
                'is_active' => true,
                'tenant_id' => $tenant->getKey(),
                'home_row_id' => $row->getKey(),
                'menu_id' => $menu->getKey(),
            ]);
        }

        // A second row, of small circles: App\Enums\HomeRowLayout::Links,
        // for the places this tenant is also found — the guest app draws
        // these as circular rather than the banner's rectangles.
        $links = HomeRow::query()->firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'position' => 1],
            ['layout' => HomeRowLayout::Links, 'is_active' => true],
        );

        $this->firstOrCreateByEnglishName(
            HomeTile::query()->where('home_row_id', $links->getKey()),
            ['en' => 'Instagram', 'ta' => 'இன்ஸ்டாகிராம்'],
            fn (): HomeTile => new HomeTile([
                'action' => HomeTileAction::Link,
                'url' => 'https://instagram.com/'.$tenant->slug,
                'position' => 0,
                'is_active' => true,
            ]),
            ['tenant_id' => $tenant->getKey(), 'home_row_id' => $links->getKey()],
            'label',
        );

        $this->firstOrCreateByEnglishName(
            HomeTile::query()->where('home_row_id', $links->getKey()),
            ['en' => 'WhatsApp', 'ta' => 'வாட்ஸ்அப்'],
            fn (): HomeTile => new HomeTile([
                'action' => HomeTileAction::Link,
                'url' => 'https://wa.me/91'.$tenant->phone,
                'position' => 1,
                'is_active' => true,
            ]),
            ['tenant_id' => $tenant->getKey(), 'home_row_id' => $links->getKey()],
            'label',
        );

        // A third row, App\Enums\HomeRowLayout::Carousel, holding the one
        // App\Enums\HomeTileAction::Pdf tile in the seed: there is no
        // photography to seed (see the banner's own comment above), so a
        // document is what this layout carries instead of pictures.
        $carousel = HomeRow::query()->firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'position' => 2],
            ['layout' => HomeRowLayout::Carousel, 'is_active' => true],
        );

        $this->firstOrCreateByEnglishName(
            HomeTile::query()->where('home_row_id', $carousel->getKey()),
            ['en' => 'FSSAI License', 'ta' => 'FSSAI உரிமம்'],
            fn (): HomeTile => new HomeTile([
                'action' => HomeTileAction::Pdf,
                // No file actually sits at this path — there is nothing to
                // upload for a seeder — but TileController 404s a missing
                // one rather than erroring, and the row demonstrates the
                // shape an admin's own upload would take.
                'document_path' => 'documents/'.$tenant->slug.'-fssai-license.pdf',
                'position' => 0,
                'is_active' => true,
            ]),
            ['tenant_id' => $tenant->getKey(), 'home_row_id' => $carousel->getKey()],
            'label',
        );
    }

    /**
     * Find a record by the English half of a translated column, or make it.
     *
     * @template TModel of Menu|MenuCategory|MenuItem|MenuAddOnGroup|MenuAddOnOption|MenuCombo|HomeTile|Charge
     *
     * @param  Builder<TModel>  $query  already narrowed to the right parent
     * @param  array<string, string>  $translations  the name in every language
     * @param  Closure(): TModel  $make  a new, unsaved record with its own columns set
     * @param  array<string, mixed>  $owner  the keys tying it to its tenant and parent
     * @return TModel
     */
    private function firstOrCreateByEnglishName(
        Builder $query,
        array $translations,
        Closure $make,
        array $owner,
        string $column = 'name',
    ): Model {
        $english = Locale::default()->value;

        $record = (clone $query)
            ->where($column.'->'.$english, $translations[$english])
            ->first();

        $record ??= $make();

        $record->setTranslations($column, $translations);
        $record->forceFill($owner)->save();

        return $record;
    }
}
