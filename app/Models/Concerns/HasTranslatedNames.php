<?php

namespace App\Models\Concerns;

use App\Enums\Locale;
use Spatie\Translatable\HasTranslations;

/**
 * A model whose guest-facing text is stored one value per language.
 *
 * Spatie's HasTranslations does the reading and writing: the column holds
 * {"en": "Starters", "ta": "தொடக்கம்"} and `$model->name` gives back whichever
 * matches the current locale, falling back to English. Using models must
 * declare `public array $translatable`.
 *
 * What this adds is the one thing the package leaves to the application:
 * English is privileged. It is the fallback, it is what the admin forms
 * require, and it is the value every uniqueness rule checks
 * — so queries that sort or match on a name have to say so, and this is where
 * they say it once.
 */
trait HasTranslatedNames
{
    use HasTranslations;

    /**
     * The query path to a translated column's fallback value.
     *
     * Laravel compiles a `column->key` path to Postgres's `->>` operator, so
     * this needs no raw SQL. It is deliberately the same expression the unique
     * indexes are built on, so validation and the database agree.
     */
    public static function fallbackLocalePath(string $column = 'name'): string
    {
        return $column.'->'.Locale::default()->value;
    }

    /**
     * Put every language of the named columns into a Filament form's data.
     *
     * Spatie hands back the current locale's string for a translated attribute,
     * which is right everywhere except an admin form: that edits all of them at
     * once, so it needs the whole document. Lives here rather than in the form
     * because only a model that holds translations can answer it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fillTranslationsInto(array $data, string ...$columns): array
    {
        foreach ($columns as $column) {
            $data[$column] = $this->getTranslations($column);
        }

        return $data;
    }
}
