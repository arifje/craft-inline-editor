<?php

namespace arifje\inlineeditor\helpers;

/**
 * Small, dependency-free checks on stored CKEditor content.
 */
final class ContentHelper
{
    /**
     * Whether the raw CKEditor content contains nested entries
     * (`<craft-entry data-entry-id="…">`, craftcms/ckeditor 4.x/5.x).
     *
     * A CKEditor build without Craft's control-panel plugins would silently
     * drop those elements on save, so such content must not be edited inline.
     */
    public static function containsNestedEntries(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }
        return (bool)preg_match('/<craft-entry\b/i', $html);
    }

    /**
     * Returns the raw stored content for a CKEditor field value.
     *
     * Craft's HtmlFieldData renders reference tags (`{asset:12@1:url}`) and,
     * on craftcms/ckeditor 4.x/5.x, nested entries when it is cast to a string.
     * The editor must work on the unrendered content so those references
     * survive a round trip.
     */
    public static function rawContent(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_object($value) && method_exists($value, 'getRawContent')) {
            return (string)$value->getRawContent();
        }
        return is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) ? (string)$value : '';
    }
}
