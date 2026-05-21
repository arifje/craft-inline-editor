<?php

namespace arifje\inlineeditor\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\fields\PlainText;
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
     *   - tag:         HTML tag to wrap with (default: span / div for ckeditor)
     *   - class:       Extra CSS class names
     *   - attributes:  Extra attributes (key => value)
     *   - inputType:   Override input type for plaintext (input|textarea)
     *   - placeholder: Empty-state placeholder text
     */
    public function render(ElementInterface $element, string $handle, array $options = []): string
    {
        $type = $this->detectType($element, $handle);
        $rawValue = $this->getRawValue($element, $handle);
        $displayValue = $this->getDisplayValue($element, $handle, $type, $rawValue);

        if (!$this->isEditable($element)) {
            return $displayValue;
        }

        $defaultTag = $type === self::TYPE_CKEDITOR ? 'div' : 'span';
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

        foreach ($extraAttributes as $name => $value) {
            $attrs[$name] = $value;
        }

        $attrString = $this->buildAttributes($attrs);
        $inner = $displayValue !== '' ? $displayValue : ($placeholder !== '' ? '<span class="inline-editor__placeholder">' . htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' : '');

        return "<{$tag} {$attrString}>{$inner}</{$tag}>";
    }

    /**
     * Persist a new value for an element field.
     *
     * @throws InvalidArgumentException If the field is not editable inline.
     */
    public function save(ElementInterface $element, string $handle, mixed $value): bool
    {
        $type = $this->detectType($element, $handle);

        $sanitized = $this->sanitize($value, $type);

        if ($handle === 'title') {
            $element->title = $sanitized;
        } else {
            $element->setFieldValue($handle, $sanitized);
        }

        return Craft::$app->getElements()->saveElement($element);
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

        if ($field instanceof PlainText) {
            return self::TYPE_PLAINTEXT;
        }

        if ($field instanceof Url) {
            return self::TYPE_URL;
        }

        // CKEditor is an optional plugin — match by class name to avoid a hard dependency.
        $class = $field !== null ? get_class($field) : '';
        if ($class !== '' && (str_contains($class, 'ckeditor') || str_contains(strtolower($class), 'ckeditor'))) {
            return self::TYPE_CKEDITOR;
        }

        throw new InvalidArgumentException("Field \"{$handle}\" is not a supported inline-editable field type.");
    }

    private function getField(ElementInterface $element, string $handle): ?Field
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }
        return $layout->getFieldByHandle($handle);
    }

    private function getRawValue(ElementInterface $element, string $handle): mixed
    {
        if ($handle === 'title') {
            return $element->title;
        }
        return $element->getFieldValue($handle);
    }

    private function getDisplayValue(ElementInterface $element, string $handle, string $type, mixed $rawValue): string
    {
        if ($type === self::TYPE_CKEDITOR) {
            // CKEditor stores HTML; the field value is typically an object cast to string.
            return (string)$rawValue;
        }

        $string = (string)$rawValue;
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isEditable(ElementInterface $element): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsSiteRequest() || $request->getIsConsoleRequest()) {
            return false;
        }
        return Craft::$app->getUser()->getIsAdmin();
    }

    private function looksMultiline(mixed $value): bool
    {
        return is_string($value) && str_contains($value, "\n");
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
