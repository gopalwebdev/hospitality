<?php

namespace App\Filament\Restaurant\Resources\Menus\Schemas;

use App\Filament\Schemas\TranslatedFields;
use App\Models\Menu;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
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

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.menus.name_section'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->schema(TranslatedFields::text(
                        'name',
                        __('panel.shared.name'),
                        maxLength: 64,
                        // Scoped to the tenant by Filament's global scope, so
                        // two restaurants may both have a "Dinner" and one
                        // restaurant may not have it twice.
                        uniqueWithin: fn (): Builder => Menu::query(),
                        uniqueMessage: __('panel.menus.unique'),
                    )),

                Section::make(__('panel.menus.description_section'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->schema(TranslatedFields::textarea('description', __('panel.shared.description'), maxLength: 300, rows: 2))
                    ->collapsed(),

                Section::make(__('panel.menus.storefront_section'))
                    ->icon(Heroicon::OutlinedEye)
                    ->schema([
                        // Where it sits in the list is arranged by dragging
                        // the rows there, not typed here — see Reordering.
                        Toggle::make('is_active')
                            ->label(__('panel.menus.is_active'))
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),

                Section::make(__('panel.menus.service_window'))
                    ->icon(Heroicon::OutlinedClock)
                    ->schema([
                        // Both or neither: a window with one end is not a
                        // window, so each requires the other rather than the
                        // missing half being guessed at.
                        TimePicker::make('available_from')
                            ->label(__('panel.menus.available_from'))
                            ->seconds(false)
                            ->live(onBlur: true)
                            ->requiredWith('available_until'),

                        TimePicker::make('available_until')
                            ->label(__('panel.menus.available_until'))
                            ->seconds(false)
                            ->live(onBlur: true)
                            ->requiredWith('available_from'),
                    ])
                    ->columns(2)
                    ->collapsed(fn (?Menu $record): bool => ! ($record?->hasServiceWindow() ?? false)),
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
