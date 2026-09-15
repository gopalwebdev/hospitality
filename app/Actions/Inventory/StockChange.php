<?php

namespace App\Actions\Inventory;

use App\Models\MenuAddOnOption;
use App\Models\MenuItem;

/**
 * One change asked of a count: take some, add some, or set it outright.
 *
 * Names its row by class and key rather than by a model, because
 * ApplyStockChanges reads the row again under a lock before changing it —
 * whatever instance a caller holds may already be out of date.
 */
final readonly class StockChange
{
    private const string TAKE = 'take';

    private const string ADD = 'add';

    private const string SET = 'set';

    /**
     * @param  class-string<MenuItem|MenuAddOnOption>  $type
     * @param  list<string>  $lineKeys  the basket lines asking, so a shortage can point back at them
     */
    private function __construct(
        public string $type,
        public int $id,
        private string $operation,
        public ?int $quantity,
        public array $lineKeys = [],
    ) {}

    /**
     * Take some for an order. Refused when a counted row has fewer left; nothing happens to one nobody counts.
     *
     * @param  class-string<MenuItem|MenuAddOnOption>  $type
     * @param  list<string>  $lineKeys
     */
    public static function take(string $type, int $id, int $quantity, array $lineKeys = []): self
    {
        return new self($type, $id, self::TAKE, $quantity, $lineKeys);
    }

    /**
     * Restock, or put back what a cancelled order took. Nothing happens to a row nobody counts.
     *
     * @param  class-string<MenuItem|MenuAddOnOption>  $type
     */
    public static function add(string $type, int $id, int $quantity): self
    {
        return new self($type, $id, self::ADD, $quantity);
    }

    /**
     * Set the count outright, as counted; null stops counting.
     *
     * @param  class-string<MenuItem|MenuAddOnOption>  $type
     */
    public static function setTo(string $type, int $id, ?int $quantity): self
    {
        return new self($type, $id, self::SET, $quantity);
    }

    public function isTake(): bool
    {
        return $this->operation === self::TAKE;
    }

    /**
     * What a count of $current becomes, or null while nobody counts it.
     */
    public function applyTo(?int $current): ?int
    {
        return match ($this->operation) {
            self::SET => $this->quantity,
            self::TAKE => $current === null ? null : $current - (int) $this->quantity,
            default => $current === null ? null : $current + (int) $this->quantity,
        };
    }
}
