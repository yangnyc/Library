<?php

namespace App\Modules\Imports\Sanitizing;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

/**
 * Sanitizes XHTML and SVG documents from a publication before they are stored
 * in the reading copy. The original uploaded file is never modified.
 *
 * Elements are allow-listed. Unknown elements are unwrapped (their text is
 * kept); active content is removed together with its children.
 */
class MarkupSanitizer
{
    private const HTML_ELEMENTS = [
        'html', 'head', 'title', 'body', 'meta', 'link', 'style',
        'section', 'article', 'nav', 'aside', 'header', 'footer', 'main', 'div', 'span', 'p', 'br', 'hr', 'wbr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hgroup', 'address', 'blockquote', 'pre', 'figure', 'figcaption',
        'ol', 'ul', 'li', 'dl', 'dt', 'dd', 'a', 'em', 'strong', 'small', 's', 'cite', 'q', 'dfn', 'abbr', 'time',
        'code', 'var', 'samp', 'kbd', 'sub', 'sup', 'i', 'b', 'u', 'mark', 'ruby', 'rt', 'rp', 'rb', 'rtc', 'bdi', 'bdo',
        'ins', 'del', 'img', 'picture', 'table', 'caption', 'colgroup', 'col', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th',
        'details', 'summary', 'big', 'center', 'font', 'tt', 'strike', 'acronym', 'data',
    ];

