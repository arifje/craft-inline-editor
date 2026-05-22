<?php

namespace arifje\inlineeditor;

use arifje\inlineeditor\models\Settings;
use arifje\inlineeditor\services\Editor;
use arifje\inlineeditor\twig\Extension as TwigExtension;
use arifje\inlineeditor\web\assets\editor\EditorAsset;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\View;
use yii\base\Event;

/**
 * Inline Editor plugin.
 *
 * @property-read Editor $editor
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public static ?Plugin $plugin = null;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'editor' => Editor::class,
        ]);

        Craft::$app->view->registerTwigExtension(new TwigExtension());

        $this->registerCpTemplateRoot();
        $this->registerSiteAssets();
    }

    public function getEditor(): Editor
    {
        return $this->get('editor');
    }

    /**
     * Returns true when the current user may use the inline editor.
     * Admins always have access; non-admins need to belong to at least one
     * of the groups configured in the plugin settings.
     */
    public function canCurrentUserEdit(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            return false;
        }
        if ($user->admin) {
            return true;
        }
        $allowedGroupIds = array_map('intval', (array)$this->getSettings()->allowedGroupIds);
        if (empty($allowedGroupIds)) {
            return false;
        }
        foreach ($user->getGroups() as $group) {
            if (in_array($group->id, $allowedGroupIds, true)) {
                return true;
            }
        }
        return false;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('inline-editor/_settings', [
            'settings' => $this->getSettings(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
            'purifierConfigs' => $this->getPurifierConfigOptions(),
        ]);
    }

    /**
     * Returns select options for every .json file found in config/htmlpurifier/.
     */
    private function getPurifierConfigOptions(): array
    {
        $options = [['label' => Craft::t('inline-editor', 'None (rely on field settings)'), 'value' => '']];

        $dir = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier';
        if (is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $options[] = ['label' => $name, 'value' => $name];
            }
        }

        return $options;
    }

    private function registerCpTemplateRoot(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event): void {
                $event->roots[$this->id] = __DIR__ . '/templates';
            }
        );
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
                if (!Plugin::getInstance()->canCurrentUserEdit()) {
                    return;
                }
                Craft::$app->getView()->registerAssetBundle(EditorAsset::class);
            }
        );
    }
}
