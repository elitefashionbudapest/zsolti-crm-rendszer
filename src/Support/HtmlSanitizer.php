<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;

/**
 * Allowlist-elvű HTML-tisztító a tárolt, később nyersen renderelt tartalomhoz
 * (pl. tanácsadó-anyag a szerkesztőben). A script-futtatás vektorait kivágja:
 * veszélyes elemek, on* eseménykezelők, javascript:/vbscript: URL-ek.
 * A formázást (szöveg, listák, inline style) megtartja.
 */
final class HtmlSanitizer
{
    private const STRIP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form', 'noscript', 'template', 'svg', 'math'];
    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'background'];

    public function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__wrap">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        // Veszélyes elemek eltávolítása
        foreach (self::STRIP_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Attribútum-tisztítás
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on')) {
                    $el->removeAttribute($attr->name);
                    continue;
                }
                if (in_array($name, self::URL_ATTRS, true) && $this->isDangerousUrl($attr->value)) {
                    $el->removeAttribute($attr->name);
                }
            }
        }

        $wrap = null;
        foreach ($dom->childNodes as $n) {
            if ($n instanceof DOMElement && $n->getAttribute('id') === '__wrap') {
                $wrap = $n;
                break;
            }
        }
        if ($wrap === null) {
            return '';
        }

        $out = '';
        foreach ($wrap->childNodes as $child) {
            $out .= (string) $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function isDangerousUrl(string $value): bool
    {
        $v = strtolower(preg_replace('/[\s\x00-\x20]+/', '', $value) ?? '');

        return str_starts_with($v, 'javascript:') || str_starts_with($v, 'vbscript:') || str_starts_with($v, 'data:text/html');
    }
}
