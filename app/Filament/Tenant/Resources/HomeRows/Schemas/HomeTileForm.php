<?php

namespace App\Filament\Restaurant\Resources\HomeRows\Schemas;

use App\Enums\HomeTileAction;
use App\Filament\Restaurant\Resources\Menus\Schemas\MenuCategoryForm;
use App\Filament\Schemas\TranslatedFields;
use App\Models\HomeTile;
use App\Models\Restaurant;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class HomeTileForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['label'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.tiles.label_section'))
                    ->description(__('panel.tiles.label_section_help'))
                    ->icon(Heroicon::OutlinedTag)
                    ->schema(TranslatedFields::text('label', __('panel.tiles.label_field'), maxLength: 48)),

                Section::make(__('panel.tiles.picture'))
                    ->description(__('panel.tiles.picture_help'))
                    ->icon(Heroicon::OutlinedPhoto)
                    ->schema([
                        FileUpload::make('image_path')
                            ->hiddenLabel()
                            ->image()
                            ->imageEditor()
                            // Kept on the private disk and served through a
                            // route that checks the restaurant in the domain,
                            // so one restaurant's uploads are never reachable
                            // from another's subdomain.
                            ->disk('local')
                            ->directory(fn (): string => self::uploadDirectory())
                            ->visibility('private')
                            ->preventFilePathTampering()
                            ->maxSize(4096)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText(__('panel.tiles.picture_field_help')),
                    ]),

                Section::make(__('panel.tiles.destination'))
                    ->description(__('panel.tiles.destination_help'))
                    ->icon(Heroicon::OutlinedCursorArrowRays)
                    ->schema([
                        // A radio rather than a select: each answer needs a
                        // line of explanation, and all of them should be
                        // readable without opening anything.
                        Radio::make('action')
                            ->label(__('panel.tiles.on_tap'))
                            ->options(HomeTileAction::options())
                            ->descriptions(self::actionDescriptions())
                            ->default(HomeTileAction::Menu->value)
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        // Exactly one of the next three is filled, decided by
                        // the action above. The model clears the others on save
                        // and refuses a tile with none — see HomeTile::booted().
                        Select::make('menu_id')
                            ->label(__('panel.tiles.menu_to_open'))
                            ->options(fn (): array => MenuCategoryForm::menuOptions())
                            ->searchable()
                            ->preload()
                            ->prefixIcon(Heroicon::OutlinedBookOpen)
                            ->visible(fn (Get $get): bool => self::actionIs($get, HomeTileAction::Menu))
                            ->required(fn (Get $get): bool => self::actionIs($get, HomeTileAction::Menu))
                            ->helperText(__('panel.tiles.menu_to_open_help')),

                        TextInput::make('url')
                            ->label(__('panel.tiles.link'))
                            ->url()
                            ->maxLength(2048)
                            ->prefixIcon(Heroicon::OutlinedLink)
                            ->visible(fn (Get $get): bool => self::actionIs($get, HomeTileAction::Link))
                            ->required(fn (Get $get): bool => self::actionIs($get, HomeTileAction::Link))
                            ->helperText(__('panel.tiles.link_help')),

                        FileUpload::make('document_path')
                            ->label(__('panel.tiles.document'))
                            ->disk('local')
                            ->directory(fn (): string => self::uploadDirectory())
                            ->visibility('private')
                            ->preventFilePathTampering()
                            ->maxSize(10240)
                            ->acceptedFileTypes(['application/pdf'])
                            ->visible(fn (Get $get): bool => self::actionIs($get, HomeTileAction::Pdf))
                            ->required(fn (Get $get): bool => self::actionIs($get, HomeTileAction::Pdf))
                            ->helperText(__('panel.tiles.document_help')),
                    ])
                    ->columns(1),

                Section::make(__('panel.tiles.placement'))
                    ->icon(Heroicon::OutlinedEye)
                    ->schema([
                        // Where it sits in the list is arranged by dragging
                        // the rows there, not typed here — see Reordering.
                        Toggle::make('is_active')
                            ->label(__('panel.tiles.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    /**
     * Put every language back into the form when a tile is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, HomeTile $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }

    /**
     * Whether the action currently chosen in the form is this one.
     */
    private static function actionIs(Get $get, HomeTileAction $action): bool
    {
        return HomeTileAction::tryFrom((string) $get('action')) === $action;
    }

    /**
     * What each action does, shown under its option.
     *
     * @return array<string, string>
     */
    private static function actionDescriptions(): array
    {
        return array_reduce(
            HomeTileAction::cases(),
            static function (array $descriptions, HomeTileAction $action): array {
                $descriptions[$action->value] = $action->description();

                return $descriptions;
            },
            [],
        );
    }

    /**
     * Where this restaurant's tile uploads live.
     *
     * A directory per restaurant, so one restaurant's files are separated from
     * another's on the disk as well as by the route that serves them.
     */
    private static function uploadDirectory(): string
    {
        $tenant = Filament::getTenant();

        return 'home-tiles/'.($tenant instanceof Restaurant ? $tenant->getKey() : 'shared');
    }
}
