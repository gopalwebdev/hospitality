<?php

namespace App\Filament\Restaurant\Resources\Menus\Schemas;

use App\Filament\Schemas\TranslatedFields;
use App\Models\Menu;
use App\Models\MenuCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Naming a category, on the page of the menu it belongs to.
 *
 * There is no "which menu" field any more: categories are created and renamed
 * on the menu's arrangement screen, so the menu is the page rather than a
 * select. Moving one to another menu is its own action, because that is a
 * different act from renaming.
 *
 * The menu still has to be *known* — uniqueness is per menu — so the screen
 * passes its key in, and the category being renamed with it: that screen's
 * table is built on plain arrays rather than models, so there is no record for
 * the uniqueness rule to find and ignore on its own.
 */
class MenuCategoryForm
{
    /**
     * The columns this form edits in more than one language.
     *
     * @var list<string>
     */
    public const array TRANSLATED = ['name'];

    public static function configure(Schema $schema, ?int $menuId = null, ?MenuCategory $editing = null): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TranslatedFields::localeSwitcher(),

                Section::make(__('panel.categories.section'))
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->schema(TranslatedFields::text(
                        'name',
                        __('panel.shared.name'),
                        maxLength: 64,
                        // Unique within the menu rather than the restaurant, so
                        // a lunch card and a dinner card may both have a
                        // "Starters". Matches the expression index exactly.
                        // Per level, matching the expression index: a menu may
                        // have a top-level "Chicken" and a "Biryani › Chicken".
                        uniqueWithin: fn (): Builder => MenuCategory::query()
                            ->where('menu_id', $menuId)
                            ->topLevel(),
                        uniqueMessage: __('panel.categories.unique'),
                        editing: $editing,
                    )),

                Section::make(__('panel.categories.on_the_menu'))
                    ->icon(Heroicon::OutlinedEye)
                    ->schema([
                        // Where it sits in the list is arranged by dragging
                        // the rows there, not typed here — see Reordering.
                        Toggle::make('is_active')
                            ->label(__('panel.categories.is_active'))
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    /**
     * Put every language back into the form when a category is edited.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTranslations(array $data, MenuCategory $record): array
    {
        return $record->fillTranslationsInto($data, ...self::TRANSLATED);
    }

    /**
     * This restaurant's menus, in the order it arranged them.
     *
     * Read as models rather than plucked, because the label is a translated
     * column: `pluck('name->en')` comes back keyed by the path itself, and
     * `$menu->name` is both simpler and already language-aware.
     *
     * @return array<int, string>
     */
    public static function menuOptions(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        // once(): Filament asks a select for its options more than once while
        // it builds and validates one form.
        return once(fn (): array => Menu::query()
            ->where('tenant_id', $tenantId)
            ->inMenuOrder()
            ->get()
            ->mapWithKeys(fn (Menu $menu): array => [$menu->getKey() => $menu->name])
            ->all());
    }
}
