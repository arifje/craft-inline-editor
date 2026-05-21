<?php

namespace arifje\inlineeditor\web\assets\editor;

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
    }
}
