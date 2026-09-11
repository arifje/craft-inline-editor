<?php

namespace arifje\inlineeditor\services;

use arifje\inlineeditor\helpers\CkeConfigResolver;
use arifje\inlineeditor\models\ResolvedCkeConfig;
use arifje\inlineeditor\Plugin;
use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\web\View;
use Throwable;
use yii\base\InvalidArgumentException;

/**
 * CKEditor integration — Craft-aware glue around {@see CkeConfigResolver}.
 *
 * Responsibilities:
 * - detect which craftcms/ckeditor plugin line is installed and what it exposes;
 * - resolve the configuration of the specific field being edited;
 * - register that configuration (and custom CSS) on the page, once per field;
 * - decide which CKEditor 5 build the frontend loads and verify it is usable.
 */
class Ckeditor extends Component
{
    /**
     * Last CKEditor 5 release that can be loaded from the CKEditor CDN without a
     * licence key. From 44.0.0 on, the CDN distribution refuses the `GPL` key
     * and requires a commercial key, so newer CDN builds are not an option for
     * an open-source plugin. Self-hosted builds (including the one shipped with
     * craftcms/ckeditor 5.x) may use the `GPL` key.
     */
    public const CDN_VERSION = '43.3.1';
    public const CDN_BASE_URL = 'https://cdn.ckeditor.com/ckeditor5/';

    public const BUILD_SOURCE_SETTING = 'setting';
    public const BUILD_SOURCE_CRAFT = 'craft-bundled';
    public const BUILD_SOURCE_CDN = 'cdn';

    /**
     * Regional language codes for which CKEditor ships dedicated translations;
     * everything else falls back to the primary language subtag.
     */
    private const REGIONAL_LANGUAGES = ['de-ch', 'en-au', 'en-gb', 'es-co', 'pt-br', 'sr-latn', 'zh-cn'];

    /** @var array<string, ResolvedCkeConfig> */
    private array $resolved = [];
    /** @var array<string, true> */
    private array $registered = [];
    private ?array $build = null;

    /**
     * Whether the craftcms/ckeditor plugin is installed and enabled.
     */
    public static function isPluginInstalled(): bool
    {
        return class_exists(\craft\ckeditor\Plugin::class) && \craft\ckeditor\Plugin::getInstance() !== null;
    }

    /**
     * Whether the installed CKEditor plugin still has shared configs
     * (Settings → CKEditor; craftcms/ckeditor 3.x and 4.x). 5.x removed them.
     */
    public static function supportsSharedConfigs(): bool
    {
        if (!self::isPluginInstalled()) {
            return false;
        }
        return method_exists(\craft\ckeditor\Plugin::getInstance(), 'getCkeConfigs');
    }

    public static function isCkeditorField(?FieldInterface $field): bool
    {
        return $field !== null
            && class_exists(\craft\ckeditor\Field::class)
            && $field instanceof \craft\ckeditor\Field;
    }

    public static function getPluginVersion(): ?string
    {
        if (!self::isPluginInstalled()) {
            return null;
        }
        try {
            $info = Craft::$app->getPlugins()->getPluginInfo('ckeditor');
            $version = $info['version'] ?? null;
            return is_string($version) && $version !== '' ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Creates a resolver wired to the installed CKEditor plugin.
     */
    public function createResolver(): CkeConfigResolver
    {
        $sharedConfigLoader = null;
        if (self::supportsSharedConfigs()) {
            $sharedConfigLoader = static function(string $uid): ?object {
                try {
                    return \craft\ckeditor\Plugin::getInstance()->getCkeConfigs()->getByUid($uid);
                } catch (InvalidArgumentException) {
                    // Unknown UID — reported by the resolver, not swallowed.
                    return null;
                }
            };
        }

        $configFileReader = static function(string $file): ?string {
            // Only plain file names are valid here; never let a setting escape config/ckeditor/.
            $file = basename($file);
            $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'ckeditor' . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path) || !is_readable($path)) {
                return null;
            }
            $contents = @file_get_contents($path);
            return $contents === false ? null : $contents;
        };

        return new CkeConfigResolver($sharedConfigLoader, $configFileReader);
    }

