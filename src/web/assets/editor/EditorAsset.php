<?php

namespace arifje\inlineeditor\web\assets\editor;

use arifje\inlineeditor\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\AssetBundle;
use craft\web\View;

class EditorAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->css = ['css/inline-editor.css'];
        $this->js = ['js/inline-editor.js'];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        $config = [
            'saveUrl' => UrlHelper::actionUrl('inline-editor/default/save'),
            'searchTagsUrl' => UrlHelper::actionUrl('inline-editor/default/search-tags'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfToken' => Craft::$app->getRequest()->getCsrfToken(),
            'ckeditorCdn' => 'https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js',
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $view->registerJs("window.InlineEditorConfig = {$json};", View::POS_HEAD);

        // Inline the raw CKEditor JS config source so the browser can evaluate
        // and merge it without an extra HTTP request.
        $configName = Plugin::getInstance()->getSettings()->ckeditorConfig;
        if ($configName !== '') {
            $configPath = Craft::$app->getPath()->getConfigPath()
                . DIRECTORY_SEPARATOR . 'ckeditor'
                . DIRECTORY_SEPARATOR . $configName . '.js';

            if (is_file($configPath)) {
                $source = file_get_contents($configPath);
                // Wrap in a function so `return` at top level is valid, then
                // expose the resulting object for inline-editor.js to consume.
                $view->registerJs(
                    "window.InlineEditorCKConfig = (function(){\n" . $source . "\n})();",
                    View::POS_HEAD
                );
            }
        }
    }
}
