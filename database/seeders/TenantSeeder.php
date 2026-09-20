<?php

namespace Database\Seeders;

use App\Enums\ChargeCalculation;
use App\Enums\CountryCallingCode;
use App\Enums\Currency;
use App\Enums\Diet;
use App\Enums\HomeRowLayout;
use App\Enums\HomeTileAction;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
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
     * hotel is what shows items and service requests sharing one menu.
     *
     * @var list<array{slug: string, name: string, type: TenantType, address: string, pincode: string, email: string, phone: string, owner_name: string, owner_email: string, staff_name: string, staff_email: string, menus: list<string>}>
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
        ],
    ];

    /**
     * Every card a tenant can be seeded with, keyed by the name TENANTS uses.
     *
     * Each is a menu's name in both languages, its sections, whether the combos
     * are seeded onto it, and an optional service window.
     *
     * @var array<string, array{name: array<string, string>, sections: list<array<string, mixed>>, combos?: bool, available_from?: string, available_until?: string}>
     */
    public const array CARDS = [
        'main' => ['name' => self::MENU_NAME, 'sections' => self::MENU, 'combos' => true],
        'in_room_dining' => ['name' => self::IN_ROOM_DINING_MENU_NAME, 'sections' => self::MENU, 'combos' => true],
        'drinks' => ['name' => self::DRINKS_MENU_NAME, 'sections' => self::DRINKS],
        'breakfast' => [
            'name' => self::BREAKFAST_MENU_NAME,
            'sections' => self::BREAKFAST,
            'available_from' => self::BREAKFAST_FROM,
            'available_until' => self::BREAKFAST_UNTIL,
        ],
        'room_requests' => ['name' => self::ROOM_REQUESTS_MENU_NAME, 'sections' => self::ROOM_REQUESTS],
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
     * @var list<array{name: array<string, string>, is_required: bool, max_selections: int|null, options: list<array{name: array<string, string>, price: int, max_quantity?: int, is_default?: bool, stock_quantity?: int}>, items: list<string>}>
     */
    public const array ADD_ON_GROUPS = [
        [
            'name' => ['en' => 'Portion', 'ta' => 'அளவு'],
            'is_required' => true,
            'max_selections' => 1,
            'options' => [
                ['name' => ['en' => 'Half', 'ta' => 'அரை'], 'price' => 0, 'is_default' => true],
                ['name' => ['en' => 'Full', 'ta' => 'முழு'], 'price' => 15000],
            ],
            'items' => ['Hyderabadi Chicken Biryani', 'Chicken 65 Biryani', 'Mutton Dum Biryani', 'Vegetable Dum Biryani', 'Paneer Biryani', 'Egg Biryani'],
        ],
        [
            'name' => ['en' => 'Spice level', 'ta' => 'காரம்'],
            'is_required' => true,
            'max_selections' => 1,
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
            'max_selections' => 1,
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
            'max_selections' => 3,
            'options' => [
                ['name' => ['en' => 'Extra cheese', 'ta' => 'கூடுதல் சீஸ்'], 'price' => 4000, 'max_quantity' => 2],
                ['name' => ['en' => 'Extra paneer', 'ta' => 'கூடுதல் பன்னீர்'], 'price' => 6000, 'stock_quantity' => 15],
                ['name' => ['en' => 'Raita', 'ta' => 'ராய்தா'], 'price' => 3000],
            ],
            'items' => ['Paneer Tikka', 'Paneer Butter Masala', 'Hyderabadi Chicken Biryani', 'Vegetable Dum Biryani'],
        ],
        [
            'name' => ['en' => 'Dosa sides', 'ta' => 'தோசை துணைகள்'],
            'is_required' => false,
            'max_selections' => 4,
            'options' => [
                ['name' => ['en' => 'Extra chutney', 'ta' => 'கூடுதல் சட்னி'], 'price' => 1500, 'max_quantity' => 2],
                ['name' => ['en' => 'Extra sambar', 'ta' => 'கூடுதல் சாம்பார்'], 'price' => 1500, 'max_quantity' => 2],
                ['name' => ['en' => 'Ghee', 'ta' => 'நெய்'], 'price' => 2500],
            ],
            'items' => ['Masala Dosa', 'Ghee Roast', 'Idli Plate'],
        ],
        [
            'name' => ['en' => 'Sugar', 'ta' => 'சர்க்கரை'],
            'is_required' => true,
            'max_selections' => 1,
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
            'max_selections' => 1,
            'options' => [
                ['name' => ['en' => 'Extra strong', 'ta' => 'கூடுதல் கடுமையான'], 'price' => 1000],
            ],
            'items' => ['Filter Coffee'],
        ],
        [
            'name' => ['en' => 'Sweet or salted', 'ta' => 'இனிப்பு அல்லது உப்பு'],
            'is_required' => true,
            'max_selections' => 1,
            'options' => [
                ['name' => ['en' => 'Sweet', 'ta' => 'இனிப்பு'], 'price' => 0, 'is_default' => true],
                ['name' => ['en' => 'Salted', 'ta' => 'உப்பு'], 'price' => 0],
            ],
            'items' => ['Fresh Lime Soda'],
        ],
        [
            // A service request customised like anything else: which pillow.
            'name' => ['en' => 'Pillow type', 'ta' => 'தலையணை வகை'],
            'is_required' => true,
            'max_selections' => 1,
            'options' => [
                ['name' => ['en' => 'Feather', 'ta' => 'இறகு'], 'price' => 0],
                ['name' => ['en' => 'Memory foam', 'ta' => 'மெமரி ஃபோம்'], 'price' => 0],
            ],
            'items' => ['Extra Pillow'],
        ],
        [
            'name' => ['en' => 'Delivery time', 'ta' => 'கொண்டுவரும் நேரம்'],
            'is_required' => false,
            'max_selections' => 1,
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
     * Most of it is a service request and most of that is complimentary, which
     * is what the card is for — it shows a pillow and a bottle of water on one
     * menu, one with no diet mark and no price, the other with both.
     *
     * @var list<array<string, mixed>>
     */
    public const array ROOM_REQUESTS = [
        [
            'name' => ['en' => 'Housekeeping', 'ta' => 'வீட்டு பராமரிப்பு'],
            'items' => [
                [
                    'name' => ['en' => 'Extra Pillow', 'ta' => 'கூடுதல் தலையணை'],
                    'is_service_request' => true,
                    'price' => 0,
                    // Two to an order, however they are split between kinds.
                    'max_quantity' => 2,
                    // Counted: the linen room has only so many.
                    'stock_quantity' => 30,
                    'is_featured' => true,
                    'featured_position' => 1,
                ],
                [
                    'name' => ['en' => 'Extra Blanket', 'ta' => 'கூடுதல் போர்வை'],
                    'is_service_request' => true,
                    'price' => 0,
                    'max_quantity' => 2,
                ],
                [
                    'name' => ['en' => 'Bedsheet Change', 'ta' => 'படுக்கை விரிப்பு மாற்றம்'],
                    'is_service_request' => true,
                    'price' => 0,
                    'max_quantity' => 1,
                ],
                [
                    'name' => ['en' => 'Towel Set', 'ta' => 'துண்டு தொகுப்பு'],
                    'is_service_request' => true,
                    'price' => 0,
                ],
                [
                    // A service request that is charged for, at the rate services pay.
                    'name' => ['en' => 'Laundry Pickup', 'ta' => 'சலவை சேகரிப்பு'],
                    'is_service_request' => true,
                    'price' => 15000,
                    'tax_rate' => 1800,
                ],
            ],
        ],
        [
            'name' => ['en' => 'Bathroom and Drinks', 'ta' => 'குளியலறை மற்றும் பானங்கள்'],
            'items' => [
                [
                    'name' => ['en' => 'Toiletry Kit', 'ta' => 'கழிப்பறை பொருட்கள் தொகுப்பு'],
                    'is_service_request' => true,
                    'price' => 0,
                ],
                [
                    // Something to order on the same card as the pillows: a
                    // bottle of water carries its diet mark and a price.
                    'name' => ['en' => 'Water Bottle (1 L)', 'ta' => 'தண்ணீர் பாட்டில் (1 லி)'],
                    'price' => 4000,
                    'diets' => [Diet::Vegetarian],
                    'tax_rate' => 1800,
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
     *     compare_at_price: int,
     *     contents: list<array{name: array<string, string>, quantity: int}>
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
            'compare_at_price' => 75900,
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
            'compare_at_price' => 58800,
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
            'compare_at_price' => 159600,
            'contents' => [
                ['name' => ['en' => 'Mutton Dum Biryani'], 'quantity' => 2],
                ['name' => ['en' => 'Butter Chicken'], 'quantity' => 1],
                ['name' => ['en' => 'Butter Naan'], 'quantity' => 4],
            ],
        ],
        [
            'name' => ['en' => 'Lunch Box', 'ta' => 'மதிய உணவுப் பெட்டி'],
            'description' => [
                'en' => 'One veg biryani and a soup, packed to go.',
                'ta' => 'ஒரு சைவ பிரியாணி, ஒரு சூப் — பார்சலாக.',
            ],
            'price' => 39900,
            'compare_at_price' => 44900,
            'contents' => [
                ['name' => ['en' => 'Vegetable Dum Biryani'], 'quantity' => 1],
                ['name' => ['en' => 'Sweet Corn Soup'], 'quantity' => 1],
            ],
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
                            'compare_at_price' => 13000,
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
                            // Sealed goods rather than a served drink, and an
                            // aerated one at that — 40% under GST 2.0.
                            'name' => ['en' => 'Cola', 'ta' => 'கோலா'],
                            'price' => 6000,
                            'diets' => [Diet::Vegetarian],
                            'tax_rate' => 4000,
                        ],
                        [
                            'name' => ['en' => 'Mineral Water', 'ta' => 'மினரல் வாட்டர்'],
                            'price' => 2000,
                            'diets' => [Diet::Vegetarian],
                            'tax_rate' => 1800,
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
                            'compare_at_price' => 34900,
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
                            'compare_at_price' => 45000,
                            'diets' => [Diet::NonVegetarian],
                            'is_featured' => true,
                            'featured_position' => 3,
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
                            // biryani combo draws on the same count.
                            'stock_quantity' => 20,
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
     * under exactly one either way. An item is something to order unless its card
     * marks it a service request, and only a service request goes without a diet
     * mark; everything else carries at least one, and a vegan item carries two.
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
                'compare_at_price' => $item['compare_at_price'] ?? null,
                'is_service_request' => $item['is_service_request'] ?? false,
                'diets' => $item['diets'] ?? null,
                'availability' => $item['availability'] ?? ItemAvailability::Available,
                // Null is no limit.
                'max_quantity' => $item['max_quantity'] ?? null,
                // Null is nobody counting.
                'stock_quantity' => $item['stock_quantity'] ?? null,
                'is_featured' => $item['is_featured'] ?? false,
                'featured_position' => $item['featured_position'] ?? 0,
                'tax_rate' => $item['tax_rate'] ?? null,
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
            $itemIds = MenuItem::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('name->'.Locale::English->value, $definition['items'])
                ->pluck('id');

            if ($itemIds->isEmpty()) {
                continue;
            }

            $group = $this->firstOrCreateByEnglishName(
                MenuAddOnGroup::query()->where('tenant_id', $tenant->getKey()),
                $definition['name'],
                fn (): MenuAddOnGroup => new MenuAddOnGroup([
                    'is_required' => $definition['is_required'],
                    'max_selections' => $definition['max_selections'],
                ]),
                ['tenant_id' => $tenant->getKey()],
            );

            foreach ($definition['options'] as $optionPosition => $option) {
                $this->firstOrCreateByEnglishName(
                    MenuAddOnOption::query()->where('menu_add_on_group_id', $group->getKey()),
                    $option['name'],
                    fn (): MenuAddOnOption => new MenuAddOnOption([
                        'price' => $option['price'],
                        'max_quantity' => $option['max_quantity'] ?? 1,
                        'is_default' => $option['is_default'] ?? false,
                        'is_available' => true,
                        // Null is nobody counting.
                        'stock_quantity' => $option['stock_quantity'] ?? null,
                        'position' => $optionPosition,
                    ]),
                    ['tenant_id' => $tenant->getKey(), 'menu_add_on_group_id' => $group->getKey()],
                );
            }

            foreach ($itemIds as $itemId) {
                MenuItemAddOnGroup::query()->firstOrCreate(
                    ['menu_item_id' => $itemId, 'menu_add_on_group_id' => $group->getKey()],
                    ['tenant_id' => $tenant->getKey(), 'position' => $position],
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
                    'compare_at_price' => $definition['compare_at_price'],
                    'availability' => ItemAvailability::Available,
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
