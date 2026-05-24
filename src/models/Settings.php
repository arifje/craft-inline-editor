<?php

namespace arifje\inlineeditor\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * IDs of user groups whose members may use the inline editor.
     * Administrators always have access regardless of this setting.
     *
     * @var int[]
     */
    public array $allowedGroupIds = [];

    /**
     * Filename (without .json extension) of the HTML Purifier config to apply
     * when saving CKEditor field content. Files live in config/htmlpurifier/.
     * Leave empty to skip purification here and rely on Craft's own field processing.
     */
    public string $ckeditorPurifierConfig = '';

    /**
     * UID of the CKEditor config (as defined in the craftcms/ckeditor plugin's
     * CP settings and stored in project config). Its toolbar, heading levels,
     * and custom JS are merged into the inline editor's CKEditor instance.
     * Leave empty to use the built-in defaults.
     */
    public string $ckeditorConfig = '';

    public function defineRules(): array
    {
        return [
            ['allowedGroupIds', 'each', 'rule' => ['integer']],
            [['ckeditorPurifierConfig', 'ckeditorConfig'], 'string'],
        ];
    }
}
