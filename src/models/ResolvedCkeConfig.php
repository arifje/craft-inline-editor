<?php

namespace arifje\inlineeditor\models;

/**
 * The CKEditor configuration that applies to one CKEditor field, normalised
 * across the supported craftcms/ckeditor plugin lines.
 *
 * Instances are produced by {@see \arifje\inlineeditor\helpers\CkeConfigResolver}.
 */
final class ResolvedCkeConfig
{
    /** Configuration read from the field itself (craftcms/ckeditor 5.x). */
    public const SOURCE_FIELD = 'field';
    /** Shared config selected on the field (craftcms/ckeditor 3.x/4.x, Settings → CKEditor). */
    public const SOURCE_SHARED_CONFIG = 'shared-config';
    /** Shared config forced by the plugin's "CKEditor config override" setting (3.x/4.x only). */
    public const SOURCE_OVERRIDE = 'override';
    /** Nothing usable was found; the CKEditor plugin's own defaults are used. */
    public const SOURCE_DEFAULT = 'default';

    /**
     * @param string[] $toolbar Toolbar item names, e.g. ['heading', '|', 'bold']
     * @param int[]|false $headingLevels Allowed heading levels, or false when headings are disabled
     * @param array|null $options JSON config options (mutually exclusive with $js)
     * @param string|null $js JavaScript config body that returns an object (mutually exclusive with $options)
     * @param string|null $css Custom editor styles
     * @param string $source One of the SOURCE_* constants
     * @param string|null $name Human-readable name of the config/field, for diagnostics
     * @param string[] $warnings Actionable problems found while resolving
     */
    public function __construct(
        public array $toolbar,
        public array|false $headingLevels,
        public ?array $options,
        public ?string $js,
        public ?string $css,
        public string $source,
        public ?string $name = null,
        public array $warnings = [],
    ) {
    }

    /**
     * The JSON-safe part of the config, as consumed by inline-editor.js.
     * The `js` body is deliberately excluded: it must be emitted as a real
     * function, not as a string.
     */
    public function toJsData(): array
    {
        return [
            'toolbar' => array_values($this->toolbar),
            'headingLevels' => $this->headingLevels === false
                ? false
                : array_values(array_map('intval', $this->headingLevels)),
            'options' => $this->options,
            'source' => $this->source,
            'name' => $this->name,
            'hasJs' => $this->js !== null,
        ];
    }
}
