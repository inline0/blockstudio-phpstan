<?php

declare(strict_types=1);

namespace Blockstudio\PHPStan\Schema;

/**
 * Field properties Blockstudio accepts, per built-in field type.
 *
 * Mirrors the field branches of schemas/block.json in the Blockstudio
 * repository. FieldPropertiesTest fails when the two drift apart.
 */
final class FieldProperties
{
    /** @var list<string> */
    private const COMMON = [
        'id', 'key', 'label', 'description', 'help', 'hidden', 'translate', 'storage', 'switch',
        'idStructure', 'overrides', 'conditions',
    ];

    /** @var array<string, list<string>> */
    private const BY_TYPE = [
        'attributes' => [
            'default', 'fallback', 'link', 'media',
        ],
        'block' => [
            'block', 'returnFormat',
        ],
        'checkbox' => [
            'default', 'fallback', 'options', 'returnFormat', 'populate', 'toggle',
        ],
        'classes' => [
            'default', 'fallback', 'tailwind',
        ],
        'code' => [
            'default', 'fallback', 'autoCompletion', 'foldGutter', 'height', 'language',
            'lineNumbers', 'maxHeight', 'minHeight', 'popout', 'asset',
        ],
        'color' => [
            'default', 'fallback', 'options', 'populate', 'clearable', 'disableCustomColors',
        ],
        'date' => [
            'default', 'fallback', 'startOfWeek',
        ],
        'datetime' => [
            'default', 'fallback', 'is12Hour',
        ],
        'files' => [
            'default', 'fallback', 'addToGallery', 'allowedTypes', 'gallery', 'max', 'min',
            'multiple', 'size', 'textMediaButton', 'title', 'returnFormat', 'returnSize',
        ],
        'gradient' => [
            'default', 'fallback', 'options', 'populate', 'clearable', 'disableCustomGradients',
        ],
        'group' => [
            'title', 'opened', 'initialOpen', 'scrollAfterOpen', 'class', 'style', 'attributes',
        ],
        'html-tag' => [
            'default', 'fallback', 'tags', 'exclude',
        ],
        'icon' => [
            'default', 'fallback', 'sets', 'subSets', 'returnFormat',
        ],
        'link' => [
            'default', 'fallback', 'hasRichPreviews', 'noDirectEntry', 'noURLSuggestion',
            'opensInNewTab', 'showSuggestions', 'textButton', 'withCreateSuggestion',
        ],
        'message' => [
            'default', 'fallback', 'value',
        ],
        'number' => [
            'default', 'fallback', 'dragDirection', 'dragThreshold', 'hideHTMLArrows',
            'isShiftStepEnabled', 'max', 'min', 'required', 'shiftStep', 'step',
        ],
        'radio' => [
            'default', 'fallback', 'options', 'returnFormat', 'populate', 'display', 'allowNull',
        ],
        'range' => [
            'default', 'fallback', 'allowReset', 'initialPosition', 'isShiftStepEnabled', 'marks',
            'max', 'min', 'railColor', 'resetFallbackValue', 'separatorType', 'shiftStep',
            'showTooltip', 'step', 'trackColor', 'withInputField',
        ],
        'select' => [
            'default', 'fallback', 'multiple', 'options', 'returnFormat', 'populate', 'stylisedUi',
            'allowNull', 'allowReset',
        ],
        'tabs' => [
            'tabs',
        ],
        'text' => [
            'default', 'fallback', 'max', 'min',
        ],
        'textarea' => [
            'default', 'fallback', 'max', 'min', 'rows',
        ],
        'toggle' => [
            'default', 'fallback',
        ],
        'repeater' => [
            'default', 'min', 'max', 'minimized', 'textButton', 'textMinimized', 'textRemove',
            'attributes',
        ],
        'richtext' => [
            'default', 'fallback',
        ],
        'unit' => [
            'default', 'fallback', 'disableUnits', 'isPressEnterToChange',
            'isResetValueOnUnitChange', 'isUnitSelectTabbable', 'units',
        ],
        'wysiwyg' => [
            'default', 'fallback', 'toolbar', 'mode',
        ],
    ];

    public static function isBuiltInType(string $type): bool
    {
        return isset(self::BY_TYPE[$type]);
    }

    /**
     * Properties of a field that its type does not accept.
     *
     * @param array<array-key, mixed> $field
     * @return list<string>
     */
    public static function unknownKeys(array $field, string $type): array
    {
        if (!self::isBuiltInType($type)) {
            return [];
        }

        $known = self::known($type);
        $unknown = [];
        foreach (array_keys($field) as $key) {
            if (!in_array($key, $known, true)) {
                $unknown[] = (string) $key;
            }
        }

        return $unknown;
    }

    /**
     * The closest property the type accepts, for a "Did you mean" hint.
     */
    public static function suggest(string $key, string $type): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $maximumDistance = min(3, max(1, intdiv(strlen($key), 3)));
        foreach (self::known($type) as $candidate) {
            $distance = levenshtein(strtolower($key), strtolower($candidate));
            if ($distance < $bestDistance && $distance <= $maximumDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @return list<string> */
    public static function common(): array
    {
        return self::COMMON;
    }

    /** @return array<string, list<string>> */
    public static function byType(): array
    {
        return self::BY_TYPE;
    }

    /** @return list<string> */
    private static function known(string $type): array
    {
        return array_merge(['type'], self::COMMON, self::BY_TYPE[$type] ?? []);
    }
}
