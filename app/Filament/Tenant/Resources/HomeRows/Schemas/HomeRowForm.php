<?php

namespace App\Filament\Restaurant\Resources\HomeRows\Schemas;

use App\Enums\HomeRowLayout;
use App\Filament\Schemas\TranslatedFields;
use App\Models\HomeRow;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class HomeRowForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['title'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.rows.title_section'))
                    ->description(__('panel.rows.title_section_help'))
                    ->icon(Heroicon::OutlinedTag)
                    // Never required, in any language: a banner into the menu
                    // reads better with nothing over it.
                    ->schema(TranslatedFields::optionalText('title', __('panel.rows.title_field'), maxLength: 48)),

                Section::make(__('panel.rows.layout'))
                    ->description(__('panel.rows.layout_help'))
                    ->icon(Heroicon::OutlinedViewColumns)
                    ->schema([
                        // A radio rather than a select: the layout is the whole
                        // decision on this page, and each option needs its line
                        // of explanation read before it is picked.
                        Radio::make('layout')
                            ->hiddenLabel()
                            ->options(HomeRowLayout::options())
                            ->descriptions(HomeRowLayout::descriptions())
                            ->default(HomeRowLayout::Banner->value)
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('panel.rows.placement'))
                    ->icon(Heroicon::OutlinedEye)
                    ->schema([
                        // Where it sits in the list is arranged by dragging
                        // the rows there, not typed here — see Reordering.
                        Toggle::make('is_active')
                            ->label(__('panel.rows.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    /**
     * Put every language back into the form when a row is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, HomeRow $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }
}
