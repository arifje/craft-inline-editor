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
     * UID of a shared CKEditor config (Settings → CKEditor, craftcms/ckeditor 3.x/4.x).
     *
     * By default the inline editor uses the configuration of the CKEditor field
     * being edited. When this is set, that shared config is applied to every
     * inline CKEditor instead (legacy behaviour of versions before 1.8.0).
     *
     * craftcms/ckeditor 5.x has no shared configs; the setting is ignored there
     * (a warning is logged) and the field's own configuration is used.
     */
    public string $ckeditorConfig = '';

    /**
     * URL of a self-hosted CKEditor 5 build (`ckeditor5.umd.js`, or a classic
     * build exposing `window.ClassicEditor`). Leave empty to use the build shipped
     * with craftcms/ckeditor 5.x, or the pinned CDN build for 3.x/4.x.
     */
    public string $ckeditorBuildUrl = '';

    /**
     * URL of the stylesheet that belongs to `ckeditorBuildUrl` (`ckeditor5.css`).
     * Ignored when `ckeditorBuildUrl` is empty.
     */
    public string $ckeditorBuildCssUrl = '';

    /**
     * Licence key passed to the editor when `ckeditorBuildUrl` is used.
     * CKEditor 5 builds from 44.0.0 on require one (`GPL` for the open-source
     * licence, self-hosted only). Ignored when `ckeditorBuildUrl` is empty.
     */
    public string $ckeditorLicenseKey = '';

    public function defineRules(): array
    {
        return [
            ['allowedGroupIds', 'each', 'rule' => ['integer']],
            [['ckeditorPurifierConfig', 'ckeditorConfig', 'ckeditorBuildUrl', 'ckeditorBuildCssUrl', 'ckeditorLicenseKey'], 'trim'],
            [['ckeditorPurifierConfig', 'ckeditorConfig', 'ckeditorBuildUrl', 'ckeditorBuildCssUrl', 'ckeditorLicenseKey'], 'string'],
        ];
    }
}