    private const SVG_ELEMENTS = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath',
        'image', 'defs', 'use', 'title', 'desc', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask',
        'pattern', 'symbol', 'marker', 'switch', 'metadata',
    ];

    private const MATHML_ELEMENTS = [
        'math', 'mrow', 'mi', 'mn', 'mo', 'ms', 'mtext', 'mspace', 'mfrac', 'msqrt', 'mroot', 'msub', 'msup', 'msubsup',
        'munder', 'mover', 'munderover', 'mtable', 'mtr', 'mtd', 'mfenced', 'mstyle', 'mpadded', 'mphantom', 'semantics',
        'annotation', 'menclose', 'mmultiscripts', 'mprescripts', 'none',
    ];

    /** Removed together with everything inside them. */
    private const DROP_WITH_CONTENT = [
        'script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'select', 'option', 'optgroup', 'textarea', 'datalist', 'output', 'keygen', 'audio', 'video', 'source', 'track',
        'canvas', 'template', 'slot', 'portal', 'base', 'dialog', 'foreignobject', 'animate', 'animatemotion',
        'animatetransform', 'set', 'handler', 'listener', 'map', 'area',
    ];

    private const URL_ATTRIBUTES = ['href', 'src', 'xlink:href', 'poster', 'data', 'background', 'cite', 'longdesc', 'action', 'formaction'];

    private const DROP_ATTRIBUTES = [
        'srcdoc', 'srcset', 'action', 'formaction', 'ping', 'http-equiv', 'manifest', 'integrity', 'crossorigin',
        'nonce', 'autofocus', 'contenteditable', 'tabindex', 'accesskey', 'target', 'download', 'referrerpolicy',
        'usemap', 'ismap', 'is', 'slot', 'part', 'popover', 'popovertarget',
    ];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly CssSanitizer $css = new CssSanitizer) {}

    /** @return list<string> */
    public function notes(): array
    {
        return array_values(array_unique($this->notes));
    }

    public function sanitizeXhtml(string $source): string
    {
        $document = $this->parse($source, allowHtmlFallback: true);
        $this->sanitizeNode($document->documentElement);
        $this->ensureHead($document);

        // The HTML-parser fallback produces elements without a namespace.
        if (! $document->documentElement->namespaceURI && ! $document->documentElement->hasAttribute('xmlns')) {
            $document->documentElement->setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
        }

        return "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!DOCTYPE html>\n".$document->saveXML($document->documentElement);
    }

    public function sanitizeSvg(string $source): string
    {
        $document = $this->parse($source, allowHtmlFallback: false);
        if (strtolower($document->documentElement->localName) !== 'svg') {
            throw new RuntimeException('Not an SVG document.');
        }
        $this->sanitizeNode($document->documentElement);

        return "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n".$document->saveXML($document->documentElement);
    }

    private function parse(string $source, bool $allowHtmlFallback): DOMDocument
    {
        $source = SafeXml::prepare($source);
        // Named HTML entities are undefined once the DTD is gone; make them numeric.
        $source = $this->numericEntities($source);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($source, SafeXml::FLAGS);
            if (! $loaded && $allowHtmlFallback) {
                $this->notes[] = 'A document was not well-formed XML and was repaired with the HTML parser.';
                $loaded = $document->loadHTML('<?xml encoding="utf-8"?>'.$source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || ! $document->documentElement) {
            throw new RuntimeException('The document could not be parsed.');
        }

        return $document;
    }

    private function sanitizeNode(DOMNode $node): void
    {
        // Snapshot: the live child list changes while nodes are removed.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $this->sanitizeElement($child);
            } elseif (in_array($child->nodeType, [XML_PI_NODE, XML_COMMENT_NODE, XML_ENTITY_REF_NODE, XML_DOCUMENT_TYPE_NODE], true)) {
                $node->removeChild($child);
            }
        }

        if ($node instanceof DOMElement && $node->parentNode instanceof DOMDocument) {
            $this->sanitizeAttributes($node, strtolower($node->localName));
        }
    }

    private function sanitizeElement(DOMElement $element): void
    {
        $name = strtolower($element->localName);

        if (in_array($name, self::DROP_WITH_CONTENT, true)) {
            $this->notes[] = "Removed <$name> content.";
            $element->parentNode->removeChild($element);

            return;
        }

        $allowed = in_array($name, self::HTML_ELEMENTS, true)
            || in_array($name, self::SVG_ELEMENTS, true)
            || in_array($name, self::MATHML_ELEMENTS, true);

        if (! $allowed) {
            $this->sanitizeNode($element);
            $this->unwrap($element);

            return;
        }

        if ($name === 'link' && ! $this->isLocalStylesheetLink($element)) {
            $element->parentNode->removeChild($element);

            return;
        }
        if ($name === 'meta' && $element->hasAttribute('http-equiv')) {
            $element->parentNode->removeChild($element);

            return;
        }
        if ($name === 'style') {
            $clean = $this->css->sanitize($element->textContent);
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($element->ownerDocument->createTextNode($clean));
            $this->sanitizeAttributes($element, $name);

            return;
        }

        $this->sanitizeAttributes($element, $name);
        $this->sanitizeNode($element);
    }

    private function sanitizeAttributes(DOMElement $element, string $elementName): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $local = strtolower($attribute->localName);
            $value = $attribute->value;

            if (str_starts_with($local, 'on') || in_array($local, self::DROP_ATTRIBUTES, true) || str_starts_with($name, 'xmlns:ev')) {
                $this->notes[] = "Removed attribute $name.";
                $element->removeAttributeNode($attribute);

                continue;
            }

            if ($local === 'style') {
                // setAttribute escapes the value; assigning DOMAttr::$value would not.
                $element->setAttribute('style', $this->css->sanitizeDeclarations($value));

                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) || in_array($local, ['href', 'src'], true)) {
                if (! $this->isAllowedUrl($value, $elementName, $local)) {
                    $this->notes[] = "Removed $name reference outside the publication.";
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        if ($elementName === 'a' && $element->hasAttribute('href') && preg_match('#^https?://#i', trim($element->getAttribute('href')))) {
            $element->setAttribute('rel', 'noopener noreferrer external');
        }
    }

    private function isAllowedUrl(string $value, string $elementName, string $attribute): bool
    {
        // Browsers ignore whitespace and control characters inside schemes.
        $url = preg_replace('/[\x00-\x20\x7F]+/', '', $value) ?? '';

        if ($url === '') {
            return true;
        }

        $hasScheme = (bool) preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url);
        $isRemote = $hasScheme || str_starts_with($url, '//') || str_contains($url, '\\');

        // Links may point outside the book; the reader asks before opening them.
        if ($elementName === 'a' && $attribute === 'href') {
            return ! $isRemote || (bool) preg_match('#^(https?:|mailto:)#i', $url) && ! str_contains($url, '\\');
        }

        if (in_array($elementName, ['img', 'image'], true) && preg_match('#^data:image/(png|jpeg|gif|webp);base64,#i', $url)) {
            return true;
        }

        // Everything a page loads by itself must come from inside the publication.
        return ! $isRemote && ! str_starts_with($url, '/');
    }

    private function isLocalStylesheetLink(DOMElement $link): bool
    {
        $rel = strtolower($link->getAttribute('rel'));

        return preg_match('/(^|\s)stylesheet(\s|$)/', $rel)
            && $this->isAllowedUrl($link->getAttribute('href'), 'link', 'href')
            && $link->getAttribute('href') !== '';
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private function ensureHead(DOMDocument $document): void
    {
        $root = $document->documentElement;
        if (strtolower($root->localName) !== 'html') {
            return;
        }
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->localName) === 'head') {
                return;
            }
        }
        $head = $document->createElementNS($root->namespaceURI ?: 'http://www.w3.org/1999/xhtml', 'head');
        $root->insertBefore($head, $root->firstChild);
    }

    private function numericEntities(string $source): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5, 'UTF-8') as $char => $entity) {
                if (! in_array($entity, ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], true)) {
                    $codes = array_map(fn (string $c) => '&#'.mb_ord($c, 'UTF-8').';', mb_str_split($char, 1, 'UTF-8'));
                    $map[$entity] = implode('', $codes);
                }
            }
        }

        return strtr($source, $map);
    }
}
