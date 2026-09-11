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
            'saveUrl'         => UrlHelper::actionUrl('inline-editor/default/save'),
            'searchTagsUrl'   => UrlHelper::actionUrl('inline-editor/default/search-tags'),
            'replaceAssetUrl' => UrlHelper::actionUrl('inline-editor/default/replace-asset'),
            'csrfTokenName'   => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfToken'       => Craft::$app->getRequest()->getCsrfToken(),
            // Which CKEditor 5 build to lazy-load, and how (see services\Ckeditor::getEditorBuild()).
            // Per-field CKEditor configuration is registered separately, by field UID,
            // in window.InlineEditorCKConfigs when a CKEditor field is rendered.
            'ckeditor'        => Plugin::getInstance()->getCkeditor()->getEditorBuild(),
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $view->registerJs("window.InlineEditorConfig = {$json};", View::POS_HEAD);
    }
}
