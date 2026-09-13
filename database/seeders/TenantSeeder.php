<?php

namespace Database\Seeders;

use App\Enums\CountryCallingCode;
use App\Enums\Currency;
use App\Enums\FoodType;
use App\Enums\HomeRowLayout;
use App\Enums\HomeTileAction;
use App\Enums\ItemAvailability;
use App\Enums\Locale;
use App\Enums\Role;
use App\Models\HomeRow;
use App\Models\HomeTile;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuCombo;
use App\Models\MenuComboItem;
use App\Models\MenuItem;
use App\Models\MenuItemAddition;
use App\Models\Restaurant;
use App\Models\User;
use Closure;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * One restaurant, its settings, and the one administrator who runs it.
 *
 * The admin address uses plus-addressing so every restaurant gets a distinct
 * account while the sign-in codes all land in the same real inbox.
 */
class RestaurantSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The restaurants to seed, keyed by the slug that becomes their subdomain.
     *
     * @var list<array{slug: string, name: string, address: string, pincode: string, email: string, phone: string, admin_name: string, admin_email: string, staff_name: string, staff_email: string}>
     */
    public const array RESTAURANTS = [
        [
            'slug' => 'spice',
            'name' => 'Spice Garden',
            'address' => '12 Mount Road, Chennai',
            'pincode' => '600002',
            'email' => 'hello@spicegarden.example.com',
            'phone' => '9876543210',
            'admin_name' => 'Spice Garden Admin',
            'admin_email' => 'gopalwebdev+spice@gmail.com',
            'staff_name' => 'Spice Garden Staff',
            'staff_email' => 'gopalwebdev+spice-staff@gmail.com',
        ],
    ];

    /**
     * The name of the one menu every seeded restaurant gets, in both languages.
     *
     * Seeded copy is bilingual on purpose: it is the only way to see that the
     * language toggle in the guest app really does anything without
     * typing a Tamil menu out by hand first.
     *
     * @var array<string, string>
     */
    public const array MENU_NAME = ['en' => 'Main Menu', 'ta' => 'முதன்மை மெனு'];

    /**
     * The second menu every seeded restaurant gets.
     *
     * One menu was enough to read a storefront but not enough to work the
     * panel: moving a category to another menu, and filing one under the right
     * card, both need somewhere to move it to.
     *
     * @var array<string, string>
     */
    public const array DRINKS_MENU_NAME = ['en' => 'Drinks', 'ta' => 'பானங்கள்'];

    /**
     * A third menu, served only between two times of day.
     *
     * It exists to make the two things that need somewhere to go actually
     * testable: a menu with a service window, and a third card to move a
     * category onto.
     *
     * @var array<string, string>
     */
    public const array BREAKFAST_MENU_NAME = ['en' => 'Breakfast', 'ta' => 'காலை உணவு'];

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
                            'price_minor_units' => 11000,
                            'food_type' => FoodType::Vegetarian,
                            'is_featured' => true,
                            'featured_position' => 1,
                            'additions' => [
                                ['name' => ['en' => 'Extra chutney', 'ta' => 'கூடுதல் சட்னி'], 'price_minor_units' => 1500],
                                ['name' => ['en' => 'Extra sambar', 'ta' => 'கூடுதல் சாம்பார்'], 'price_minor_units' => 1500],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Ghee Roast', 'ta' => 'நெய் ரோஸ்ட்'],
                            'price_minor_units' => 13000,
                            'food_type' => FoodType::Vegetarian,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Idli and Vada', 'ta' => 'இட்லி மற்றும் வடை'],
                    'items' => [
                        [
                            'name' => ['en' => 'Idli Plate', 'ta' => 'இட்லி பிளேட்'],
                            'price_minor_units' => 8000,
                            'food_type' => FoodType::Vegetarian,
                        ],
                        [
                            'name' => ['en' => 'Medu Vada', 'ta' => 'மெது வடை'],
                            'price_minor_units' => 7000,
                            'food_type' => FoodType::Vegetarian,
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => ['en' => 'Egg Dishes', 'ta' => 'முட்டை உணவுகள்'],
            'items' => [
                [
                    'name' => ['en' => 'Egg Bhurji', 'ta' => 'முட்டை பூர்ஜி'],
                    'price_minor_units' => 12000,
                    'food_type' => FoodType::Egg,
                ],
                [
                    'name' => ['en' => 'Omelette', 'ta' => 'ஆம்லெட்'],
                    'price_minor_units' => 9000,
                    'food_type' => FoodType::Egg,
                ],
            ],
        ],
    ];

    /**
     * The bundles the main menu leads with, beside its featured dishes.
     *
     * Priced below what the dishes come to separately, which is the whole
     * point of a combo — the contents are named so a guest can see what they
     * are getting, never to be added up.
     *
     * @var list<array{
     *     name: array<string, string>,
     *     description: array<string, string>,
     *     price_minor_units: int,
     *     compare_at_price_minor_units: int,
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
            'price_minor_units' => 59900,
            'compare_at_price_minor_units' => 75900,
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
            'price_minor_units' => 44900,
            'compare_at_price_minor_units' => 58800,
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
            'price_minor_units' => 129900,
            'compare_at_price_minor_units' => 159600,
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
            'price_minor_units' => 39900,
            'compare_at_price_minor_units' => 44900,
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
                    'price_minor_units' => 5000,
                    'food_type' => FoodType::Vegetarian,
                    'is_featured' => true,
                    'featured_position' => 1,
                    'additions' => [
                        ['name' => ['en' => 'Less sugar', 'ta' => 'குறைந்த சர்க்கரை'], 'price_minor_units' => 0],
                        ['name' => ['en' => 'Extra strong', 'ta' => 'கூடுதல் கடுமையான'], 'price_minor_units' => 1000],
                    ],
                ],
                [
                    'name' => ['en' => 'Masala Chai', 'ta' => 'மசாலா டீ'],
                    'price_minor_units' => 4000,
                    'food_type' => FoodType::Vegetarian,
                ],
                [
                    'name' => ['en' => 'Badam Milk', 'ta' => 'பாதாம் பால்'],
                    'price_minor_units' => 7000,
                    'food_type' => FoodType::Vegetarian,
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
                            'price_minor_units' => 8000,
                            'food_type' => FoodType::Vegetarian,
                            'additions' => [
                                ['name' => ['en' => 'Sweet', 'ta' => 'இனிப்பு'], 'price_minor_units' => 0],
                                ['name' => ['en' => 'Salted', 'ta' => 'உப்பு'], 'price_minor_units' => 0],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Watermelon Juice', 'ta' => 'தர்பூசணி ஜூஸ்'],
                            'price_minor_units' => 9000,
                            'food_type' => FoodType::Vegetarian,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Shakes', 'ta' => 'ஷேக்குகள்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Mango Lassi', 'ta' => 'மாம்பழ லஸ்ஸி'],
                            'price_minor_units' => 11000,
                            'compare_at_price_minor_units' => 13000,
                            'food_type' => FoodType::Vegetarian,
                            'is_featured' => true,
                            'featured_position' => 2,
                        ],
                        [
                            'name' => ['en' => 'Cold Coffee', 'ta' => 'கோல்ட் காபி'],
                            'price_minor_units' => 12000,
                            'food_type' => FoodType::Vegetarian,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Bottled', 'ta' => 'பாட்டில்'],
                    'items' => [
                        [
                            // Sealed goods rather than restaurant service, and
                            // an aerated drink at that — 40% under GST 2.0.
                            'name' => ['en' => 'Cola', 'ta' => 'கோலா'],
                            'price_minor_units' => 6000,
                            'food_type' => FoodType::Vegetarian,
                            'tax_rate_basis_points' => 4000,
                        ],
                        [
                            'name' => ['en' => 'Mineral Water', 'ta' => 'மினரல் வாட்டர்'],
                            'price_minor_units' => 2000,
                            'food_type' => FoodType::Vegetarian,
                            'tax_rate_basis_points' => 1800,
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
                            'price_minor_units' => 24950,
                            'food_type' => FoodType::Vegetarian,
                            'is_featured' => true,
                            'featured_position' => 1,
                            'additions' => [
                                ['name' => ['en' => 'Extra paneer', 'ta' => 'கூடுதல் பன்னீர்'], 'price_minor_units' => 5000],
                                ['name' => ['en' => 'Less spicy', 'ta' => 'குறைந்த காரம்'], 'price_minor_units' => 0],
                                ['name' => ['en' => 'Extra mint chutney', 'ta' => 'கூடுதல் புதினா சட்னி'], 'price_minor_units' => 2000],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Gobi Manchurian', 'ta' => 'கோபி மஞ்சூரியன்'],
                            'price_minor_units' => 21000,
                            'food_type' => FoodType::Vegetarian,
                            'additions' => [
                                ['name' => ['en' => 'Make it dry', 'ta' => 'உலர்ந்ததாக'], 'price_minor_units' => 0],
                                ['name' => ['en' => 'Extra gravy', 'ta' => 'கூடுதல் கிரேவி'], 'price_minor_units' => 3000],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Mushroom 65', 'ta' => 'காளான் 65'],
                            'price_minor_units' => 23000,
                            'food_type' => FoodType::Vegetarian,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Non-vegetarian', 'ta' => 'அசைவம்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Chicken 65', 'ta' => 'சிக்கன் 65'],
                            'price_minor_units' => 29900,
                            'compare_at_price_minor_units' => 34900,
                            'food_type' => FoodType::NonVegetarian,
                            'is_featured' => true,
                            'featured_position' => 2,
                            'additions' => [
                                ['name' => ['en' => 'Boneless', 'ta' => 'எலும்பு இல்லாமல்'], 'price_minor_units' => 4000],
                                ['name' => ['en' => 'Extra curry leaves', 'ta' => 'கூடுதல் கறிவேப்பிலை'], 'price_minor_units' => 0],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Apollo Fish', 'ta' => 'அப்பல்லோ மீன்'],
                            'price_minor_units' => 34900,
                            'food_type' => FoodType::NonVegetarian,
                        ],
                        [
                            'name' => ['en' => 'Prawn Koliwada', 'ta' => 'இறால் கோலிவாடா'],
                            'price_minor_units' => 39900,
                            'food_type' => FoodType::NonVegetarian,
                            'availability' => ItemAvailability::OutOfStock,
                        ],
                        [
                            'name' => ['en' => 'Egg Pepper Fry', 'ta' => 'முட்டை மிளகு வறுவல்'],
                            'price_minor_units' => 19900,
                            'food_type' => FoodType::Egg,
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
                    'price_minor_units' => 14900,
                    'food_type' => FoodType::Vegetarian,
                ],
                [
                    'name' => ['en' => 'Hot and Sour Soup', 'ta' => 'ஹாட் அண்ட் சார் சூப்'],
                    'price_minor_units' => 15900,
                    'food_type' => FoodType::Vegetarian,
                    'additions' => [
                        ['name' => ['en' => 'Add chicken', 'ta' => 'சிக்கன் சேர்க்க'], 'price_minor_units' => 5000],
                    ],
                ],
                [
                    'name' => ['en' => 'Mutton Paya Soup', 'ta' => 'மட்டன் பாயா சூப்'],
                    'price_minor_units' => 21900,
                    'food_type' => FoodType::NonVegetarian,
                ],
            ],
        ],
        [
            // The category that shows what subdivisions are for: three of them,
            // each with dishes, plus one dish filed straight under the section.
            'name' => ['en' => 'Biryani', 'ta' => 'பிரியாணி'],
            'items' => [
                [
                    'name' => ['en' => 'Egg Biryani', 'ta' => 'முட்டை பிரியாணி'],
                    'price_minor_units' => 27500,
                    'food_type' => FoodType::Egg,
                ],
            ],
            'sub_categories' => [
                [
                    'name' => ['en' => 'Chicken', 'ta' => 'சிக்கன்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Hyderabadi Chicken Biryani', 'ta' => 'ஹைதராபாதி சிக்கன் பிரியாணி'],
                            'price_minor_units' => 38000,
                            'compare_at_price_minor_units' => 45000,
                            'food_type' => FoodType::NonVegetarian,
                            'is_featured' => true,
                            'featured_position' => 3,
                            'additions' => [
                                ['name' => ['en' => 'Extra raita', 'ta' => 'கூடுதல் ராய்தா'], 'price_minor_units' => 3000],
                                ['name' => ['en' => 'Boiled egg', 'ta' => 'வேகவைத்த முட்டை'], 'price_minor_units' => 2500],
                                ['name' => ['en' => 'Extra gravy', 'ta' => 'கூடுதல் கிரேவி'], 'price_minor_units' => 3500],
                                ['name' => ['en' => 'No raita', 'ta' => 'ராய்தா வேண்டாம்'], 'price_minor_units' => 0],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Chicken 65 Biryani', 'ta' => 'சிக்கன் 65 பிரியாணி'],
                            'price_minor_units' => 41000,
                            'food_type' => FoodType::NonVegetarian,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Mutton', 'ta' => 'மட்டன்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Mutton Dum Biryani', 'ta' => 'மட்டன் தம் பிரியாணி'],
                            'price_minor_units' => 46000,
                            'food_type' => FoodType::NonVegetarian,
                            'additions' => [
                                ['name' => ['en' => 'Extra mutton', 'ta' => 'கூடுதல் மட்டன்'], 'price_minor_units' => 12000],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Mutton Keema Biryani', 'ta' => 'மட்டன் கீமா பிரியாணி'],
                            'price_minor_units' => 44000,
                            'food_type' => FoodType::NonVegetarian,
                            'availability' => ItemAvailability::TemporarilyUnavailable,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Vegetable', 'ta' => 'காய்கறி'],
                    'items' => [
                        [
                            'name' => ['en' => 'Vegetable Dum Biryani', 'ta' => 'வெஜிடபிள் தம் பிரியாணி'],
                            'price_minor_units' => 30000,
                            'food_type' => FoodType::Vegetarian,
                        ],
                        [
                            'name' => ['en' => 'Paneer Biryani', 'ta' => 'பன்னீர் பிரியாணி'],
                            'price_minor_units' => 33000,
                            'food_type' => FoodType::Vegetarian,
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
                            'price_minor_units' => 28900,
                            'food_type' => FoodType::Vegetarian,
                            'additions' => [
                                ['name' => ['en' => 'Extra butter', 'ta' => 'கூடுதல் வெண்ணெய்'], 'price_minor_units' => 2000],
                            ],
                        ],
                        [
                            'name' => ['en' => 'Dal Tadka', 'ta' => 'தால் தட்கா'],
                            'price_minor_units' => 21900,
                            'food_type' => FoodType::Vegetarian,
                        ],
                    ],
                ],
                [
                    'name' => ['en' => 'Non-vegetarian', 'ta' => 'அசைவம்'],
                    'items' => [
                        [
                            'name' => ['en' => 'Butter Chicken', 'ta' => 'பட்டர் சிக்கன்'],
                            'price_minor_units' => 36900,
                            'food_type' => FoodType::NonVegetarian,
                            'is_featured' => true,
                            'featured_position' => 4,
                        ],
                        [
                            'name' => ['en' => 'Chettinad Chicken', 'ta' => 'செட்டிநாடு சிக்கன்'],
                            'price_minor_units' => 35900,
                            'food_type' => FoodType::NonVegetarian,
                        ],
                        [
                            'name' => ['en' => 'Mutton Rogan Josh', 'ta' => 'மட்டன் ரோகன் ஜோஷ்'],
                            'price_minor_units' => 44900,
                            'food_type' => FoodType::NonVegetarian,
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
                    'price_minor_units' => 8000,
                    'food_type' => FoodType::Vegetarian,
                    'additions' => [
                        ['name' => ['en' => 'Extra butter', 'ta' => 'கூடுதல் வெண்ணெய்'], 'price_minor_units' => 2000],
                        ['name' => ['en' => 'Add garlic', 'ta' => 'பூண்டு சேர்க்க'], 'price_minor_units' => 2500],
                    ],
                ],
                [
                    'name' => ['en' => 'Tandoori Roti', 'ta' => 'தந்தூரி ரொட்டி'],
                    'price_minor_units' => 5000,
                    'food_type' => FoodType::Vegetarian,
                ],
                [
                    'name' => ['en' => 'Laccha Paratha', 'ta' => 'லச்சா பராத்தா'],
                    'price_minor_units' => 7000,
                    'food_type' => FoodType::Vegetarian,
                ],
                [
                    'name' => ['en' => 'Kerala Parotta', 'ta' => 'கேரள பரோட்டா'],
                    'price_minor_units' => 4500,
                    'food_type' => FoodType::Vegetarian,
                ],
            ],
        ],
        [
            'name' => ['en' => 'Desserts', 'ta' => 'இனிப்புகள்'],
            'items' => [
                [
                    'name' => ['en' => 'Gulab Jamun', 'ta' => 'குலாப் ஜாமூன்'],
                    'price_minor_units' => 12000,
                    'food_type' => FoodType::Vegetarian,
                ],
                [
                    'name' => ['en' => 'Rasmalai', 'ta' => 'ரஸ்மலாய்'],
                    'price_minor_units' => 14000,
                    'food_type' => FoodType::Vegetarian,
                    'is_featured' => true,
                    'featured_position' => 5,
                ],
                [
                    'name' => ['en' => 'Double Ka Meetha', 'ta' => 'டபுள் கா மீதா'],
                    'price_minor_units' => 13000,
                    'food_type' => FoodType::Vegetarian,
                ],
                [
                    // Sold as a sealed tub rather than as restaurant service,
                    // so it carries a rate of its own — 18%, not the 5% the
                    // rest of the card follows.
                    'name' => ['en' => 'Ice Cream Tub', 'ta' => 'ஐஸ்கிரீம் டப்'],
                    'price_minor_units' => 18000,
                    'food_type' => FoodType::Vegetarian,
                    'tax_rate_basis_points' => 1800,
                ],
            ],
        ],
    ];

    /**
     * Seed every restaurant, its people, and the menus they serve.
     */
    public function run(): void
    {
        foreach (self::RESTAURANTS as $definition) {
            $restaurant = Restaurant::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'address' => $definition['address'],
                    'pincode' => $definition['pincode'],
                    'email' => $definition['email'],
                    'phone_country_code' => CountryCallingCode::India,
                    'phone' => $definition['phone'],
                    'is_active' => true,
                ],
            );

            $restaurant->settings()->firstOrCreate([], [
                'contact_email' => "hello@{$definition['slug']}.example.com",
                'contact_phone' => '+91 98765 43210',
                'currency' => Currency::IndianRupee,
                'accepts_orders' => true,
                'opens_at' => '09:00:00',
                'closes_at' => '23:00:00',
            ]);

            // The admin belongs to this restaurant, not the product team: a
            // null tenant_id would file them under "Product team" in the
            // platform panel, which they are not.
            $admin = User::query()->firstOrCreate(
                ['email' => $definition['admin_email']],
                ['name' => $definition['admin_name'], 'tenant_id' => $restaurant->getKey(), 'email_verified_at' => now()],
            );

            $admin->syncRoles([Role::Admin->value]);
            $restaurant->users()->syncWithoutDetaching([$admin->getKey()]);

            // A staff account for the roster and its limits. Plus-addressing means every
            // seeded account's sign-in code lands in the same real inbox.
            $staff = User::query()->firstOrCreate(
                ['email' => $definition['staff_email']],
                ['name' => $definition['staff_name'], 'tenant_id' => $restaurant->getKey(), 'email_verified_at' => now()],
            );

            $staff->syncRoles([Role::Staff->value]);
            $restaurant->users()->syncWithoutDetaching([$staff->getKey()]);

            $this->seedMenu($restaurant);
        }
    }

    /**
     * A small bilingual menu, so a fresh install has something to look at.
     *
     * Prices are in the minor unit, as the column is: 24950 is ₹249.50.
     *
     * Every lookup matches on the English name rather than the whole translated
     * column, because a JSON document only compares equal when every language
     * in it does — which would make this seeder duplicate its own menu the
     * first time a restaurant translated one dish.
     */
    private function seedMenu(Restaurant $restaurant): void
    {
        $menu = $this->firstOrCreateByEnglishName(
            Menu::query()->where('tenant_id', $restaurant->getKey()),
            self::MENU_NAME,
            fn (): Menu => new Menu(['position' => 0, 'is_active' => true]),
            ['tenant_id' => $restaurant->getKey()],
        );

        $this->seedCard($restaurant, $menu, self::MENU);

        $drinks = $this->firstOrCreateByEnglishName(
            Menu::query()->where('tenant_id', $restaurant->getKey()),
            self::DRINKS_MENU_NAME,
            fn (): Menu => new Menu(['position' => 1, 'is_active' => true]),
            ['tenant_id' => $restaurant->getKey()],
        );

        $this->seedCard($restaurant, $drinks, self::DRINKS);

        $breakfast = $this->firstOrCreateByEnglishName(
            Menu::query()->where('tenant_id', $restaurant->getKey()),
            self::BREAKFAST_MENU_NAME,
            fn (): Menu => new Menu([
                'position' => 2,
                'is_active' => true,
                'available_from' => self::BREAKFAST_FROM,
                'available_until' => self::BREAKFAST_UNTIL,
            ]),
            ['tenant_id' => $restaurant->getKey()],
        );

        $this->seedCard($restaurant, $breakfast, self::BREAKFAST);

        // After the cards, because a combo names dishes that have to exist.
        $this->seedCombos($restaurant, $menu);

        // Every menu gets a way in. A card a guest cannot reach is a card that
        // may as well not be there.
        $this->seedHomeScreen($restaurant, [$menu, $drinks, $breakfast]);
    }

    /**
     * One menu's categories, their dishes, and each dish's additions.
     *
     * @param  list<array<string, mixed>>  $card
     */
    private function seedCard(Restaurant $restaurant, Menu $menu, array $card): void
    {
        foreach ($card as $position => $section) {
            $category = $this->seedCategory($restaurant, $menu, $section['name'], $position);

            foreach ($section['items'] ?? [] as $itemPosition => $item) {
                $this->seedDish($restaurant, $category, $item, $itemPosition);
            }

            // Subdivisions are rows of the same table with a parent, so they
            // are seeded by the same call — only `parent` differs.
            foreach ($section['sub_categories'] ?? [] as $subPosition => $subSection) {
                $subCategory = $this->seedCategory($restaurant, $menu, $subSection['name'], $subPosition, $category);

                foreach ($subSection['items'] as $itemPosition => $item) {
                    $this->seedDish($restaurant, $subCategory, $item, $itemPosition);
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
        Restaurant $restaurant,
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
                'tenant_id' => $restaurant->getKey(),
                'menu_id' => $menu->getKey(),
                'parent_id' => $parent?->getKey(),
            ],
        );
    }

    /**
     * One dish, its offer if it has one, and its additions.
     *
     * The category may be a section or one of its subdivisions; a dish is filed
     * under exactly one either way.
     *
     * @param  array<string, mixed>  $item
     */
    private function seedDish(
        Restaurant $restaurant,
        MenuCategory $category,
        array $item,
        int $position,
    ): void {
        $dish = $this->firstOrCreateByEnglishName(
            MenuItem::query()->where('menu_category_id', $category->getKey()),
            $item['name'],
            fn (): MenuItem => new MenuItem([
                'price_minor_units' => $item['price_minor_units'],
                // Null on almost every dish: not on offer. A zero would be a
                // price of nothing.
                'compare_at_price_minor_units' => $item['compare_at_price_minor_units'] ?? null,
                'food_type' => $item['food_type'],
                'availability' => $item['availability'] ?? ItemAvailability::Available,
                'is_featured' => $item['is_featured'] ?? false,
                'featured_position' => $item['featured_position'] ?? 0,
                'tax_rate_basis_points' => $item['tax_rate_basis_points'] ?? null,
                'position' => $position,
            ]),
            [
                'tenant_id' => $restaurant->getKey(),
                'menu_category_id' => $category->getKey(),
            ],
        );

        foreach ($item['additions'] ?? [] as $additionPosition => $addition) {
            $this->firstOrCreateByEnglishName(
                MenuItemAddition::query()->where('menu_item_id', $dish->getKey()),
                $addition['name'],
                fn (): MenuItemAddition => new MenuItemAddition([
                    'price_minor_units' => $addition['price_minor_units'],
                    'is_available' => true,
                    'position' => $additionPosition,
                ]),
                ['tenant_id' => $restaurant->getKey(), 'menu_item_id' => $dish->getKey()],
            );
        }
    }

    /**
     * The bundles one menu leads with, and what is in each.
     *
     * A combo's contents are looked up by the English name of a dish already
     * seeded onto this menu. A name that finds nothing is skipped rather than
     * failing the seed: the combo is still a working combo one line shorter,
     * and a half-seeded database is worse than a slightly smaller one.
     */
    private function seedCombos(Restaurant $restaurant, Menu $menu): void
    {
        foreach (self::COMBOS as $position => $definition) {
            $combo = $this->firstOrCreateByEnglishName(
                MenuCombo::query()->where('menu_id', $menu->getKey()),
                $definition['name'],
                fn (): MenuCombo => new MenuCombo([
                    'description' => $definition['description'],
                    'price_minor_units' => $definition['price_minor_units'],
                    'compare_at_price_minor_units' => $definition['compare_at_price_minor_units'],
                    'availability' => ItemAvailability::Available,
                    'position' => $position,
                ]),
                ['tenant_id' => $restaurant->getKey(), 'menu_id' => $menu->getKey()],
            );

            foreach ($definition['contents'] as $contentPosition => $content) {
                $dish = MenuItem::query()
                    ->where('tenant_id', $restaurant->getKey())
                    ->onMenu($menu->getKey())
                    ->where('name->'.Locale::English->value, $content['name'][Locale::English->value])
                    ->first();

                if (! $dish instanceof MenuItem) {
                    continue;
                }

                MenuComboItem::query()->firstOrCreate(
                    ['menu_combo_id' => $combo->getKey(), 'menu_item_id' => $dish->getKey()],
                    [
                        'tenant_id' => $restaurant->getKey(),
                        'quantity' => $content['quantity'],
                        'position' => $contentPosition,
                    ],
                );
            }
        }
    }

    /**
     * The tiles a freshly seeded restaurant's guests land on: one per menu.
     *
     * A guest only ever reaches a menu through a tile, so seeding one tile left
     * the drinks and breakfast cards with no way in — they existed, and nobody
     * arriving at a table could get to them.
     *
     * Matched on the menu each tile opens rather than on its label, so
     * re-seeding never doubles up and the tile a restaurant has already
     * relabelled keeps its own words.
     *
     * No picture: there is no photography to seed, and a tile without one is a
     * working tile — the guest app draws the label on the brand colour.
     *
     * @param  list<Menu>  $menus  in the order a guest should read them
     */
    private function seedHomeScreen(Restaurant $restaurant, array $menus): void
    {
        // One banner row: full-width rectangles, one tap target per line, which
        // is the shape a menu tile wants. A rail of photographs beside it is
        // something a restaurant adds from the panel.
        $row = HomeRow::query()->firstOrCreate(
            ['tenant_id' => $restaurant->getKey(), 'position' => 0],
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
                'tenant_id' => $restaurant->getKey(),
                'home_row_id' => $row->getKey(),
                'menu_id' => $menu->getKey(),
            ]);
        }
    }

    /**
     * Find a record by the English half of a translated column, or make it.
     *
     * @template TModel of Menu|MenuCategory|MenuItem|MenuItemAddition|MenuCombo|HomeTile
     *
     * @param  Builder<TModel>  $query  already narrowed to the right parent
     * @param  array<string, string>  $translations  the name in every language
     * @param  Closure(): TModel  $make  a new, unsaved record with its own columns set
     * @param  array<string, mixed>  $owner  the keys tying it to its restaurant and parent
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
