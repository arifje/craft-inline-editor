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
     * Filename (without .js extension) of the CKEditor JS config to load from
     * config/ckeditor/. Its exported object is merged with the inline editor's
     * base CKEditor config, so toolbar items, plugins and decorators are applied.
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
