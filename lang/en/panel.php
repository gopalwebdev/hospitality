<?php

/*
|--------------------------------------------------------------------------
| Tenant Panel
|--------------------------------------------------------------------------
|
| The parts of the tenant panel that follow the language chosen in
| its top bar. Deliberately only the menu and storefront surfaces: those are
| what a tenant works in daily, and what a Tamil-speaking manager needs to
| read. Roles, permissions and accounts stay in English — they are the product
| team's vocabulary and code refers to them by name.
|
| What a tenant typed itself — menu, section, dish, addition and tile names
| — is not here. That lives in translated database columns; see
| .ai/rules/models.md.
|
*/

return [

    'language' => [
        'label' => 'Language',
    ],

    'navigation' => [
        'menu' => 'Menu',
        'storefront' => 'Storefront',
    ],

    'shared' => [
        'editing_language' => 'Editing in',
        'fallback_required' => 'The :field is required in :language. Switch to :language and fill it in.',
        'rearrange' => 'Rearrange',
        'rearrange_done' => 'Done',
        'showing' => 'Showing',
        'name' => 'Name',
        'description' => 'Description',
    ],

    'menus' => [
        'label' => 'menu',
        'plural' => 'menus',
        'create' => 'New menu',
        'name_section' => 'Name',
        'description_section' => 'Description',
        'storefront_section' => 'On the storefront',
        'is_active' => 'Showing to guests',
        'service_window' => 'Hours served',
        'available_from' => 'Served from',
        'available_until' => 'Served until',
        'unique' => 'This tenant already has a menu with that name.',
        'delete_warning' => 'Every section on this menu is deleted with it, and every dish in those sections. Hide it instead to take it off the storefront and keep everything.',
    ],

    'arrangement' => [
        'title' => 'Arrangement',
        'name' => 'On this menu',
        'type' => 'Kind',
        'contents' => 'Contents',
        'rail' => 'Row',
        'hidden' => 'Hidden',
        'actions' => 'Actions',
        'rename' => 'Rename',
        'delete' => 'Delete',
        'create_category' => 'New category',
        'category_created' => 'Category added',
        'sub_category_created' => 'Sub-category added',
        'saved' => 'Saved',
        'deleted' => 'Deleted',
        'open_rail' => 'Open',
        'open_dish' => 'Edit this dish',
        'open_dishes' => 'Dishes in this category',
        'dishes_count' => '{0} No dishes|{1} 1 dish|[2,*] :count dishes',
        'combos_count' => '{0} No combos|{1} 1 combo|[2,*] :count combos',
        'sub_categories_count' => '{0} no sub-categories|{1} 1 sub-category|[2,*] :count sub-categories',
        'featured_description' => 'The dishes this menu opens with. Turn on Featured when editing a dish to lead with it.',
        'combos_description' => 'Dishes sold together for one price.',
        'empty_heading' => 'Nothing on this menu yet',
        'empty_description' => 'A menu is read section by section — Starters, Biryani, Desserts. Add the first category, then fill it with dishes.',
    ],

    'categories' => [
        'plural' => 'Categories',
        'create' => 'New category',
        'section' => 'Category',
        'menu' => 'Menu',
        'empty_heading' => 'No categories yet',
        'empty_description' => 'A menu is read section by section — Starters, Biryani, Desserts. Add the first one to start filling this menu in.',
        'on_the_menu' => 'On the menu',
        'is_active' => 'Showing on the menu',
        'items_count' => 'Dishes',
        'unique' => 'This menu already has a category with that name.',
        'delete_warning' => 'Every sub-category and every dish in this category is deleted with it. Hide it instead to take it off the menu and keep everything.',
        'move' => 'Move to another menu',
        'move_help' => 'The category keeps its sub-categories, its dishes and its order; only the menu it sits on changes. Dishes it was leading with stop being featured, because the featured row belongs to a menu.',
        'move_target' => 'Menu to move it to',
        'move_none' => 'This tenant has only one menu, so there is nowhere to move this to.',
        'moved' => 'Moved to another menu',
    ],

    'sub_categories' => [
        'plural' => 'Sub-categories',
        'create' => 'New sub-category',
        'section' => 'Sub-category',
        'is_active' => 'Showing on the menu',
        'unique' => 'This category already has a sub-category with that name.',
        'delete_warning' => 'Every dish in this sub-category is deleted with it. Move them up to the category first if you want to keep them.',
        'needs_a_category' => 'Add a category to this menu first.',
        'empty_heading' => 'No sub-categories yet',
        'empty_description' => 'Most menus never need these. Add one when a category has grown long enough to be worth breaking up.',
    ],

    'combos' => [
        'plural' => 'Combos',
        'create' => 'New combo',
        'section' => 'Combo',
        'unique' => 'This menu already has a combo with that name.',
        'contents' => 'What is in it',
        'dish' => 'Dish',
        'quantity' => 'How many',
        'add_dish' => 'Add a dish',
        'duplicate_dish' => 'That dish is already in this combo. Change its quantity instead of adding it twice.',
        'delete_warning' => 'The combo is removed from this menu. The dishes in it are left alone.',
        'empty_heading' => 'No combos yet',
        'empty_description' => 'A menu reads fine without these. Add one when you want to sell a few dishes together for less than their separate prices.',
    ],

    'items' => [
        'label' => 'dish',
        'plural' => 'dishes',
        'create' => 'New dish',
        'dish' => 'Dish',
        'section' => 'Category',
        'needs_a_section' => 'Add a menu and a section to it first.',
        'food_type' => 'Food type',
        'price_section' => 'Price and availability',
        'price' => 'Price',
        'compare_at_price' => 'Original price',
        'compare_at_price_invalid' => 'The original price has to be higher than the price you charge.',
        'availability' => 'Availability',
        'on_offer' => 'On offer',
        'tax_section' => 'Tax',
        'tax_rate' => 'GST rate',
        'hsn_code' => 'HSN / SAC code',
        'type' => 'Type',
        'unique' => 'This category already has a dish with that name.',
        'delete_warning' => 'The additions on this dish are deleted with it, and it is taken out of any combo listing it.',
        'is_featured' => 'Featured',
        'featured_heading' => 'Featured dishes',
        'featured_empty_heading' => 'Nothing featured yet',
        'featured_empty_description' => 'A menu reads fine without this. Turn on Featured when editing a dish to lead with it here.',
    ],

    'additions' => [
        'section' => 'Additions',
        'label' => 'Addition',
        'add' => 'Add an addition',
        'price' => 'Extra charge',
        'is_available' => 'Available',
        'count' => 'Additions',
    ],

    'rows' => [
        'label' => 'home screen row',
        'plural' => 'home screen',
        'heading' => 'Home screen',
        'subheading' => 'What a guest sees after scanning the QR code at their table. Each row is a band of the screen; drag them into the order you want them read.',
        'create' => 'New row',
        'title_section' => 'Heading',
        'title_section_help' => 'Optional. A banner into your menu speaks for itself; a rail of photographs usually wants something over it.',
        'title_field' => 'Heading',
        'layout' => 'Layout',
        'layout_help' => 'How this row draws the tiles inside it.',
        'layout_column' => 'Layout',
        'untitled' => 'No heading',
        'tiles_count' => 'Tiles',
        'placement' => 'In this row',
        'is_active' => 'Showing to guests',
        'empty_heading' => 'No rows yet',
        'empty_description' => 'Guests scanning a table\'s QR code see nothing until there is at least one row here. Start with a banner row that opens your menu.',
        'delete_warning' => 'Every tile in this row is deleted with it. Anything those tiles pointed at — a menu, say — is left alone.',
        'manage_tiles' => 'Tiles',
        'manage_tiles_help' => 'The tiles in this row, in the order a guest reads them.',
    ],

    'tiles' => [
        'create' => 'New tile',
        'label_section' => 'Label',
        'label_section_help' => 'Read out by screen readers, shown on the tile when there is no picture yet, and used as the heading of the page a PDF tile opens.',
        'label_field' => 'Label',
        'picture' => 'Picture',
        'picture_help' => 'The row\'s layout decides the shape it is cropped to. A tile without a picture still works — the guest app draws the label on your brand colour instead.',
        'picture_field_help' => 'JPEG, PNG or WebP, up to 4 MB. Around 1200 by 675 pixels looks right.',
        'picture_column' => 'Picture',
        'no_picture' => 'No picture',
        'destination' => 'Where it goes',
        'destination_help' => 'What happens when a guest taps this tile.',
        'on_tap' => 'On tap',
        'goes_to' => 'Goes to',
        'menu_to_open' => 'Menu to open',
        'menu_to_open_help' => 'Only this tenant\'s menus. A tile pointing at a hidden menu stops being shown.',
        'document' => 'PDF to show',
        'document_help' => 'Up to 10 MB. Shown inside the app, with a back arrow out of it.',
        'an_uploaded_pdf' => 'An uploaded PDF',
        'no_menu_chosen' => 'No menu chosen',
        'placement' => 'In this row',
        'link' => 'Link to open',
        'link_help' => 'The full address, starting with https:// — your Instagram, a WhatsApp link, your own site. It opens in a new tab.',
        'a_link' => 'A link',
        'is_active' => 'Showing to guests',
        'empty_heading' => 'No tiles yet',
        'empty_description' => 'Guests scanning a table\'s QR code see nothing until there is at least one tile here. Start with one that opens your menu.',
        'delete_warning' => 'The tile is removed from the home screen. Anything it pointed at — a menu, say — is left alone.',
    ],

];
