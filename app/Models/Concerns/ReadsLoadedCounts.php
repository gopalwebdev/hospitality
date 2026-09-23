<?php

namespace App\Models\Concerns;

/**
 * Reads a withCount() value that is already on the model.
 *
 * A list page loads its counts once for the whole page. Anything asked per row
 * afterwards should use that number rather than going back to the database, or
 * a page of twenty rows costs twenty extra queries to say what it already knows.
 */
trait ReadsLoadedCounts
{
    /**
     * A withCount() or withSum() value already loaded, or null when it was not.
     *
     * Read straight out of the attribute array rather than through __get:
     * under Model::shouldBeStrict() touching an attribute that was never
     * selected throws, and "not loaded" is the ordinary case here, not a bug.
     *
     * A withSum() with nothing to sum comes back as SQL NULL, which is a
     * loaded answer of zero — not "not loaded" — so the two are told apart by
     * whether the key exists at all, never by whether its value is null.
     * Conflating them sent every row with nothing to sum back to a fresh
     * query, silently reintroducing the N+1 the loaded value existed to avoid.
     */
    protected function loadedCount(string $key): ?int
    {
        $attributes = $this->getAttributes();

        if (! array_key_exists($key, $attributes)) {
            return null;
        }

        return $attributes[$key] === null ? 0 : (int) $attributes[$key];
    }
}
