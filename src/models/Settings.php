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

    public function defineRules(): array
    {
        return [
            ['allowedGroupIds', 'each', 'rule' => ['integer']],
            ['ckeditorPurifierConfig', 'string'],
        ];
    }
}
