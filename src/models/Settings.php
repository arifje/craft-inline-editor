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

    public function defineRules(): array
    {
        return [
            ['allowedGroupIds', 'each', 'rule' => ['integer']],
        ];
    }
}
