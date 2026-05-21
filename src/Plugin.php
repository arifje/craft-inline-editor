<?php

namespace arifje\inlineeditor;

use arifje\inlineeditor\services\Editor;
use arifje\inlineeditor\twig\Extension as TwigExtension;
use arifje\inlineeditor\web\assets\editor\EditorAsset;
use Craft;
use craft\base\Plugin as BasePlugin;
use craft\web\View;
use yii\base\Event;

/**
 * Inline Editor plugin.
 *
 * @property-read Editor $editor
 */
class Plugin extends BasePlugin
{
    public static ?Plugin $plugin = null;

    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'editor' => Editor::class,
        ]);

        Craft::$app->view->registerTwigExtension(new TwigExtension());

        $this->registerSiteAssets();
    }

    public function getEditor(): Editor
    {
        return $this->get('editor');
    }

    private function registerSiteAssets(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            static function (): void {
                $request = Craft::$app->getRequest();
                if (!$request->getIsSiteRequest() || $request->getIsConsoleRequest()) {
                    return;
                }
                if (!Craft::$app->getUser()->getIsAdmin()) {
                    return;
                }
                Craft::$app->getView()->registerAssetBundle(EditorAsset::class);
            }
        );
    }
}
