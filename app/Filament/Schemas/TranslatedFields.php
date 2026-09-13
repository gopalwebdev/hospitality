<?php

namespace App\Filament\Schemas;

use App\Enums\Locale;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The inputs an admin types a translated field into: one box per field, and one
 * switcher for the whole form deciding which language those boxes are showing.
 *
 * A field still has an input per language underneath — that is how the state of
 * every language reaches the save in one go — but only the switched-to one is
 * on screen. A form with a name and a description used to be four boxes; it is
 * two, and the form no longer doubles in height for each language added.
 *
 * Two consequences worth knowing:
 *
 * - Hidden languages are still dehydrated, so switching to Tamil and saving
 *   keeps the English that was already typed. Without that, editing one
 *   language would quietly blank the other.
 * - Validation that has to hold whichever language is showing — English is
 *   required, English is unique — is attached to *every* language's input and
 *   reads the English value out of the form state rather than off the input it
 *   happens to be attached to. A rule left only on the English input would
 *   never run while Tamil was the one on screen, and the save would fail at the
 *   database instead.
 */
final class TranslatedFields
{
    /**
     * The form state holding which language is being edited.
     *
     * Never dehydrated: it is a control for the person filling the form in, not
     * a column on anything. Bubbles up from nested sections, so one switcher at
     * the top of a form drives every translated field under it.
     */
    public const string LOCALE_KEY = '_locale';

    /**
     * The control that decides which language every box on this form shows.
     *
     * Place it once, at the top of the form. Toggle buttons rather than a
     * select: with a handful of languages the whole choice should be readable
     * without opening anything, and switching should be one tap.
     *
     * It opens on the language the **panel is being worked in** — the choice in
     * the top bar — rather than always on English: someone who has switched the
     * panel to Tamil is there to read and write Tamil, and a form that opened on
     * English every time made them move this control on every record. English
     * is what an unset or unknown language means, so a panel nobody has switched
     * still opens every form on English.
     *
     * `formatStateUsing()` and not `default()` alone, because a default only
     * applies to a form that is *filled with nothing*. Every edit form — and
     * every modal handed data, which includes "New sub-category" and its
     * prefilled parent — skips it, so this control came up with neither
     * language lit and the form's own rules had to guess what was on screen.
     * Formatting runs on the way in whatever the state is.
     */
    public static function localeSwitcher(): ToggleButtons
    {
        return ToggleButtons::make(self::LOCALE_KEY)
            ->label(__('panel.shared.editing_language'))
            ->options(array_reduce(
                Locale::cases(),
                static function (array $options, Locale $locale): array {
                    $options[$locale->value] = $locale->label();

                    return $options;
                },
                [],
            ))
            ->default(static fn (): string => self::workingLocale()->value)
            ->formatStateUsing(static fn (mixed $state): string => (
                (is_string($state) ? Locale::tryFrom($state) : null) ?? self::workingLocale()
            )->value)
            ->live()
            ->inline()
            ->grouped()
            ->dehydrated(false)
            ->columnSpanFull();
    }

    /**
     * A single-line input, showing whichever language is switched to.
     *
     * `uniqueWithin` narrows the query the name is checked against — the menu a
     * section sits on, the section a dish sits in — and is only ever applied to
     * the fallback language, because that is the one value a name is unique on.
     *
     * `editing` names the row being edited when the form is not bound to one.
     * A form on a resource or a relation manager has its record injected and
     * needs nothing here; one opened from the arrangement screen is looking at
     * a table of plain arrays, so the record it would otherwise ignore has to
     * be handed in — without it, saving a category under its own name is
     * refused as a clash with itself.
     *
     * @param  (Closure(Get): Builder<covariant Model>)|null  $uniqueWithin
     * @return list<TextInput>
     */
    public static function text(
        string $name,
        string $label,
        int $maxLength = 120,
        ?Closure $uniqueWithin = null,
        string $uniqueMessage = 'Something here already has that name.',
        ?Model $editing = null,
    ): array {
        return array_map(
            static function (Locale $locale) use ($name, $label, $maxLength, $uniqueWithin, $uniqueMessage, $editing): TextInput {
                $field = self::configure(
                    TextInput::make("{$name}.{$locale->value}")->maxLength($maxLength),
                    $locale,
                    $name,
                    $label,
                );

                if (! $uniqueWithin instanceof Closure) {
                    return $field;
                }

                return $field->rule(
                    // `mixed` rather than `?Model`: a schema's record is a
                    // plain array when the form was opened from a table built
                    // on custom data, and a typed parameter would be handed one.
                    //
                    // Every language's input carries the rule, but they all ask
                    // the same question about the English value, so only the
                    // input for the language on screen asks the database.
                    fn (Get $get, mixed $record): Closure => $locale === self::editingLocale($get)
                        ? self::uniqueFallbackValue(
                            fn (): Builder => $uniqueWithin($get),
                            $editing ?? ($record instanceof Model ? $record : null),
                            $name,
                            $uniqueMessage,
                            $get($name.'.'.Locale::default()->value),
                        )
                        : static function (): void {},
                );
            },
            Locale::cases(),
        );
    }

