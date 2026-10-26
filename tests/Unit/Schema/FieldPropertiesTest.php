<?php

declare(strict_types=1);

namespace Blockstudio\PHPStan\Tests\Unit\Schema;

use Blockstudio\PHPStan\Schema\FieldProperties;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the property lists of FieldProperties equal to the field branches of
 * the Blockstudio schemas, which only exist in the monorepo.
 */
final class FieldPropertiesTest extends TestCase
{
    public function test_properties_match_the_block_schema(): void
    {
        $this->assertMatchesSchema('block.json');
    }

    public function test_unknown_keys_and_suggestions(): void
    {
        $field = ['id' => 'title', 'type' => 'text', 'translat' => false, 'default' => 'Hi'];

        $this->assertSame(['translat'], FieldProperties::unknownKeys($field, 'text'));
        $this->assertSame('translate', FieldProperties::suggest('translat', 'text'));
        $this->assertNull(FieldProperties::suggest('minimized', 'text'));
        $this->assertSame([], FieldProperties::unknownKeys(['foo' => 1], 'custom/hero'));
        $this->assertSame([], FieldProperties::unknownKeys(['foo' => 1], 'acme/rating'));
    }

    private function assertMatchesSchema(string $file): void
    {
        $path = dirname(__DIR__, 5) . '/schemas/' . $file;
        if (!is_file($path)) {
            $this->markTestSkipped('Blockstudio schemas are not available.');
        }

        $schema = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $branchSets = $this->fieldBranchSets($schema);
        $this->assertNotSame([], $branchSets, 'No field branches found in ' . $file);

        $known = FieldProperties::byType();
        $seen = [];
        foreach ($branchSets as $branches) {
            foreach ($branches as $branch) {
                $type = $branch['properties']['type']['const'] ?? null;
                if (!is_string($type)) {
                    continue;
                }

                $this->assertArrayHasKey($type, $known, $file);
                $this->assertSame(
                    $known[$type],
                    array_values(array_diff(array_keys($branch['properties']), ['type'])),
                    sprintf('%s: properties of "%s"', $file, $type)
                );
                $seen[$type] = true;
            }
        }

        $types = array_keys($known);
        $seenTypes = array_keys($seen);
        sort($types);
        sort($seenTypes);
        $this->assertSame($types, $seenTypes, $file);
    }

    /**
     * Every anyOf list of field branches, with the common properties checked
     * against the object that owns it. Nested lists can omit types, a group
     * inside a group for example.
     *
     * @param array<string, mixed> $node
     * @return list<list<array<string, mixed>>>
     */
    private function fieldBranchSets(array $node): array
    {
        $sets = [];

        if (isset($node['anyOf']) && is_array($node['anyOf']) && $this->hasFieldBranches($node['anyOf'])) {
            $this->assertSame(
                FieldProperties::common(),
                array_values(array_diff(array_keys($node['properties'] ?? []), ['type']))
            );
            $sets[] = $node['anyOf'];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $sets = array_merge($sets, $this->fieldBranchSets($child));
            }
        }

        return $sets;
    }

    /**
     * @param array<array-key, mixed> $branches
     */
    private function hasFieldBranches(array $branches): bool
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && ($branch['properties']['type']['const'] ?? null) === 'text') {
                return true;
            }
        }

        return false;
    }
}
