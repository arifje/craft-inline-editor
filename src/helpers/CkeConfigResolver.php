<?php

namespace arifje\inlineeditor\helpers;

use arifje\inlineeditor\models\ResolvedCkeConfig;
use Closure;

/**
 * Resolves the CKEditor configuration that applies to a CKEditor field.
 *
 * The craftcms/ckeditor plugin stores configuration in two different places
 * depending on its major version:
 *
 * - 3.x (Craft 4) and 4.x (Craft 5): shared configs managed under
 *   Settings → CKEditor, referenced from the field via its `ckeConfig` UID.
 * - 5.x (Craft 5.9+): the configuration lives on the field itself
 *   (`toolbar`, `headingLevels`, `options`/`js`/`jsFile`, `css`/`cssFile`);
 *   shared configs and `Plugin::getCkeConfigs()` no longer exist.
 *
 * This class is pure PHP: every Craft interaction (loading a shared config,
 * reading a `config/ckeditor/*` file) is injected as a callable, so the
 * resolution rules are unit-testable without a Craft installation.
 */
final class CkeConfigResolver
{
    /** Mirrors the defaults of `craft\ckeditor\CkeConfig` / `craft\ckeditor\Field`. */
    public const DEFAULT_TOOLBAR = ['heading', '|', 'bold', 'italic', 'link'];
    public const DEFAULT_HEADING_LEVELS = [1, 2, 3, 4, 5, 6];

    private ?Closure $sharedConfigLoader;
    private ?Closure $configFileReader;

    /**
     * @param callable|null $sharedConfigLoader fn(string $uid): ?object — returns the shared
     *   config (an object exposing toolbar/headingLevels/options/js/css/name) or null when it
     *   does not exist. Null when the installed CKEditor plugin has no shared configs.
     * @param callable|null $configFileReader fn(string $file): ?string — returns the contents of
     *   `config/ckeditor/<file>` or null when it cannot be read.
     */
    public function __construct(?callable $sharedConfigLoader = null, ?callable $configFileReader = null)
    {
        $this->sharedConfigLoader = $sharedConfigLoader !== null ? Closure::fromCallable($sharedConfigLoader) : null;
        $this->configFileReader = $configFileReader !== null ? Closure::fromCallable($configFileReader) : null;
    }

    /**
     * Whether the field stores its CKEditor configuration itself (craftcms/ckeditor 5.x).
     */
    public static function hasFieldLevelConfig(object $field): bool
    {
        return property_exists($field, 'toolbar');
    }

    /**
     * Whether the field references a shared CKEditor config (craftcms/ckeditor 3.x/4.x).
     */
    public static function hasSharedConfigReference(object $field): bool
    {
        return property_exists($field, 'ckeConfig');
    }

