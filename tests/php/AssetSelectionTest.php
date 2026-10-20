<?php

namespace arifje\inlineeditor\tests;

use arifje\inlineeditor\helpers\AssetSelection;
use ArrayIterator;
use PHPUnit\Framework\TestCase;

final class AssetSelectionTest extends TestCase
{
    public function testGalleryReadsItsFullSelectionOnlyOnce(): void
    {
        $selection = new AssetSelection();
        $query = $this->query(range(1, 120));

        for ($image = 0; $image < 120; $image++) {
            self::assertSame(range(1, 120), $selection->ids($query));
        }

        self::assertSame(1, $query->calls);
    }

    public function testChangedCriteriaReloadTheSelection(): void
    {
        $selection = new AssetSelection();
        $query = $this->query([12, 10]);
        self::assertSame([12, 10], $selection->ids($query));

        $query->criteria['limit'] = 1;
        $query->values = [12];
        self::assertSame([12], $selection->ids($query));
        self::assertSame([12], $selection->ids($query));
        self::assertSame(2, $query->calls);
    }

    public function testQueryCriteriaNormalizedDuringExecutionAreReused(): void
    {
        $query = new class {
            public int $calls = 0;
            public array $criteria = ['select' => null];

            public function getCriteria(): array
            {
                return $this->criteria;
            }

            public function ids(): array
            {
                $this->calls++;
                $this->criteria['select'] = [];
                return [4, 2];
            }
        };
        $selection = new AssetSelection();

        self::assertSame([4, 2], $selection->ids($query));
        self::assertSame([4, 2], $selection->ids($query));
        self::assertSame(1, $query->calls);
    }

    public function testDifferentQueriesAndRequestsDoNotShareSelections(): void
    {
        $selection = new AssetSelection();
        $first = $this->query([7]);
        $second = $this->query([9]);
        self::assertSame([7], $selection->ids($first));
        self::assertSame([9], $selection->ids($second));
        self::assertSame([7], (new AssetSelection())->ids($first));
        self::assertSame(2, $first->calls);
        self::assertSame(1, $second->calls);
    }

    public function testEmptySelectionsAreReused(): void
    {
        $selection = new AssetSelection();
        $query = $this->query([]);
        self::assertSame([], $selection->ids($query));
        self::assertSame([], $selection->ids($query));
        self::assertSame(1, $query->calls);
        self::assertSame([], $selection->ids(null));
    }

    public function testEagerLoadedCollectionsRemainInMemoryAndPreserveOrder(): void
    {
        $collection = new class {
            public function ids(): ArrayIterator
            {
                return new ArrayIterator(['17', '3', '12']);
            }
        };

        self::assertSame([17, 3, 12], (new AssetSelection())->ids($collection));
    }

    private function query(array $values): object
    {
        return new class($values) {
            public int $calls = 0;
            public array $criteria = ['limit' => null];

            public function __construct(public array $values)
            {
            }

            public function getCriteria(): array
            {
                return $this->criteria;
            }

            public function ids(): array
            {
                $this->calls++;
                return $this->values;
            }
        };
    }
}
