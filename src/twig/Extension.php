<?php

namespace arifje\inlineeditor\twig;

use arifje\inlineeditor\Plugin;
use craft\base\ElementInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\Markup;

class Extension extends AbstractExtension
{
    public function getName(): string
    {
        return 'Inline Editor';
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('inlineEditable', [$this, 'inlineEditable'], ['is_safe' => ['html']]),
            new TwigFunction('inlineEditor', [$this, 'inlineEditable'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Twig function — render an inline-editable field wrapper for an element.
     */
    public function inlineEditable(ElementInterface $element, string $handle, array $options = []): Markup
    {
        $html = Plugin::getInstance()->getEditor()->render($element, $handle, $options);
        return new Markup($html, 'UTF-8');
    }
}