    /**
     * Resolves the configuration of a specific CKEditor field for the current
     * user. Warnings are logged (once per field, per request) with enough
     * context to act on, and never include config contents.
     */
    public function resolveFieldConfig(FieldInterface $field): ResolvedCkeConfig
    {
        $key = $this->fieldKey($field);
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $user = Craft::$app->getUser()->getIdentity();
        $groupUids = [];
        if ($user !== null) {
            foreach ($user->getGroups() as $group) {
                $groupUids[] = (string)$group->uid;
            }
        }
        $allowSourceEditing = CkeConfigResolver::isSourceEditingAllowed(
            $field->sourceEditingGroups ?? null,
            $user !== null && $user->admin,
            $groupUids
        );

        $overrideUid = (string)(Plugin::getInstance()->getSettings()->ckeditorConfig ?? '');
        $config = $this->createResolver()->resolve($field, $overrideUid, $allowSourceEditing);

        foreach ($config->warnings as $warning) {
            Craft::warning('Inline Editor: ' . $warning, __METHOD__);
        }

        return $this->resolved[$key] = $config;
    }

    /**
     * Registers the field's configuration on the page so inline-editor.js can
     * pick it up by field UID. Idempotent per field.
     *
     * The JSON-safe part is emitted as data; the JavaScript config body (if
     * any) is emitted as a real function so it can return plugin classes and
     * callbacks, exactly like the control panel does.
     */
    public function registerFieldConfig(FieldInterface $field, View $view): ResolvedCkeConfig
    {
        $config = $this->resolveFieldConfig($field);
        $key = $this->fieldKey($field);
        if (isset($this->registered[$key])) {
            return $config;
        }
        $this->registered[$key] = true;

        $data = $config->toJsData();
        $data['handle'] = $field->handle;

        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
        $dataJson = json_encode($data, $jsonFlags);
        $keyJson = json_encode($key, $jsonFlags);

        $js = "(function(){var registry=window.InlineEditorCKConfigs=window.InlineEditorCKConfigs||{};var data={$dataJson};";
        if ($config->js !== null) {
            // The body is admin-authored config from the field settings — the same
            // trust level as the control panel gives it. Only make sure it cannot
            // terminate the surrounding <script> element.
            $body = str_replace('</script', '<\\/script', $config->js);
            $js .= "data.js=function(){\n{$body}\n};";
        }
        $js .= "registry[{$keyJson}]=data;})();";

        $view->registerJs($js, View::POS_END, 'inline-editor-ck-' . $key);

        if ($config->css !== null) {
            $this->registerCss($view, $key, $config->css);
        }

        return $config;
    }

    /**
     * Scopes the field's custom styles to its inline editors, like the control
     * panel scopes them to the field container (CSS nesting).
     */
    private function registerCss(View $view, string $key, string $css): void
    {
        preg_match_all('/@import\s[^;]+;?/m', $css, $matches);
        foreach ($matches[0] as $import) {
            $css = str_replace($import, '', $css);
        }
        if (!empty($matches[0])) {
            $view->registerCss(implode("\n", $matches[0]), [], 'inline-editor-ck-css-imports-' . $key);
        }
        $css = trim($css);
        if ($css === '') {
            return;
        }
        $selector = sprintf('.inline-editor[data-field-uid="%s"]', addcslashes($key, '"\\'));
        $view->registerCss("{$selector} { {$css} }", [], 'inline-editor-ck-css-' . $key);
    }

    /**
     * Which CKEditor 5 build the frontend should load, in order of preference:
     *
     * 1. The build configured in the plugin settings (self-hosted, any version).
     * 2. The UMD build shipped with craftcms/ckeditor 5.x, published through
     *    Craft's asset manager. Same version and plugin set as the control panel,
     *    no third-party requests, `GPL` licence key like Craft's own editor.
     * 3. The CKEditor CDN, pinned to the last release that needs no licence key
     *    (craftcms/ckeditor 3.x/4.x ship a DLL build that does not expose the
     *    feature plugins, so it cannot be reused on the frontend).
     *
     * inline-editor.js verifies, per toolbar item, that the loaded build actually
     * provides the required plugins and drops (and reports) the ones it cannot.
     *
     * @return array{scriptUrl:string,cssUrl:?string,translationUrl:?string,licenseKey:?string,source:string,version:?string,uiLanguage:string,contentLanguage:string}
     */
    public function getEditorBuild(): array
    {
        if ($this->build !== null) {
            return $this->build;
        }

        $settings = Plugin::getInstance()->getSettings();
        $uiLanguage = $this->uiLanguage();
        $contentLanguage = Craft::$app->getSites()->getCurrentSite()->language ?? Craft::$app->language;
        $base = ['uiLanguage' => $uiLanguage, 'contentLanguage' => $contentLanguage];

        $customUrl = trim((string)$settings->ckeditorBuildUrl);
        if ($customUrl !== '') {
            return $this->build = $base + [
                'scriptUrl' => $customUrl,
                'cssUrl' => trim((string)$settings->ckeditorBuildCssUrl) ?: null,
                'translationUrl' => null,
                'licenseKey' => trim((string)$settings->ckeditorLicenseKey) ?: null,
                'source' => self::BUILD_SOURCE_SETTING,
                'version' => null,
            ];
        }

        $bundled = $this->craftBundledBuild($uiLanguage);
        if ($bundled !== null) {
            return $this->build = $base + $bundled;
        }

        $prefix = self::CDN_BASE_URL . self::CDN_VERSION;
        return $this->build = $base + [
            'scriptUrl' => "{$prefix}/ckeditor5.umd.js",
            'cssUrl' => "{$prefix}/ckeditor5.css",
            'translationUrl' => $uiLanguage !== 'en' ? "{$prefix}/translations/{$uiLanguage}.umd.js" : null,
            'licenseKey' => null,
            'source' => self::BUILD_SOURCE_CDN,
            'version' => self::CDN_VERSION,
        ];
    }

