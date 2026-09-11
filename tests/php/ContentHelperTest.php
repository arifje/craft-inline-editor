<?php

namespace arifje\inlineeditor\tests;

use arifje\inlineeditor\helpers\ContentHelper;
use PHPUnit\Framework\TestCase;

final class ContentHelperTest extends TestCase
{
    public function testDetectsNestedEntries(): void
    {
        self::assertTrue(ContentHelper::containsNestedEntries('<p>Hi</p><craft-entry data-entry-id="12"></craft-entry>'));
        self::assertTrue(ContentHelper::containsNestedEntries('<CRAFT-ENTRY data-entry-id="12">'));
        self::assertFalse(ContentHelper::containsNestedEntries('<p>craft-entry as text</p>'));
        self::assertFalse(ContentHelper::containsNestedEntries('<p><a href="{entry:12@1:url}">ref tag</a></p>'));
        self::assertFalse(ContentHelper::containsNestedEntries(''));
        self::assertFalse(ContentHelper::containsNestedEntries(null));
    }

    public function testRawContentPrefersTheUnrenderedValue(): void
    {
        $fieldData = new class {
            public function getRawContent(): string
            {
                return '<a href="{entry:5@1:url}">raw</a>';
            }

            public function __toString(): string
            {
                return '<a href="https://example.test/page">rendered</a>';
            }
        };

        self::assertSame('<a href="{entry:5@1:url}">raw</a>', ContentHelper::rawContent($fieldData));
        self::assertSame('<p>plain</p>', ContentHelper::rawContent('<p>plain</p>'));
        self::assertSame('', ContentHelper::rawContent(null));
    }
}