    /**
     * A single-line input required in no language at all.
     *
     * For text a record may simply not have — a home screen row's heading,
     * where a banner into the menu reads better with nothing over it. Carries
     * no uniqueness rule for the same reason: two untitled rows are not a
     * clash, and the index that backs this skips null titles too.
     *
     * @return list<TextInput>
     */
    public static function optionalText(string $name, string $label, int $maxLength = 120): array
    {
        return array_map(
            static fn (Locale $locale): TextInput => self::configure(
                TextInput::make("{$name}.{$locale->value}")->maxLength($maxLength),
                $locale,
                $name,
                $label,
                requireFallback: false,
            ),
            Locale::cases(),
        );
    }

    /**
     * A multi-line input, showing whichever language is switched to.
     *
     * Never required, in any language: a description is optional everywhere it
     * appears.
     *
     * @return list<Textarea>
     */
    public static function textarea(string $name, string $label, int $maxLength = 500, int $rows = 3): array
    {
        return array_map(
            static fn (Locale $locale): Textarea => self::configure(
                Textarea::make("{$name}.{$locale->value}")->maxLength($maxLength)->rows($rows),
                $locale,
                $name,
                $label,
                requireFallback: false,
            ),
            Locale::cases(),
        );
    }

    /**
     * Refuse a name another record in the same place already uses.
     *
     * Checked against the fallback language only, the one value a name is unique
     * on. The schema has no unique index, so this rule is the only thing that
     * refuses a duplicate.
     *
     * $value is passed in rather than taken from the input this is attached to:
     * the rule rides on every language's input so it runs whichever one is on
     * screen, and all of them are asking about the English value.
     *
     * @param  Closure(): Builder<covariant Model>  $query  already narrowed to the parent this belongs to
     */
    public static function uniqueFallbackValue(
        Closure $query,
        ?Model $record = null,
        string $column = 'name',
        string $message = 'Something here already has that name.',
        mixed $value = null,
    ): Closure {
        return static function (string $attribute, mixed $inputValue, Closure $fail) use ($query, $record, $column, $message, $value): void {
            $english = $value ?? $inputValue;

            if (blank($english)) {
                return;
            }

            $matches = $query()->where($column.'->'.Locale::default()->value, $english);

            // Editing a record must not collide with itself — but only when
            // the record *is* one of the rows being searched. A schema is
            // handed whatever record its surroundings have, and an action modal
            // on a page falls back to that page's own record: a menu, whose id
            // would otherwise exclude the category that happens to share it and
            // let a duplicate straight through.
            if ($record instanceof Model && $record::class === $matches->getModel()::class) {
                $matches->whereKeyNot($record->getKey());
            }

            if ($matches->exists()) {
                $fail($message);
            }
        };
    }

