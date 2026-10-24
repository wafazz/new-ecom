<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Allowlist sanitiser for product descriptions written in the rich-text editor. Anything not on the
 * list is unwrapped (its text stays) or, for script-like tags, dropped with its content. Output is
 * rebuilt from the parsed tree, so no attribute from the input survives except a checked link href.
 * Plain text from before the editor existed (no tags) becomes paragraphs.
 */
final class RichText
{
    public const MAX_HTML = 30000;

    private const MAP = [
        'p' => 'p', 'br' => 'br', 'strong' => 'strong', 'b' => 'strong', 'em' => 'em', 'i' => 'em', 'u' => 'u',
        's' => 's', 'strike' => 's', 'del' => 's', 'h1' => 'h2', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3',
        'blockquote' => 'blockquote', 'ul' => 'ul', 'ol' => 'ol', 'li' => 'li', 'a' => 'a',
    ];
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'textarea', 'select', 'option', 'title', 'head', 'link', 'meta', 'form', 'input', 'button', 'audio', 'video', 'canvas'];
    private const BLOCKS = ['p', 'h2', 'h3', 'blockquote', 'ul', 'ol', 'li'];

    /** Safe HTML for storing and for showing. Empty string when there is no visible text. */
    public static function clean(?string $input): string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return '';
        }
        if (!preg_match('/<\s*[a-z!\/]/i', $input)) { // legacy plain text
            $input = implode('', array_map(
                fn($para) => '<p>' . preg_replace('/\R/', '<br>', htmlspecialchars(trim($para), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
                preg_split('/\R{2,}/', $input) ?: []
            ));
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $input . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        $out = $body ? self::children($body) : '';
        $out = preg_replace('#(<p>(\s|<br>)*</p>)+$#', '', trim($out)) ?? '';
        $out = preg_replace('#^(<p>(\s|<br>)*</p>)+#', '', $out) ?? '';
        return trim(html_entity_decode(strip_tags($out), ENT_QUOTES, 'UTF-8')) === '' ? '' : $out;
    }

    /** Visible text length, for limits. */
    public static function textLength(string $html): int
    {
        return mb_strlen(trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    private static function children(\DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= self::node($child);
        }
        return $html;
    }

    private static function node(\DOMNode $n): string
    {
        if ($n instanceof \DOMText) {
            return htmlspecialchars($n->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (!$n instanceof \DOMElement) {
            return ''; // comments, processing instructions, CDATA
        }
        $tag = strtolower($n->tagName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        $to = self::MAP[$tag] ?? null;
        if ($to === null) {
            return self::children($n); // unknown tag: keep the text, lose the tag
        }
        if ($to === 'br') {
            return '<br>';
        }
        if ($to === 'ul' || $to === 'ol') {
            // Quill 2 writes bullet lists as <ol><li data-list="bullet">; fix the list type from the items.
            $first = null;
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMElement && strtolower($c->tagName) === 'li') {
                    $first = $c;
                    break;
                }
            }
            if ($first && $first->hasAttribute('data-list')) {
                $to = $first->getAttribute('data-list') === 'ordered' ? 'ol' : 'ul';
            }
        }
        $inner = self::children($n);
        if ($to === 'a') {
            $href = self::safeHref($n->getAttribute('href'));
            return $href === null ? $inner : '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer nofollow">' . $inner . '</a>';
        }
        return "<{$to}>{$inner}</{$to}>";
    }

    /** Only http(s), mailto and tel links; whitespace/control characters are stripped before judging the scheme. */
    private static function safeHref(string $href): ?string
    {
        $href = preg_replace('/[\x00-\x20\x7f]+/u', '', $href) ?? '';
        if ($href === '' || strlen($href) > 2000 || !preg_match('#^(https?://[^/\s]|mailto:[^\s]|tel:[+0-9\-()]+$)#i', $href)) {
            return null;
        }
        return $href;
    }
}