    /**
     * Mirrors `craft\ckeditor\Field::isSourceEditingAllowed()` so the inline editor
     * offers the "Source" button to exactly the same users as the control panel.
     *
     * @param mixed $sourceEditingGroups The field's `sourceEditingGroups` setting ('*', null, or a list of group UIDs / '__ADMINS__')
     * @param string[] $userGroupUids UIDs of the groups the current user belongs to
     */
    public static function isSourceEditingAllowed(mixed $sourceEditingGroups, bool $isAdmin, array $userGroupUids): bool
    {
        if ($sourceEditingGroups === '*') {
            return true;
        }
        if (!is_array($sourceEditingGroups) || $sourceEditingGroups === []) {
            return false;
        }
        $allowed = array_flip(array_map('strval', $sourceEditingGroups));
        if ($isAdmin && isset($allowed['__ADMINS__'])) {
            return true;
        }
        foreach ($userGroupUids as $uid) {
            if (isset($allowed[(string)$uid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the configuration for a field.
     *
     * @param object $field The CKEditor field (any object exposing the relevant public properties)
     * @param string $overrideUid UID of a shared config that should win over the field's own selection
     *   (the plugin's legacy "CKEditor config" setting). Only meaningful for craftcms/ckeditor 3.x/4.x.
     * @param bool|null $allowSourceEditing When false, the `sourceEditing` toolbar item is removed.
     */
    public function resolve(object $field, string $overrideUid = '', ?bool $allowSourceEditing = null): ResolvedCkeConfig
    {
        $warnings = [];
        $overrideUid = trim($overrideUid);

        if (self::hasFieldLevelConfig($field)) {
            if ($overrideUid !== '') {
                $warnings[] = 'The "CKEditor config" plugin setting (a shared config UID) is ignored because the installed '
                    . 'CKEditor plugin stores its configuration on each field. Clear the setting under '
                    . 'Settings → Plugins → Inline Editor; the field\'s own configuration is used automatically.';
            }
            $config = $this->fromFieldLevel($field, $warnings);
        } elseif (self::hasSharedConfigReference($field)) {
            $config = $this->fromSharedConfig($field, $overrideUid, $warnings);
        } else {
            $warnings[] = sprintf(
                'Field %s does not expose a recognisable CKEditor configuration (neither a `toolbar` nor a `ckeConfig` '
                . 'property); the CKEditor plugin\'s built-in default toolbar is used.',
                $this->describeField($field)
            );
            $config = $this->defaults(ResolvedCkeConfig::SOURCE_DEFAULT, $this->fieldName($field));
        }

        $toolbar = self::normalizeToolbar($config->toolbar);
        if ($allowSourceEditing === false) {
            $toolbar = array_values(array_filter($toolbar, static fn(string $item) => $item !== 'sourceEditing'));
        }

        return new ResolvedCkeConfig(
            $toolbar,
            $config->headingLevels,
            $config->options,
            $config->js,
            $config->css,
            $config->source,
            $config->name,
            array_merge($warnings, $config->warnings),
        );
    }

    /**
     * craftcms/ckeditor 5.x: everything lives on the field. Precedence mirrors
     * `craft\ckeditor\Field::configJs()`: a `.json` jsFile fills `options`;
     * `options` (JSON) wins over JavaScript; otherwise a `.js` jsFile or the inline `js`.
     *
     * @param string[] $warnings
     */
    private function fromFieldLevel(object $field, array &$warnings): ResolvedCkeConfig
    {
        $label = $this->describeField($field);

        $toolbar = isset($field->toolbar) && is_array($field->toolbar) ? $field->toolbar : self::DEFAULT_TOOLBAR;
        $headingLevels = self::normalizeHeadingLevels($field->headingLevels ?? self::DEFAULT_HEADING_LEVELS);
        $options = isset($field->options) && is_array($field->options) ? $field->options : null;
        $js = $this->nonEmptyString($field->js ?? null);
        $jsFile = $this->nonEmptyString($field->jsFile ?? null);
        $css = $this->nonEmptyString($field->css ?? null);
        $cssFile = $this->nonEmptyString($field->cssFile ?? null);

        if ($jsFile !== null) {
            $contents = $this->readConfigFile($jsFile);
            if ($contents === null) {
                $warnings[] = sprintf(
                    'The CKEditor config file "%s" selected on field %s could not be read from config/ckeditor/. '
                    . 'The field is rendered without its custom config options; restore the file or pick another one in the field settings.',
                    $jsFile,
                    $label
                );
            } elseif (strtolower(pathinfo($jsFile, PATHINFO_EXTENSION)) === 'json') {
                $decoded = json_decode($contents, true);
                if (!is_array($decoded)) {
                    $warnings[] = sprintf(
                        'The CKEditor config file "%s" selected on field %s is not valid JSON and was ignored.',
                        $jsFile,
                        $label
                    );
                } else {
                    $options = $decoded;
                    $js = null;
                }
            } else {
                $js = $contents;
                $options = null;
            }
        }

        if ($options !== null) {
            // Craft only ever applies one of the two.
            $js = null;
        }

        if ($cssFile !== null) {
            $contents = $this->readConfigFile($cssFile);
            if ($contents === null) {
                $warnings[] = sprintf(
                    'The CKEditor CSS file "%s" selected on field %s could not be read from config/ckeditor/ and was ignored.',
                    $cssFile,
                    $label
                );
            } else {
                $css = $this->nonEmptyString($contents);
            }
        }

        return new ResolvedCkeConfig(
            $toolbar,
            $headingLevels,
            $options,
            $js,
            $css,
            ResolvedCkeConfig::SOURCE_FIELD,
            $this->fieldName($field),
        );
    }

    /**
     * craftcms/ckeditor 3.x/4.x: the field points at a shared config by UID.
     * An unknown UID falls back to the plugin's defaults, exactly like
     * `craft\ckeditor\Field::_ckeConfig()` does in the control panel — but
     * here it is reported instead of silently swallowed.
     *
     * @param string[] $warnings
     */
    private function fromSharedConfig(object $field, string $overrideUid, array &$warnings): ResolvedCkeConfig
    {
        $label = $this->describeField($field);
        $source = ResolvedCkeConfig::SOURCE_DEFAULT;
        $ckeConfig = null;

        if ($overrideUid !== '') {
            $ckeConfig = $this->loadSharedConfig($overrideUid, $warnings);
            if ($ckeConfig === null) {
                $warnings[] = sprintf(
                    'The "CKEditor config" plugin setting points at shared config "%s", which does not exist (Settings → CKEditor). '
                    . 'Falling back to the config selected on field %s. Update the plugin setting to fix this.',
                    $overrideUid,
                    $label
                );
            } else {
                $source = ResolvedCkeConfig::SOURCE_OVERRIDE;
            }
        }

        if ($ckeConfig === null) {
            $uid = $this->nonEmptyString($field->ckeConfig ?? null);
            if ($uid !== null) {
                $ckeConfig = $this->loadSharedConfig($uid, $warnings);
                if ($ckeConfig === null) {
                    $warnings[] = sprintf(
                        'Field %s references CKEditor config "%s", which no longer exists (Settings → CKEditor). '
                        . 'The CKEditor plugin\'s default toolbar is used, matching the control panel; select a config on the field to fix this.',
                        $label,
                        $uid
                    );
                } else {
                    $source = ResolvedCkeConfig::SOURCE_SHARED_CONFIG;
                }
            }
        }

        if ($ckeConfig === null) {
            return $this->defaults($source, $this->fieldName($field));
        }

        $options = isset($ckeConfig->options) && is_array($ckeConfig->options) ? $ckeConfig->options : null;
        $js = $options === null ? $this->nonEmptyString($ckeConfig->js ?? null) : null;

        return new ResolvedCkeConfig(
            isset($ckeConfig->toolbar) && is_array($ckeConfig->toolbar) ? $ckeConfig->toolbar : self::DEFAULT_TOOLBAR,
            self::normalizeHeadingLevels($ckeConfig->headingLevels ?? self::DEFAULT_HEADING_LEVELS),
            $options,
            $js,
            $this->nonEmptyString($ckeConfig->css ?? null),
            $source,
            $this->nonEmptyString($ckeConfig->name ?? null) ?? $this->fieldName($field),
        );
    }

    /**
     * @param string[] $warnings
     */
    private function loadSharedConfig(string $uid, array &$warnings): ?object
    {
        if ($this->sharedConfigLoader === null) {
            $warnings[] = sprintf(
                'Shared CKEditor config "%s" was requested, but the installed CKEditor plugin does not provide shared configs.',
                $uid
            );
            return null;
        }
        $config = ($this->sharedConfigLoader)($uid);
        return is_object($config) ? $config : null;
    }

    private function readConfigFile(string $file): ?string
    {
        if ($this->configFileReader === null) {
            return null;
        }
        $contents = ($this->configFileReader)($file);
        return is_string($contents) ? $contents : null;
    }

    private function defaults(string $source, ?string $name): ResolvedCkeConfig
    {
        return new ResolvedCkeConfig(
            self::DEFAULT_TOOLBAR,
            self::DEFAULT_HEADING_LEVELS,
            null,
            null,
            null,
            $source,
            $name,
        );
    }

    /**
     * @return string[]
     */
    public static function normalizeToolbar(array $toolbar): array
    {
        $items = [];
        foreach ($toolbar as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }
            // The CKEditor plugin renamed "anchor" to "bookmark" (see craft\ckeditor\CkeConfig::__construct()).
            $items[] = $item === 'anchor' ? 'bookmark' : $item;
        }
        return array_values($items);
    }

    /**
     * @return int[]|false
     */
    public static function normalizeHeadingLevels(mixed $levels): array|false
    {
        if (!is_array($levels)) {
            return false;
        }
        $normalized = [];
        foreach ($levels as $level) {
            if (is_numeric($level)) {
                $level = (int)$level;
                if ($level >= 1 && $level <= 6) {
                    $normalized[$level] = $level;
                }
            }
        }
        if ($normalized === []) {
            return false;
        }
        ksort($normalized);
        return array_values($normalized);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return trim($value) === '' ? null : $value;
    }

    private function fieldName(object $field): ?string
    {
        return $this->nonEmptyString($field->name ?? null) ?? $this->nonEmptyString($field->handle ?? null);
    }

    private function describeField(object $field): string
    {
        $name = $this->nonEmptyString($field->name ?? null);
        $handle = $this->nonEmptyString($field->handle ?? null);
        if ($name !== null && $handle !== null) {
            return sprintf('"%s" (handle: %s)', $name, $handle);
        }
        return sprintf('"%s"', $name ?? $handle ?? get_class($field));
    }
}
