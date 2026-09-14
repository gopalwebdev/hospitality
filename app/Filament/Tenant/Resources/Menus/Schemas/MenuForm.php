<?php

namespace App\Filament\Tenant\Resources\Menus\Schemas;

use App\Filament\Schemas\TranslatedFields;
use App\Models\Menu;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class MenuForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * Named once here and read by every page that fills the form, so adding a
     * translated field to the schema cannot leave the fill behind.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name', 'description'];

    /**
     * Two compact sections, side by side where the form has room and stacked where it has not.
     *
     * The breakpoint is the form's own width (a grid container) rather than the
     * window's, which is what tells the menu's Edit tab from the narrower create
     * modal on the same laptop. There used to be four full-width sections.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Grid::make(['default' => 1, '@3xl' => 3])
                    ->gridContainer()
                    ->schema([
                        Section::make(__('panel.menus.details_section'))
                            ->icon(Heroicon::OutlinedBookOpen)
                            ->compact()
                            ->columnSpan(['default' => 1, '@3xl' => 2])
                            ->schema([
                                ...TranslatedFields::text(
                                    'name',
                                    __('panel.shared.name'),
                                    maxLength: 64,
                                    // Scoped to the tenant by Filament's global scope, so
                                    // two tenants may both have a "Dinner" and one
                                    // tenant may not have it twice.
                                    uniqueWithin: fn (): Builder => Menu::query(),
                                    uniqueMessage: __('panel.menus.unique'),
                                ),

                                ...TranslatedFields::textarea('description', __('panel.shared.description'), maxLength: 300, rows: 3),
                            ]),

                        Section::make(__('panel.menus.storefront_section'))
                            ->icon(Heroicon::OutlinedEye)
                            ->compact()
                            ->columns(2)
                            ->schema([
                                Toggle::make('is_active')
                                    ->label(__('panel.menus.is_active'))
                                    ->default(true)
                                    ->columnSpanFull(),

                                // Both or neither: a window with one end is not a
                                // window, so each requires the other rather than the
                                // missing half being guessed at.
                                TimePicker::make('available_from')
                                    ->label(__('panel.menus.available_from'))
                                    ->live(onBlur: true)
                                    ->requiredWith('available_until'),

                                TimePicker::make('available_until')
                                    ->label(__('panel.menus.available_until'))
                                    ->live(onBlur: true)
                                    ->requiredWith('available_from'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Put every language back into the form when a menu is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, Menu $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }
}
