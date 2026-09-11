<?php

namespace arifje\inlineeditor\services;

use arifje\inlineeditor\helpers\ContentHelper;
use arifje\inlineeditor\Plugin;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Tag;
use craft\fields\Assets as AssetsField;
use craft\fields\PlainText;
use craft\fields\Tags;
use craft\helpers\Db;
use Throwable;
use yii\base\InvalidArgumentException;

/**
 * Editor service — resolves field metadata and renders the wrapper HTML for
 * inline-editable values.
 */
class Editor extends Component
{
    public const TYPE_TITLE = 'title';
    public const TYPE_PLAINTEXT = 'plaintext';
    public const TYPE_URL = 'url';
    public const TYPE_CKEDITOR = 'ckeditor';
    public const TYPE_TAGS = 'tags';
    public const TYPE_ASSETS = 'assets';

    /**
     * Render the wrapper HTML for an editable field.
     *
     * When the current user may not edit (or the request is not a site
     * request) the bare value is returned, so the helper is safe to use in any
     * template without leaking edit affordances to anonymous visitors.
     *
     * @param ElementInterface $element The element being rendered.
     * @param string $handle Field handle, or "title" for the element title.
     * @param array $options
     *   - tag:         HTML tag to wrap with (default: span / div for ckeditor+tags)
     *   - class:       Extra CSS class names
     *   - attributes:  Extra attributes (key => value)
     *   - inputType:   Override input type for plaintext (input|textarea)
     *   - placeholder: Empty-state placeholder text
     *   - innerHtml:   Custom display HTML to show instead of the raw field value.
     *                  Useful when the template applies Twig filters to the value
     *                  (e.g. replacing blockquote classes, swapping domains).
     *                  For CKEditor fields the raw stored content is still passed to
     *                  the editor via data-value, so the admin edits the actual stored
     *                  content regardless of display transformations.
     *                  For non-editors the custom HTML is returned as-is.
     */
    public function render(ElementInterface $element, string $handle, array $options = []): string
    {
        try {
            $type = $this->detectType($element, $handle);
        } catch (InvalidArgumentException $e) {
            Craft::warning('Inline Editor: ' . $e->getMessage(), __METHOD__);
            return isset($options['innerHtml']) ? (string)$options['innerHtml'] : '';
        }

        $field = $handle === 'title' ? null : $this->getField($element, $handle);
        $fieldValue = $handle === 'title' ? $element->title : $element->getFieldValue($handle);
        $rawValue = $this->getRawValue($fieldValue, $type);
        $displayValue = $this->getDisplayValue($type, $fieldValue, $rawValue);

        // Template-provided display HTML (e.g. with Twig filter transformations applied).
        $innerHtml = isset($options['innerHtml']) ? (string)$options['innerHtml'] : null;

        $editable = $this->isEditable($element);
        if ($editable) {
            $blocker = $this->getEditBlocker($element, $handle, $type, $fieldValue, $rawValue);
            if ($blocker !== null) {
                Craft::info('Inline Editor: ' . $blocker, __METHOD__);
                $editable = false;
            }
        }

        if (!$editable) {
            // For tags: don't emit inline-editor__tag spans — the plugin CSS is
            // not loaded for non-editors. Use plain spans so the site's own
            // stylesheet (or bare inline text) is all that's needed.
            if ($type === self::TYPE_TAGS && $innerHtml === null) {
                $display = implode('', array_map(
                    static fn(array $t) => '<span>' . htmlspecialchars($t['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
                    $rawValue
                ));
            } else {
                $display = $innerHtml ?? $displayValue;
            }

            // If the template specified layout options (tag / class / attributes),
            // honour them for non-editors too so the page looks identical regardless
            // of login state.
            $hasLayout = isset($options['tag'])
                || (isset($options['class']) && $options['class'] !== '')
                || !empty($options['attributes']);

            if (!$hasLayout) {
                return $display;
            }

            $defaultTag = in_array($type, [self::TYPE_CKEDITOR, self::TYPE_TAGS], true) ? 'div' : 'span';
            $tag        = $options['tag'] ?? $defaultTag;
            $attrs      = [];
            if (!empty($options['class'])) {
                $attrs['class'] = $options['class'];
            }
            foreach (($options['attributes'] ?? []) as $k => $v) {
                $attrs[$k] = $v;
            }
            $attrStr = $this->buildAttributes($attrs);
            return "<{$tag}" . ($attrStr !== '' ? " {$attrStr}" : '') . ">{$display}</{$tag}>";
        }

        $defaultTag = in_array($type, [self::TYPE_CKEDITOR, self::TYPE_TAGS, self::TYPE_ASSETS], true) ? 'div' : 'span';
        $tag = $options['tag'] ?? $defaultTag;
        $extraClass = $options['class'] ?? '';
        $extraAttributes = $options['attributes'] ?? [];
        $placeholder = $options['placeholder'] ?? '';
        $inputType = $options['inputType'] ?? match(true) {
            $type === self::TYPE_CKEDITOR => 'ckeditor',
            $type === self::TYPE_ASSETS   => 'assets',
            default                        => $this->looksMultiline($rawValue) ? 'textarea' : 'input',
        };

        $attrs = [
            'class' => trim('inline-editor ' . $extraClass),
            'data-inline-editor' => '',
            'data-element-id' => (string)$element->id,
            'data-element-uid' => $element->uid,
            'data-site-id' => (string)$element->siteId,
            'data-field' => $handle,
            'data-type' => $type,
            'data-input' => $inputType,
        ];

        if ($field !== null && is_string($field->uid) && $field->uid !== '') {
            $attrs['data-field-uid'] = $field->uid;
        }

        if ($placeholder !== '') {
            $attrs['data-placeholder'] = $placeholder;
        }

        if ($type === self::TYPE_ASSETS) {
            // Store current asset IDs so JS knows what's set and can pass them
            // to the server (e.g. to swap one asset in a multi-asset field).
            $attrs['data-asset-ids'] = json_encode($rawValue, JSON_UNESCAPED_UNICODE);
        }

        if ($type === self::TYPE_CKEDITOR) {
            // Always store the raw stored content (reference tags intact) so the
            // editor works on what is actually saved, not on the rendered output.
            $attrs['data-value'] = (string)$rawValue;

            if ($field !== null) {
                // Expose the configuration of *this* field to inline-editor.js.
                Plugin::getInstance()->getCkeditor()->registerFieldConfig($field, Craft::$app->getView());
            }
        }

        if ($type === self::TYPE_TAGS) {
            $attrs['data-tags'] = json_encode($rawValue, JSON_UNESCAPED_UNICODE);
            if ($field instanceof Tags) {
                $groupId = $this->getGroupIdFromTagsField($field);
                if ($groupId !== null) {
                    $attrs['data-group-id'] = (string)$groupId;
                }
            }
        }

        foreach ($extraAttributes as $name => $value) {
            $attrs[$name] = $value;
        }

        $attrString = $this->buildAttributes($attrs);

        $display = $innerHtml ?? $displayValue;
        $inner = $display !== '' ? $display : ($placeholder !== '' ? '<span class="inline-editor__placeholder">' . htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' : '');

        return "<{$tag} {$attrString}>{$inner}</{$tag}>";
    }

    /**
     * Persist a plain-text / URL / CKEditor field value.
     *
     * @throws InvalidArgumentException when the field is not inline-editable, or its
     *   current content cannot be edited safely from the frontend.
     */
    public function save(ElementInterface $element, string $handle, mixed $value): bool
    {
        $type = $this->detectType($element, $handle);

        if ($type === self::TYPE_TAGS || $type === self::TYPE_ASSETS) {
            throw new InvalidArgumentException(
                "Field \"{$handle}\" is a " . ($type === self::TYPE_TAGS ? 'Tags' : 'Assets')
                . ' field and cannot be saved as a text value.'
            );
        }

        $sanitized = $this->sanitize($value, $type);

        if ($type === self::TYPE_CKEDITOR) {
            $current = ContentHelper::rawContent($element->getFieldValue($handle));
            if (ContentHelper::containsNestedEntries($current)) {
                throw new InvalidArgumentException(
                    "Field \"{$handle}\" contains nested entries, which can only be edited in the control panel."
                );
            }
            if (ContentHelper::containsNestedEntries($sanitized)) {
                throw new InvalidArgumentException('Nested entries cannot be created or edited inline.');
            }

            $configName = Plugin::getInstance()->getSettings()->ckeditorPurifierConfig;
            if ($configName !== '') {
                $sanitized = $this->purifyHtml($sanitized, $configName);
            }
        }

        if ($type === self::TYPE_TITLE && !$this->hasEditableTitle($element)) {
            throw new InvalidArgumentException('This entry type has no Title field; its title is generated and cannot be edited inline.');
        }

        if ($type === self::TYPE_URL && $this->isElementLink($element->getFieldValue($handle))) {
            throw new InvalidArgumentException(
                "Field \"{$handle}\" links to an element, which can only be changed in the control panel."
            );
        }

        if ($handle === 'title') {
            $element->title = $sanitized;
        } else {
            $element->setFieldValue($handle, $sanitized);
        }

        return Craft::$app->getElements()->saveElement($element);
    }

    /**
     * The values to hand back to the frontend after a successful save.
     *
     * @return array{value: string, rawValue: string|null} `value` is what to display
     *   (rendered HTML for CKEditor fields, plain text otherwise); `rawValue` is the
     *   stored CKEditor content for the next edit, null for other types.
     */
    public function getSavedValues(ElementInterface $element, string $handle): array
    {
        $type = $this->detectType($element, $handle);
        $fieldValue = $handle === 'title' ? $element->title : $element->getFieldValue($handle);

        if ($type === self::TYPE_CKEDITOR) {
            return [
                'value' => $fieldValue === null ? '' : (string)$fieldValue,
                'rawValue' => ContentHelper::rawContent($fieldValue),
            ];
        }

        $raw = $this->getRawValue($fieldValue, $type);

        return [
            'value' => is_scalar($raw) ? (string)$raw : '',
            'rawValue' => null,
        ];
    }

    /**
     * Run HTML through a purifier config file from config/htmlpurifier/.
     */
    private function purifyHtml(string $html, string $configFile): string
    {
        $path = Craft::$app->getPath()->getConfigPath()
            . DIRECTORY_SEPARATOR . 'htmlpurifier'
            . DIRECTORY_SEPARATOR . basename($configFile) . '.json';

        if (!is_file($path)) {
            Craft::warning("Inline Editor: HTML Purifier config \"{$configFile}.json\" not found at {$path}.", __METHOD__);
            return $html;
        }

        $directives = json_decode((string)file_get_contents($path), true);
        $config = \HTMLPurifier_Config::createDefault();

        if (is_array($directives)) {
            foreach ($directives as $key => $val) {
                try {
                    $config->set($key, $val);
                } catch (\Exception $e) {
                    Craft::warning("Inline Editor: could not set HTMLPurifier directive \"{$key}\": " . $e->getMessage(), __METHOD__);
                }
            }
        }

        return (new \HTMLPurifier($config))->purify($html);
    }

    /**
     * Persist tag relations. Creates new tags on the fly when titles are supplied.
     *
     * @param int[]    $tagIds       IDs of existing tags to keep.
     * @param string[] $newTagTitles Titles of brand-new tags to create.
     * @return array{saved: bool, tags: array<array{id:int,title:string}>}
     */
    public function saveTags(ElementInterface $element, string $handle, array $tagIds, array $newTagTitles): array
    {
        $field = $this->getField($element, $handle);
        if (!($field instanceof Tags)) {
            throw new InvalidArgumentException("Field \"{$handle}\" is not a Tags field.");
        }

        foreach ($newTagTitles as $title) {
            $title = trim($title);
            if ($title === '') {
                continue;
            }
            $tag = $this->createTag($field, $title, $element->siteId);
            if ($tag !== null) {
                $tagIds[] = $tag->id;
            }
        }

        $element->setFieldValue($handle, array_values(array_unique($tagIds)));
        $saved = Craft::$app->getElements()->saveElement($element);

        $tags = [];
        if ($saved) {
            $tagQuery = $element->getFieldValue($handle);
            $tags = $this->tagsToArray($tagQuery->all());
        }

        return ['saved' => $saved, 'tags' => $tags];
    }

    /**
     * Search tags within a group for the autocomplete dropdown.
     *
     * @return array<array{id:int,title:string}>
     */
    public function searchTags(int $groupId, string $query, int $siteId): array
    {
        $tagQuery = Tag::find()
            ->groupId($groupId)
            ->siteId($siteId)
            ->limit(20);

        if ($query !== '') {
            $tagQuery->search('*' . $query . '*');
        }

        return $this->tagsToArray($tagQuery->all());
    }

    /**
     * Detect which input UI to expose for a given field handle.
     *
     * @throws InvalidArgumentException when the field does not exist or is not supported.
     */
    public function detectType(ElementInterface $element, string $handle): string
    {
        if ($handle === 'title') {
            return self::TYPE_TITLE;
        }

        $field = $this->getField($element, $handle);

        if ($field === null) {
            throw new InvalidArgumentException("Field \"{$handle}\" does not exist in the field layout of element {$element->id}.");
        }

        if ($field instanceof AssetsField) {
            return self::TYPE_ASSETS;
        }

        if ($field instanceof Tags) {
            return self::TYPE_TAGS;
        }

        if ($field instanceof PlainText) {
            return self::TYPE_PLAINTEXT;
        }

        if ($this->isUrlField($field)) {
            return self::TYPE_URL;
        }

        if (Ckeditor::isCkeditorField($field)) {
            return self::TYPE_CKEDITOR;
        }

        throw new InvalidArgumentException(
            "Field \"{$handle}\" (" . get_class($field) . ') is not a supported inline-editable field type.'
        );
    }

    /**
     * Craft 4 URL fields, and the Link fields that replaced them in Craft 5.3.
     * Link fields are editable inline as long as they hold a plain URL / email /
     * phone value; links to elements (entries, assets, …) are left to the control panel.
     */
    private function isUrlField(FieldInterface $field): bool
    {
        if (class_exists(\craft\fields\Url::class) && $field instanceof \craft\fields\Url) {
            return true;
        }
        return class_exists(\craft\fields\Link::class) && $field instanceof \craft\fields\Link;
    }

    /**
     * Whether the value is a Craft 5 LinkData object (duck-typed so Craft 4 never
     * needs the class).
     */
    private function isLinkData(mixed $value): bool
    {
        return is_object($value)
            && method_exists($value, 'getValue')
            && method_exists($value, 'getElement');
    }

    private function isElementLink(mixed $value): bool
    {
        if (!$this->isLinkData($value)) {
            return false;
        }
        try {
            return $value->getElement() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Returns a reason why this particular value must not be edited inline, or null.
     */
    private function getEditBlocker(ElementInterface $element, string $handle, string $type, mixed $fieldValue, mixed $rawValue): ?string
    {
        if ($type === self::TYPE_TITLE && !$this->hasEditableTitle($element)) {
            return "element {$element->id} belongs to an entry type without a Title field (its title is generated); rendered read-only.";
        }

        if ($type === self::TYPE_CKEDITOR && ContentHelper::containsNestedEntries((string)$rawValue)) {
            return "field \"{$handle}\" on element {$element->id} contains nested entries, which the inline editor cannot represent; rendered read-only (edit it in the control panel).";
        }

        if ($type === self::TYPE_URL && $this->isElementLink($fieldValue)) {
            return "field \"{$handle}\" on element {$element->id} links to an element; rendered read-only (change the link in the control panel).";
        }

        return null;
    }

    /**
     * Entries whose entry type has no Title field get a generated (or null) title
     * on every save, so a manual title would be discarded immediately.
     */
    private function hasEditableTitle(ElementInterface $element): bool
    {
        if (!method_exists($element, 'getType')) {
            return true;
        }
        try {
            $entryType = $element->getType();
        } catch (Throwable) {
            return true;
        }
        if (!is_object($entryType) || !property_exists($entryType, 'hasTitleField')) {
            return true;
        }
        return (bool)$entryType->hasTitleField;
    }

    private function createTag(Tags $field, string $title, int $siteId): ?Tag
    {
        $groupId = $this->getGroupIdFromTagsField($field);
        if ($groupId === null) {
            Craft::warning("Inline Editor: could not resolve group ID for Tags field \"{$field->handle}\" — tag \"{$title}\" not created.", __METHOD__);
            return null;
        }

        // Tag titles are unique per group: reuse an existing tag instead of
        // failing validation (e.g. when two editors add the same tag).
        $existing = Tag::find()
            ->groupId($groupId)
            ->siteId($siteId)
            ->title(Db::escapeParam($title))
            ->one();
        if ($existing !== null) {
            return $existing;
        }

        $tag = new Tag([
            'groupId' => $groupId,
            'title' => $title,
            'siteId' => $siteId,
        ]);

        if (!Craft::$app->getElements()->saveElement($tag)) {
            Craft::warning("Inline Editor: could not create tag \"{$title}\": " . json_encode($tag->getErrors()), __METHOD__);
            return null;
        }

        return $tag;
    }

    private function getField(ElementInterface $element, string $handle): ?FieldInterface
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }
        return $layout->getFieldByHandle($handle);
    }

    /**
     * The editable representation of a field value:
     * - CKEditor: the raw stored HTML (reference tags intact)
     * - Link (Craft 5): the stored value (URL / mailto: / tel:)
     * - Tags: [{id, title}, …]
     * - Assets: [id, …]
     * - everything else: the string value
     */
    private function getRawValue(mixed $fieldValue, string $type): mixed
    {
        switch ($type) {
            case self::TYPE_TAGS:
                return is_object($fieldValue) && method_exists($fieldValue, 'all')
                    ? $this->tagsToArray($fieldValue->all())
                    : [];

            case self::TYPE_ASSETS:
                return is_object($fieldValue) && method_exists($fieldValue, 'ids')
                    ? array_map('intval', $fieldValue->ids())
                    : [];

            case self::TYPE_CKEDITOR:
                return ContentHelper::rawContent($fieldValue);

            case self::TYPE_URL:
                if ($this->isLinkData($fieldValue)) {
                    return (string)$fieldValue->getValue();
                }
                return $fieldValue === null ? '' : (string)$fieldValue;

            default:
                return $fieldValue === null ? '' : (string)$fieldValue;
        }
    }

    private function getDisplayValue(string $type, mixed $fieldValue, mixed $rawValue): string
    {
        if ($type === self::TYPE_CKEDITOR) {
            // Rendered output: reference tags parsed, nested entries rendered.
            return $fieldValue === null ? '' : (string)$fieldValue;
        }

        if ($type === self::TYPE_ASSETS) {
            // Display is always provided by the template via the innerHtml option.
            return '';
        }

        if ($type === self::TYPE_TAGS) {
            // $rawValue is already the tagsToArray result at this point.
            return implode('', array_map(
                static fn(array $t) => '<span class="inline-editor__tag">' . htmlspecialchars($t['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
                $rawValue
            ));
        }

        if ($type === self::TYPE_URL && $this->isLinkData($fieldValue)) {
            // The resolved URL (including any URL suffix), like `{{ entry.link }}` outputs.
            return htmlspecialchars((string)$fieldValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return htmlspecialchars((string)$rawValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isEditable(ElementInterface $element): bool
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return false;
        }
        return Plugin::getInstance()->canCurrentUserEdit();
    }

    private function looksMultiline(mixed $value): bool
    {
        return is_string($value) && str_contains($value, "\n");
    }

    /** @return array<array{id:int,title:string}> */
    private function tagsToArray(array $tags): array
    {
        return array_map(static fn(Tag $t) => ['id' => $t->id, 'title' => $t->title], $tags);
    }

    /**
     * Resolve the numeric group ID from a Tags field.
     *
     * Craft 4/5 store the group as source = "taggroup:{uid}", not as a direct
     * $groupId integer property, so we parse the source string and look up the
     * group by its UID.
     */
    private function getGroupIdFromTagsField(Tags $field): ?int
    {
        $source = $field->source ?? '';

        if (!str_starts_with($source, 'taggroup:')) {
            return null;
        }

        $uid = substr($source, strlen('taggroup:'));

        // Numeric ID (legacy Craft 3 format) — accept it directly.
        if (ctype_digit($uid)) {
            return (int)$uid;
        }

        $group = Craft::$app->getTags()->getTagGroupByUid($uid);
        return $group?->id;
    }

    /**
     * Resolve the folder ID to upload into for an Assets field.
     *
     * Delegates to Craft's own resolution (`Assets::resolveDynamicPathToFolderId()`),
     * which honours "Restrict assets to a single location", the default upload
     * location, object-template subpaths, and creates missing folders — on
     * Craft 4 and 5 alike.
     */
    public function resolveUploadFolder(AssetsField $field, ElementInterface $element): ?int
    {
        try {
            return $field->resolveDynamicPathToFolderId($element);
        } catch (Throwable $e) {
            Craft::warning(
                "Inline Editor: could not resolve the upload folder for Assets field \"{$field->handle}\": "
                . $e->getMessage() . ' Check the field’s “Upload Location” / “Asset Location” setting.',
                __METHOD__
            );
            return null;
        }
    }

    private function buildAttributes(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $name => $value) {
            if ($value === '' && $name !== 'data-inline-editor') {
                continue;
            }
            if ($value === true || ($value === '' && $name === 'data-inline-editor')) {
                $parts[] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                continue;
            }
            $parts[] = sprintf(
                '%s="%s"',
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }
        return implode(' ', $parts);
    }

    private function sanitize(mixed $value, string $type): string
    {
        $string = is_scalar($value) ? (string)$value : '';
        if ($type === self::TYPE_CKEDITOR) {
            return $string;
        }
        return str_replace(["\r\n", "\r"], "\n", $string);
    }
}
