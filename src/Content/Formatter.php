<?php

declare(strict_types=1);

namespace HashOver\Content;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Turns comment text into safe HTML.
 *
 * Comments are stored exactly as written. When displayed, everything is
 * escaped except a few attribute-less formatting tags; the result is parsed
 * with the HTML5 parser (which balances tags), URLs become links, line
 * breaks become <br>, and a final allow-list pass removes anything else.
 */
final class Formatter
{
    /** Tags commenters may use */
    public const array ALLOWED_TAGS = ['b', 'i', 'u', 's', 'pre', 'code', 'ul', 'ol', 'li', 'blockquote'];

    /** Elements and attributes the formatter itself creates */
    private const array GENERATED = [
        'a' => ['href', 'rel', 'class'],
        'br' => [],
    ];

    /** "[img]URL[/img]" (group 1) or a bare URL */
    private const string LINK_PATTERN = '~\[img\](https?://[^\s<>"\'`\[\]]+)\[/img\]|\bhttps?://[^\s<>"\'`]+~i';

    /**
     * Clean up text as typed: normalize line breaks, drop control characters
     * and bidirectional overrides (which can disguise text), keep emoji joiners
     */
    public static function normalize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{Cc}\n\t]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    public function toHtml(string $text): string
    {
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $this->escape(self::normalize($text)) . '</body></html>',
            LIBXML_NOERROR,
        );

        $body = $document->body ?? throw new \LogicException('The HTML parser produced no body.');

        $this->transformText($document, $body);
        $this->enforceAllowList($body);

        return trim($body->innerHTML);
    }

    /**
     * Escape everything, then re-enable allowed tags outside of <code>,
     * whose content is always shown literally
     */
    private function escape(string $text): string
    {
        $parts = preg_split('~(<code>.*?(?:</code>|$))~is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = $parts === false ? [$text] : $parts;
        $html = '';

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $inner = (string) preg_replace('~^<code>|</code>$~i', '', $part);
                $html .= '<code>' . htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5) . '</code>';
                continue;
            }

            $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
            $html .= (string) preg_replace_callback(
                '~&lt;(/?)(' . implode('|', self::ALLOWED_TAGS) . ')&gt;~i',
                static fn(array $match): string => '<' . $match[1] . strtolower($match[2]) . '>',
                $escaped,
            );
        }

        return $html;
    }

    /** Add links and line breaks to text nodes */
    private function transformText(HTMLDocument $document, Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof Element) {
                $this->transformText($document, $child);
            } elseif ($child instanceof Text) {
                $preformatted = $this->isInside($child, ['pre', 'code']);
                $replacement = $this->formatText($document, $child->data, linkify: !$preformatted, lineBreaks: !$preformatted);

                if ($replacement !== null) {
                    $child->replaceWith(...$replacement);
                }
            }
        }
    }

    /**
     * @return list<Node|string>|null null when the text needs no changes
     */
    private function formatText(HTMLDocument $document, string $text, bool $linkify, bool $lineBreaks): ?array
    {
        $hasLinks = $linkify && preg_match('~\[img\]|https?://~i', $text) === 1;
        $hasBreaks = $lineBreaks && str_contains($text, "\n");

        if (!$hasLinks && !$hasBreaks) {
            return null;
        }

        $nodes = [];
        $offset = 0;

        if ($linkify && preg_match_all(self::LINK_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                [$whole, $position] = $match[0];
                array_push($nodes, ...$this->textWithBreaks($document, substr($text, $offset, $position - $offset), $lineBreaks));

                if (($match[1][0] ?? '') !== '') {
                    $nodes[] = $this->link($document, $match[1][0], 'hashover-image');
                } else {
                    [$url, $trailing] = $this->splitTrailingPunctuation($whole);
                    $nodes[] = $this->link($document, $url);

                    if ($trailing !== '') {
                        $nodes[] = $trailing;
                    }
                }

                $offset = $position + strlen($whole);
            }
        }

        array_push($nodes, ...$this->textWithBreaks($document, substr($text, $offset), $lineBreaks));

        return $nodes;
    }

    /**
     * @return list<Node|string>
     */
    private function textWithBreaks(HTMLDocument $document, string $text, bool $lineBreaks): array
    {
        if (!$lineBreaks) {
            return $text === '' ? [] : [$text];
        }

        $nodes = [];

        foreach (explode("\n", $text) as $index => $line) {
            if ($index > 0) {
                $nodes[] = $document->createElement('br');
            }

            if ($line !== '') {
                $nodes[] = $line;
            }
        }

        return $nodes;
    }

    private function link(HTMLDocument $document, string $url, string $class = ''): Element
    {
        $link = $document->createElement('a');
        $link->setAttribute('href', $url);
        $link->setAttribute('rel', 'nofollow ugc noopener noreferrer');

        if ($class !== '') {
            $link->setAttribute('class', $class);
        }

        $link->textContent = $url;

        return $link;
    }

    /**
     * Sentence punctuation directly after a URL isn't part of it
     *
     * @return array{string, string}
     */
    private function splitTrailingPunctuation(string $url): array
    {
        $trimmed = rtrim($url, '.,;:!?');

        // Keep a closing parenthesis only if the URL contains an opening one
        if (str_ends_with($trimmed, ')') && substr_count($trimmed, '(') < substr_count($trimmed, ')')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        return [$trimmed, substr($url, strlen($trimmed))];
    }

    /**
     * @param list<string> $tags
     */
    private function isInside(Node $node, array $tags): bool
    {
        for ($parent = $node->parentElement; $parent !== null; $parent = $parent->parentElement) {
            if (in_array(strtolower($parent->localName), $tags, true)) {
                return true;
            }
        }

        return false;
    }

    /** Defense in depth: unwrap unknown elements, remove unknown attributes */
    private function enforceAllowList(Element $root): void
    {
        $elements = array_filter(iterator_to_array($root->childNodes), static fn(Node $node): bool => $node instanceof Element);

        foreach ($elements as $element) {
            $this->enforceAllowList($element);
            $tag = strtolower($element->localName);

            if (in_array($tag, self::ALLOWED_TAGS, true)) {
                $allowedAttributes = [];
            } elseif (array_key_exists($tag, self::GENERATED)) {
                $allowedAttributes = self::GENERATED[$tag];
            } else {
                $element->replaceWith(...iterator_to_array($element->childNodes));
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (!in_array($attribute->name, $allowedAttributes, true)) {
                    $element->removeAttribute($attribute->name);
                }
            }

            if ($tag === 'a' && preg_match('~^https?://~i', $element->getAttribute('href') ?? '') !== 1) {
                $element->replaceWith(...iterator_to_array($element->childNodes));
            }
        }
    }
}
