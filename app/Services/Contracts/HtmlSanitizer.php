<?php

namespace App\Services\Contracts;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Keeps agreement templates to safe, printable HTML: a small set of
 * formatting tags, no scripts, styles, event handlers or links to elsewhere.
 * Admin-edited templates are shown to clients, so this runs on every save.
 */
final class HtmlSanitizer
{
    private const TAGS = ['h1', 'h2', 'h3', 'h4', 'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'span', 'section', 'blockquote', 'hr'];

    private const ATTRS = ['class', 'id', 'colspan', 'rowspan'];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();

        $root = $doc->getElementById('__root');
        if (! $root) {
            return '';
        }
        $this->walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta'], true)) {
                    $node->removeChild($child);

                    continue;
                }
                if (! in_array($tag, self::TAGS, true)) {
                    // Unknown tag: keep its text, drop the tag.
                    $this->walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    if (! in_array(strtolower($attr->name), self::ATTRS, true)) {
                        $child->removeAttribute($attr->name);
                    }
                }
                $this->walk($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
