<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

class TaskDescription
{
    /** @return array<string, mixed> */
    public static function attributes(array $input): array
    {
        if (isset($input['descriptionHtml'])) {
            $formatted = self::sanitize($input['descriptionHtml']);

            return ['description' => $formatted['text'], 'description_html' => $formatted['html'], 'description_html_hash' => hash('sha256', $formatted['text'])];
        }

        return array_key_exists('description', $input) ? ['description' => $input['description'], 'description_html' => null, 'description_html_hash' => null] : [];
    }

    /** @return list<array{type: string, content: array}> */
    public static function blocks(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $spans = function (DOMNode $node, array $marks = []) use (&$spans): array {
            if ($node->nodeType === XML_TEXT_NODE) {
                return [['text' => $node->textContent, ...$marks]];
            }
            if (! $node instanceof DOMElement) {
                return [];
            }
            if ($node->tagName === 'br') {
                return [['text' => "\n", ...$marks]];
            }
            if ($node->tagName === 'strong') {
                $marks['bold'] = true;
            }
            if ($node->tagName === 'em') {
                $marks['italic'] = true;
            }
            if ($node->tagName === 'a' && WorkspaceAgreements::validLink($node->getAttribute('href'))) {
                $marks['href'] = $node->getAttribute('href');
            }
            $result = [];
            foreach ($node->childNodes as $child) {
                $result = [...$result, ...$spans($child, $marks)];
            }

            return $result;
        };
        $blocks = [];
        foreach ($document->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && in_array($node->tagName, ['ul', 'ol'], true)) {
                foreach ($node->childNodes as $item) {
                    if ($item instanceof DOMElement && $item->tagName === 'li') {
                        $blocks[] = ['type' => $node->tagName === 'ul' ? 'bullet' : 'number', 'content' => $spans($item)];
                    }
                }
            } else {
                $blocks[] = ['type' => 'paragraph', 'content' => $spans($node)];
            }
        }

        return $blocks;
    }

    /** @return array{html: string, text: string} */
    public static function sanitize(string $input): array
    {
        if (strlen($input) > 60000) {
            throw ValidationException::withMessages(['descriptionHtml' => 'Formatted description is too large.']);
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$input.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $nodes = 0;
        $clean = function (DOMNode $node, int $depth = 0) use (&$clean, &$nodes): string {
            if (++$nodes > 5000 || $depth > 40) {
                throw ValidationException::withMessages(['descriptionHtml' => 'Description is too complex.']);
            }
            if ($node->nodeType === XML_TEXT_NODE) {
                return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if (! $node instanceof DOMElement) {
                return '';
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'img'], true)) {
                return '';
            }
            $children = '';
            foreach ($node->childNodes as $child) {
                $children .= $clean($child, $depth + 1);
            }
            $tag = match ($tag) {
                'b' => 'strong', 'i' => 'em', 'div' => 'p', default => $tag
            };
            if ($tag === 'br') {
                return '<br>';
            }
            if ($tag === 'a') {
                $url = $node->getAttribute('href');
                if (! WorkspaceAgreements::validLink($url)) {
                    return $children;
                }

                return '<a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" target="_blank" rel="noopener noreferrer">'.$children.'</a>';
            }

            return in_array($tag, ['p', 'strong', 'em', 'ul', 'ol', 'li'], true) ? '<'.$tag.'>'.$children.'</'.$tag.'>' : $children;
        };
        $html = '';
        foreach ($document->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $node) {
            $html .= $clean($node);
        }
        $lines = [];
        $number = 0;
        foreach (self::blocks($html) as $block) {
            $number = $block['type'] === 'number' ? $number + 1 : 0;
            $text = implode('', array_map(fn ($span) => $span['text'].(isset($span['href']) && $span['href'] !== $span['text'] ? ' ('.$span['href'].')' : ''), $block['content']));
            $lines[] = ($block['type'] === 'bullet' ? '• ' : ($number ? $number.'. ' : '')).$text;
        }
        $text = trim(implode("\n", $lines));
        if (mb_strlen($text) > 10000 || trim($text) === '') {
            throw ValidationException::withMessages(['descriptionHtml' => 'Description must contain 1 to 10000 text characters.']);
        }

        return ['html' => $html, 'text' => $text];
    }
}
