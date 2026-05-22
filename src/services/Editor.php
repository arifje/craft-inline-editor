<?php

namespace arifje\inlineeditor\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Tag;
use craft\fields\PlainText;
use craft\fields\Tags;
use craft\fields\Url;
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

    /**
     * Render the wrapper HTML for an editable field.
     *
     * When the current user is not an admin (or the request is not a site
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
     */
    public function render(ElementInterface $element, string $handle, array $options = []): string
    {
        $type = $this->detectType($element, $handle);
        $rawValue = $this->getRawValue($element, $handle, $type);
        $displayValue = $this->getDisplayValue($type, $rawValue);

        if (!$this->isEditable($element)) {
            return $displayValue;
        }

        $defaultTag = in_array($type, [self::TYPE_CKEDITOR, self::TYPE_TAGS], true) ? 'div' : 'span';
        $tag = $options['tag'] ?? $defaultTag;
        $extraClass = $options['class'] ?? '';
        $extraAttributes = $options['attributes'] ?? [];
        $placeholder = $options['placeholder'] ?? '';
        $inputType = $options['inputType'] ?? ($type === self::TYPE_CKEDITOR ? 'ckeditor' : ($this->looksMultiline($rawValue) ? 'textarea' : 'input'));

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

        if ($placeholder !== '') {
            $attrs['data-placeholder'] = $placeholder;
        }

        if ($type === self::TYPE_TAGS) {
            $attrs['data-tags'] = json_encode($rawValue, JSON_UNESCAPED_UNICODE);
            $field = $this->getField($element, $handle);
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
        $inner = $displayValue !== '' ? $displayValue : ($placeholder !== '' ? '<span class="inline-editor__placeholder">' . htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' : '');

        return "<{$tag} {$attrString}>{$inner}</{$tag}>";
    }

    /**
     * Persist a plain-text / URL / CKEditor field value.
     */
    public function save(ElementInterface $element, string $handle, mixed $value): bool
    {
        $type = $this->detectType($element, $handle);
        $sanitized = $this->sanitize($value, $type);

        if ($type === self::TYPE_CKEDITOR) {
            $configName = \arifje\inlineeditor\Plugin::getInstance()->getSettings()->ckeditorPurifierConfig;
            if ($configName !== '') {
                $sanitized = $this->purifyHtml($sanitized, $configName);
            }
        }

        if ($handle === 'title') {
            $element->title = $sanitized;
        } else {
            $element->setFieldValue($handle, $sanitized);
        }

        return Craft::$app->getElements()->saveElement($element);
    }

    /**
     * Run HTML through a purifier config file from config/htmlpurifier/.
     */
    private function purifyHtml(string $html, string $configFile): string
    {
        $path = Craft::$app->getPath()->getConfigPath()
            . DIRECTORY_SEPARATOR . 'htmlpurifier'
            . DIRECTORY_SEPARATOR . $configFile . '.json';

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
     */
    public function detectType(ElementInterface $element, string $handle): string
    {
        if ($handle === 'title') {
            return self::TYPE_TITLE;
        }

        $field = $this->getField($element, $handle);

        if ($field instanceof Tags) {
            return self::TYPE_TAGS;
        }

        if ($field instanceof PlainText) {
            return self::TYPE_PLAINTEXT;
        }

        if ($field instanceof Url) {
            return self::TYPE_URL;
        }

        // CKEditor is an optional plugin — match by class name to avoid a hard dependency.
        $class = $field !== null ? get_class($field) : '';
        if ($class !== '' && str_contains(strtolower($class), 'ckeditor')) {
            return self::TYPE_CKEDITOR;
        }

        throw new InvalidArgumentException("Field \"{$handle}\" is not a supported inline-editable field type.");
    }

    private function createTag(Tags $field, string $title, int $siteId): ?Tag
    {
        $groupId = $this->getGroupIdFromTagsField($field);
        if ($groupId === null) {
            Craft::warning("Inline Editor: could not resolve group ID for Tags field \"{$field->handle}\" — tag \"{$title}\" not created.", __METHOD__);
            return null;
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

    private function getField(ElementInterface $element, string $handle): ?Field
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }
        return $layout->getFieldByHandle($handle);
    }

    private function getRawValue(ElementInterface $element, string $handle, string $type): mixed
    {
        if ($handle === 'title') {
            return $element->title;
        }

        $value = $element->getFieldValue($handle);

        if ($type === self::TYPE_TAGS) {
            return $this->tagsToArray($value->all());
        }

        return $value;
    }

    private function getDisplayValue(string $type, mixed $rawValue): string
    {
        if ($type === self::TYPE_CKEDITOR) {
            return (string)$rawValue;
        }

        if ($type === self::TYPE_TAGS) {
            // $rawValue is already the tagsToArray result at this point.
            return implode('', array_map(
                static fn(array $t) => '<span class="inline-editor__tag">' . htmlspecialchars($t['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
                $rawValue
            ));
        }

        return htmlspecialchars((string)$rawValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isEditable(ElementInterface $element): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest() || $request->getIsConsoleRequest()) {
            return false;
        }
        return \arifje\inlineeditor\Plugin::getInstance()->canCurrentUserEdit();
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
     * Craft 4/5 stores the group as source = "taggroup:{uid}", not as a direct
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
