<?php

namespace arifje\inlineeditor\helpers;

use Traversable;
use WeakMap;

/**
 * Reuses an asset query's selected IDs while its criteria remain unchanged.
 * The cache lives only for this helper's request and does not retain queries.
 */
final class AssetSelection
{
    /** @var ?WeakMap<object, array{criteria: array, ids: int[]}> */
    private ?WeakMap $selections = null;

    /**
     * @param mixed $value An asset query or an eager-loaded element collection.
     * @return int[]
     */
    public function ids(mixed $value): array
    {
        if (!is_object($value) || !method_exists($value, 'ids')) {
            return [];
        }

        $criteria = method_exists($value, 'getCriteria') ? $value->getCriteria() : null;
        if ($criteria !== null) {
            $this->selections ??= new WeakMap();
            $cached = $this->selections[$value] ?? null;
            if ($cached !== null && $cached['criteria'] === $criteria) {
                return $cached['ids'];
            }
        }

        $ids = $value->ids();
        $ids = array_map('intval', $ids instanceof Traversable ? iterator_to_array($ids) : $ids);

        if ($criteria !== null) {
            // Craft's ids() can normalize its select criteria while executing.
            $this->selections[$value] = ['criteria' => $value->getCriteria(), 'ids' => $ids];
        }

        return $ids;
    }
}
