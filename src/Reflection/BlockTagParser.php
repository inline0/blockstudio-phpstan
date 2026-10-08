<?php

declare(strict_types=1);

namespace Blockstudio\PHPStan\Reflection;

/**
 * Extracts block tags from template and page source content.
 *
 * Ported from Blockstudio\Block_Tags for use at PHPStan analysis time. Always
 * recognizes <bs:namespace-slug> and <block name="...">; prefix tags such as
 * <acme-slug>, alias tags, and element-mapped HTML tags are recognized when the
 * caller names them. Openings end at the first ">" outside quotes and PHP
 * spans, so attribute values may contain ">" and <?php ... ?> output.
 */
final class BlockTagParser
{
    /**
     * @param list<string> $prefixes Tag prefixes from blockTags.prefixes, such as "acme".
     * @param list<string> $aliases  Alias tag names, such as "theme-button".
     * @param list<string> $elements HTML element names mapped to blocks, such as "section".
     * @return list<array{
     *   name: string,
     *   tag: string,
     *   kind: 'bs'|'block'|'prefix'|'alias'|'element',
     *   candidates: list<string>,
     *   attributes: array<string, string>,
     *   line: int
     * }>
     */
    public function extractTags(
        string $content,
        array $prefixes = [],
        array $aliases = [],
        array $elements = []
    ): array {
        if ($content === '' || !str_contains($content, '<')) {
            return [];
        }

        $alternatives = [
            'bs:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?',
            'block',
        ];
        foreach ($aliases as $alias) {
            $alternatives[] = preg_quote(strtolower($alias), '/');
        }
        foreach ($prefixes as $prefix) {
            if (preg_match('/^[a-z][a-z0-9]*$/', $prefix) === 1) {
                $alternatives[] = preg_quote($prefix, '/') . '-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';
            }
        }
        foreach ($elements as $element) {
            if (preg_match('/^[a-z][a-z0-9]*$/', strtolower($element)) === 1) {
                $alternatives[] = preg_quote(strtolower($element), '/');
            }
        }

        $pattern = '/<(' . implode('|', $alternatives) . ')(?=[\s\/>])/i';
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $aliasNames = array_fill_keys(array_map('strtolower', $aliases), true);
        $elementNames = array_fill_keys(array_map('strtolower', $elements), true);
        $tags = [];

        foreach ($matches[1] as $index => [$tagName, $offset]) {
            $start = $matches[0][$index][1];
            $tagName = strtolower($tagName);
            $open = $this->readOpening($content, $offset + strlen($tagName));
            if ($open === null) {
                continue;
            }

            $attributes = $this->parseAttributes($open);
            $tag = $this->classify($tagName, $attributes, $aliasNames, $elementNames);
            if ($tag === null) {
                continue;
            }

            $tag['line'] = $this->lineAt($content, $start);
            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, true> $aliasNames
     * @param array<string, true> $elementNames
     * @return array{
     *   name: string,
     *   tag: string,
     *   kind: 'bs'|'block'|'prefix'|'alias'|'element',
     *   candidates: list<string>,
     *   attributes: array<string, string>
     * }|null
     */
    private function classify(
        string $tagName,
        array $attributes,
        array $aliasNames,
        array $elementNames
    ): ?array {
        if (str_starts_with($tagName, 'bs:')) {
            $candidates = $this->bsCandidates(substr($tagName, 3));
            if ($candidates === []) {
                return null;
            }

            return [
                'name' => $candidates[0],
                'tag' => $tagName,
                'kind' => 'bs',
                'candidates' => $candidates,
                'attributes' => $attributes,
            ];
        }

        if ($tagName === 'block') {
            $blockName = $attributes['name'] ?? '';
            unset($attributes['name']);
            if ($blockName === '') {
                return null;
            }

            return [
                'name' => $blockName,
                'tag' => $tagName,
                'kind' => 'block',
                'candidates' => [$blockName],
                'attributes' => $attributes,
            ];
        }

        $kind = isset($aliasNames[$tagName])
            ? 'alias'
            : (isset($elementNames[$tagName]) ? 'element' : 'prefix');

        return [
            'name' => '',
            'tag' => $tagName,
            'kind' => $kind,
            'candidates' => [],
            'attributes' => $attributes,
        ];
    }

    /**
     * Block names a <bs:> tag can stand for: each hyphen in turn becomes the
     * namespace separator, as Block_Tags::resolve_bs_tag_name() tries them.
     *
     * @return list<string>
     */
    private function bsCandidates(string $tagName): array
    {
        $candidates = [];
        $position = strpos($tagName, '-');

        while ($position !== false) {
            $candidates[] = substr_replace($tagName, '/', $position, 1);
            $position = strpos($tagName, '-', $position + 1);
        }

        return $candidates;
    }

    /**
     * Read an opening tag's attribute text up to its closing angle.
     *
     * Quoted values and PHP spans are skipped; a PHP span outside quotes
     * becomes a space, because its output is not known statically.
     */
    private function readOpening(string $content, int $from): ?string
    {
        $length = strlen($content);
        $quote = null;
        $source = '';

        for ($i = $from; $i < $length; $i++) {
            if ($content[$i] === '<' && ($content[$i + 1] ?? '') === '?') {
                $end = strpos($content, '?>', $i + 2);
                if ($end === false) {
                    return null;
                }
                $source .= $quote === null ? ' ' : '';
                $i = $end + 1;
                continue;
            }

            $char = $content[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return rtrim(rtrim($source), '/');
            }

            $source .= $char;
        }

        return null;
    }

    /**
     * Parse attributes the way Block_Tags::parse_attributes() reads them.
     *
     * @return array<string, string>
     */
    private function parseAttributes(string $attrString): array
    {
        $attrString = trim($attrString);
        if ($attrString === '') {
            return [];
        }

        $attrs = [];
        $pattern = '/([a-zA-Z_][\w-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+)))?/';

        if (preg_match_all($pattern, $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                if (isset($match[2]) && $match[2] !== '') {
                    $attrs[$key] = $match[2];
                } elseif (isset($match[3]) && $match[3] !== '') {
                    $attrs[$key] = $match[3];
                } elseif (isset($match[4])) {
                    $attrs[$key] = $match[4];
                } else {
                    $attrs[$key] = '';
                }
            }
        }

        return $attrs;
    }

    private function lineAt(string $content, int $pos): int
    {
        return substr_count($content, "\n", 0, $pos) + 1;
    }
}