    /**
     * Search a translated column in the language the panel is showing it in.
     *
     * A table's name column renders in the panel's language, so a search that
     * only looked at English found nothing for the Tamil a Tamil panel is
     * displaying. English is searched too: a record nobody has translated yet
     * is displayed in English, and it has to be findable by what is on screen.
     *
     * A translated column holds a JSON document, so a plain `like` would be
     * matching braces and locale keys as well as words — which is also why the
     * language has to be named at all. `ilike`, because Postgres's `like` is
     * case-sensitive and nobody searching a menu types its capital letters.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function search(Builder $query, string $column, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($column, $search): void {
            foreach (self::searchableAttributes($query->qualifyColumn($column)) as $path) {
                $query->orWhere($path, 'ilike', '%'.$search.'%');
            }
        });
    }

    /**
     * Sort a translated column by the value the panel is actually displaying.
     *
     * That is the panel's language where there is one and English where there
     * is not, so the order matches the column rather than a language that is
     * not on screen. `coalesce` rather than two sort keys, because an
     * untranslated row sorted on a null would land after every translated one
     * instead of among its neighbours.
     *
     * Filament hands the direction through as a plain string; only the two
     * values are ever sent, and this is where that is made explicit.
     *
     * The SQL is built from nothing but the column a table names and the
     * locales' own values, so it stays a literal string rather than something
     * spliced together at runtime.
     *
     * @param  Builder<covariant Model>  $query
     * @param  literal-string  $column
     * @return Builder<covariant Model>
     */
    public static function sort(Builder $query, string $column, string $direction): Builder
    {
        $working = self::workingLocale();
        $english = Locale::default();

        return $query->orderByRaw(
            'coalesce('.$column." ->> '".$working->value."', ".$column." ->> '".$english->value."') "
            .($direction === 'desc' ? 'desc' : 'asc'),
        );
    }

    /**
     * The JSON paths a search on a translated column looks in.
     *
     * The panel's language first, then English once — for a table's search and
     * for a resource's global search in the top bar alike, which otherwise
     * matches the raw JSON text: a Tamil search misses, because the document
     * stores Tamil as escape sequences, and a search for "en" matches every row.
     *
     * @return list<string>
     */
    public static function searchableAttributes(string $column): array
    {
        $working = self::workingLocale();

        $locales = $working === Locale::default() ? [$working] : [$working, Locale::default()];

        return array_map(static fn (Locale $locale): string => $column.'->'.$locale->value, $locales);
    }

    /**
     * Which language the form is currently showing.
     *
     * Falls back rather than trusting the state: the switcher is a form field
     * like any other and can arrive as anything. The fallback is the language
     * the panel is being worked in, so a form whose switcher has not been set
     * yet agrees with the one that has.
     */
    private static function editingLocale(Get $get): Locale
    {
        $value = $get(self::LOCALE_KEY);

        return (is_string($value) ? Locale::tryFrom($value) : null) ?? self::workingLocale();
    }

    /**
     * The language this panel is being worked in.
     *
     * `App\Http\Middleware\SetLocale` puts the top bar's choice on the
     * application for the request, Livewire's own included — it is appended to
     * the `web` group, which Livewire's update route runs too, so a modal
     * mounted by an AJAX request opens in the same language as the page behind
     * it. Anything unrecognised is English.
     */
    private static function workingLocale(): Locale
    {
        return Locale::fromRequestValue(app()->getLocale());
    }

    /**
     * Label, visibility, requiredness and helper text for one language's input.
     *
     * @template TField of TextInput|Textarea
     *
     * @param  TField  $field
     * @return TField
     */
    private static function configure(
        TextInput|Textarea $field,
        Locale $locale,
        string $name,
        string $label,
        bool $requireFallback = true,
    ): TextInput|Textarea {
        $isFallback = $locale === Locale::default();

        $field = $field
            ->label($label)
            ->visible(fn (Get $get): bool => self::editingLocale($get) === $locale)
            // The languages not on screen still travel with the save, or
            // editing one would blank the others.
            ->dehydratedWhenHidden()
            ->required($requireFallback && $isFallback);

        if (! $requireFallback || $isFallback) {
            return $field;
        }

        // English is required whichever language is being looked at, so the
        // rule rides here too rather than only on the English input, which is
        // hidden at exactly the moment it would need to fire.
        return $field->rule(
            fn (Get $get): Closure => static function (string $attribute, mixed $inputValue, Closure $fail) use ($get, $name, $label): void {
                if (filled($get($name.'.'.Locale::default()->value))) {
                    return;
                }

                $fail(__('panel.shared.fallback_required', [
                    'field' => $label,
                    'language' => Locale::default()->englishName(),
                ]));
            },
        );
    }
}
