<?php

namespace App\Filament\Schemas;

use App\Actions\Inventory\ApplyStockChanges;
use App\Actions\Inventory\StockChange;
use App\Enums\StockMovementReason;
use App\Models\MenuAddOnOption;
use App\Models\MenuItem;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Auth;

/**
 * How many of an item or an option are left, as a form edits it.
 *
 * A form holds the count it was opened with, and Filament saves every field it
 * holds — the options table saves every row. Written back as it stood, an admin
 * renaming a group while guests order would put every count back to what it was
 * when the modal opened. So the count a form opened with rides beside it, hidden,
 * and an existing row's count is written only when the two differ — and then
 * through ApplyStockChanges, under the lock an order takes. A new row's count is
 * stored with the row: nothing can have taken from a row that did not exist.
 */
final class StockFields
{
    /** The count the form was opened with. */
    public const string LOADED = 'stock_quantity_loaded';

    public static function quantity(): TextInput
    {
        return TextInput::make('stock_quantity')
            ->label(__('panel.stock.in_stock'))
            ->integer()
            ->minValue(0)
            ->maxValue(99999)
            // Blank is "nobody counts this", which is not the same as none left.
            ->placeholder(__('panel.stock.not_tracked'));
    }

    public static function loaded(): Hidden
    {
        return Hidden::make(self::LOADED);
    }

    /**
     * Remember the count a form is opened with.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(array $data): array
    {
        $data[self::LOADED] = $data['stock_quantity'] ?? null;

        return $data;
    }

    /**
     * A new row's data with its count as it is stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function storeNew(array $data): array
    {
        $data['stock_quantity'] = self::typed($data['stock_quantity'] ?? null);

        unset($data[self::LOADED]);

        return $data;
    }

    /**
     * An existing row's data with its count taken out, beside what was typed and what the form was opened with.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: int|null, 2: int|null}
     */
    public static function pull(array $data): array
    {
        $typed = self::typed($data['stock_quantity'] ?? null);
        $loaded = self::typed($data[self::LOADED] ?? null);

        unset($data['stock_quantity'], $data[self::LOADED]);

        return [$data, $typed, $loaded];
    }

    /**
     * Set an existing row's count under the lock, when this form is what changed it.
     */
    public static function applyIfChanged(MenuItem|MenuAddOnOption $record, ?int $typed, ?int $loaded): void
    {
        if ($typed === $loaded) {
            return;
        }

        $user = Auth::user();

        app(ApplyStockChanges::class)(
            [StockChange::setTo($record::class, $record->getKey(), $typed)],
            StockMovementReason::Count,
            user: $user instanceof User ? $user : null,
        );
    }

    private static function typed(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }
}