    /**
     * The UMD build shipped with craftcms/ckeditor 5.x, if available.
     */
    private function craftBundledBuild(string $uiLanguage): ?array
    {
        if (!self::isPluginInstalled() || !class_exists(\craft\ckeditor\web\assets\ckeditor\CkeditorAsset::class)) {
            return null;
        }

        try {
            $script = 'lib/ckeditor5.umd.js';

            // Probe the unpublished source first, so 3.x/4.x installs (DLL build without
            // the feature plugins → CDN fallback) never publish the bundle needlessly.
            $probe = new \craft\ckeditor\web\assets\ckeditor\CkeditorAsset();
            $sourcePath = (string)Craft::getAlias((string)$probe->sourcePath);
            if ($sourcePath === '' || !is_file($sourcePath . '/' . $script)) {
                return null;
            }

            $assetManager = Craft::$app->getView()->getAssetManager();
            // getBundle() publishes only this bundle's files — not its CP dependencies.
            $bundle = $assetManager->getBundle(\craft\ckeditor\web\assets\ckeditor\CkeditorAsset::class);
            $basePath = (string)$bundle->basePath;
            if ($basePath === '' || !is_file($basePath . '/' . $script)) {
                Craft::warning('Inline Editor: the CKEditor plugin\'s editor build was not published correctly; falling back to the CKEditor CDN build.', __METHOD__);
                return null;
            }

            $css = 'lib/ckeditor5.css';
            $translation = "lib/translations/{$uiLanguage}.umd.js";

            return [
                'scriptUrl' => $assetManager->getAssetUrl($bundle, $script),
                'cssUrl' => is_file("{$basePath}/{$css}") ? $assetManager->getAssetUrl($bundle, $css) : null,
                'translationUrl' => $uiLanguage !== 'en' && is_file("{$basePath}/{$translation}")
                    ? $assetManager->getAssetUrl($bundle, $translation)
                    : null,
                'licenseKey' => 'GPL',
                'source' => self::BUILD_SOURCE_CRAFT,
                'version' => self::getPluginVersion(),
            ];
        } catch (Throwable $e) {
            Craft::warning(
                'Inline Editor: could not publish the CKEditor build shipped with the CKEditor plugin ('
                . get_class($e) . ': ' . $e->getMessage() . '); falling back to the CKEditor CDN build.',
                __METHOD__
            );
            return null;
        }
    }

    /**
     * CKEditor UI language for the current Craft language, using the same
     * mapping as craft\ckeditor\web\assets\BaseCkeditorPackageAsset::uiLanguage().
     */
    private function uiLanguage(): string
    {
        $language = strtolower((string)Craft::$app->language);
        $language = match ($language) {
            'nb', 'nn' => 'no',
            'en-us', '' => 'en',
            default => $language,
        };
        if (str_contains($language, '-') && !in_array($language, self::REGIONAL_LANGUAGES, true)) {
            $language = substr($language, 0, strpos($language, '-'));
        }
        return $language;
    }

    private function fieldKey(FieldInterface $field): string
    {
        $uid = $field->uid ?? null;
        return is_string($uid) && $uid !== '' ? $uid : 'handle:' . (string)$field->handle;
    }
}
