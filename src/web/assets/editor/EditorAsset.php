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

        // CSS is always needed (tag chips render for everyone).
        // JS config globals are only written for users who can actually edit.
        if (!Plugin::getInstance()->canCurrentUserEdit()) {
            return;
        }

        $config = [
            'saveUrl' => UrlHelper::actionUrl('inline-editor/default/save'),
            'searchTagsUrl' => UrlHelper::actionUrl('inline-editor/default/search-tags'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfToken' => Craft::$app->getRequest()->getCsrfToken(),
            'ckeditorCdn' => 'https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js',
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $view->registerJs("window.InlineEditorConfig = {$json};", View::POS_HEAD);

        // Load the selected CKEditor config from Craft's project config
        // (managed by the craftcms/ckeditor plugin) and expose it to JS.
        $configUid = Plugin::getInstance()->getSettings()->ckeditorConfig;
        if ($configUid !== '' && class_exists(\craft\ckeditor\Plugin::class)) {
            try {
                $ckPlugin = \craft\ckeditor\Plugin::getInstance();
                if ($ckPlugin !== null) {
                    $ckeConfig = $ckPlugin->getCkeConfigs()->getByUid($configUid);

                    // toolbar and headingLevels are safe to JSON-encode.
                    $data = [
                        'toolbar'       => $ckeConfig->toolbar,
                        'headingLevels' => $ckeConfig->headingLevels,
                    ];
                    $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $view->registerJs("window.InlineEditorCKData = {$dataJson};", View::POS_HEAD);

                    // The JS property may contain function references (e.g. plugin
                    // classes), so it cannot be JSON-encoded. Wrap it in a callable
                    // so inline-editor.js can evaluate it safely via `jsFn()`.
                    if ($ckeConfig->js !== null && trim($ckeConfig->js) !== '') {
                        $view->registerJs(
                            "window.InlineEditorCKJsFn = function(){\n" . $ckeConfig->js . "\n};",
                            View::POS_HEAD
                        );
                    }
                }
            } catch (\Throwable $e) {
                // Config UID not found or CKEditor plugin not initialised — skip.
            }
        }
    }
}
