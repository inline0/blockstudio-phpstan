<?php

declare(strict_types=1);

namespace Blockstudio\PHPStan\Tests\Rules;

use Blockstudio\PHPStan\Reflection\FieldTypeRegistry;
use Blockstudio\PHPStan\Rules\BlockTagRule;
use Blockstudio\PHPStan\Schema\BlockJsonReader;
use Blockstudio\PHPStan\Schema\BlockstudioJsonReader;
use Blockstudio\PHPStan\Schema\CustomFieldResolver;
use Blockstudio\PHPStan\Schema\ProjectScanner;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use ReflectionClass;

/**
 * Block tags as a theme writes them: prefix and alias tags, page sources with
 * element-mapped HTML, layouts, bundled UI blocks, and project PHP.
 *
 * @extends RuleTestCase<BlockTagRule>
 */
final class BlockTagRuleProjectTest extends RuleTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $reflection = new ReflectionClass(BlockTagRule::class);
        foreach (['scannedFiles', 'unanalysedScanned'] as $property) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue(null, []);
            }
        }

        $this->root = sys_get_temp_dir() . '/blockstudio-block-tags-' . bin2hex(random_bytes(6));
        $this->writeProject(true, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    protected function getRule(): Rule
    {
        $scanner = new ProjectScanner($this->root);

        return new BlockTagRule(
            $scanner,
            new BlockJsonReader(new FieldTypeRegistry(), new CustomFieldResolver($scanner)),
            new BlockstudioJsonReader(),
            $this->root
        );
    }

    public function test_prefix_and_alias_tags_in_block_templates_are_checked(): void
    {
        $this->assertSame(
            [
                '4:Block tag "elements/badge" has unknown attribute "bogus" in index.php',
                '5:Prefix tag <acme-nothing> resolves to no registered block in index.php',
                '7:Block tag "bsui/button" has unknown attribute "wrong" in index.php',
                '8:Block tag "elements/badge" passes "class", but the block declares no class field, so Blockstudio drops it in index.php',
            ],
            $this->errors('blocks/elements/card/index.php')['blocks/elements/card/index.php'] ?? []
        );
    }

    public function test_page_sources_check_element_mapped_html_and_the_stored_contract(): void
    {
        $this->assertSame(
            [
                '1:Element <section> mapped to "layout/section" has unknown attribute "foo" in index.php',
                '4:Element <div> mapped to "layout/row" has unknown attribute "gap" in index.php',
                '7:Block tag "elements/badge" has unknown attribute "html-onclick" in index.php',
                '9:Element <section> mapped to "layout/section" passes "class", but the block declares no class field, so Blockstudio drops it in index.php',
            ],
            $this->errors('pages/home/index.php')['pages/home/index.php'] ?? []
        );
    }

    public function test_layouts_render_tags_without_element_mapping_and_html_pages_are_scanned(): void
    {
        $errors = $this->errors('pages/layout.php');

        $this->assertArrayNotHasKey('pages/layout.php', $errors);
        $this->assertSame(
            ['1:Element <section> mapped to "layout/section" has unknown attribute "bar" in index.html'],
            $errors['pages/docs/index.html'] ?? []
        );
    }

    public function test_project_php_is_scanned_when_enabled(): void
    {
        $this->assertSame(
            ['3:Block tag "elements/badge" has unknown attribute "nope" in module.php'],
            $this->errors('inc/module.php')['inc/module.php'] ?? []
        );
    }

    public function test_project_php_is_not_scanned_by_default(): void
    {
        $this->writeProject(false, true);

        $this->assertArrayNotHasKey('inc/module.php', $this->errors('inc/module.php'));
    }

    public function test_unindexed_bundled_ui_blocks_are_neither_resolved_nor_reported(): void
    {
        $this->removeDirectory($this->root . '/vendor');

        $this->assertSame(
            [
                '4:Block tag "elements/badge" has unknown attribute "bogus" in index.php',
                '8:Block tag "elements/badge" passes "class", but the block declares no class field, so Blockstudio drops it in index.php',
            ],
            $this->errors('blocks/elements/card/index.php')['blocks/elements/card/index.php'] ?? []
        );
    }

    public function test_class_errors_have_their_own_identifier(): void
    {
        $identifiers = array_map(
            static fn($error): ?string => $error->getIdentifier(),
            $this->gatherAnalyserErrors([$this->root . '/blocks/elements/card/index.php'])
        );

        $this->assertContains('blockstudio.blockTag.class', $identifiers);
        $this->assertContains('blockstudio.blockTag.attribute', $identifiers);
        $this->assertContains('blockstudio.blockTag.unknown', $identifiers);
    }

    /**
     * @return array<string, list<string>> Sorted "line:message" errors keyed by project-relative file.
     */
    private function errors(string $relative): array
    {
        $grouped = [];
        foreach ($this->gatherAnalyserErrors([$this->root . '/' . $relative]) as $error) {
            $file = substr(str_replace('\\', '/', $error->getFilePath()), strlen($this->root) + 1);
            $grouped[$file][] = $error->getLine() . ':' . $error->getMessage();
        }
        foreach ($grouped as &$messages) {
            sort($messages);
        }

        return $grouped;
    }

    private function writeProject(bool $scanPhp, bool $ui): void
    {
        $settings = [
            'blockTags' => [
                'enabled' => true,
                'prefixes' => ['acme' => ['elements', 'layout', 'bsui']],
            ],
            'ui' => ['enabled' => $ui],
            'phpstan' => [
                'blockTags' => [
                    'elements' => [
                        'section' => 'layout/section',
                        'h2' => 'elements/heading',
                        'div' => [
                            ['attribute' => 'width', 'block' => 'layout/row'],
                            'elements/div',
                        ],
                    ],
                    'aliases' => ['theme-badge' => 'elements/badge'],
                    'scanPhp' => $scanPhp,
                ],
            ],
        ];

        $this->write('blockstudio.json', (string) json_encode($settings));
        $this->block('elements/badge', [['id' => 'text', 'type' => 'text']]);
        $this->block('elements/heading', [['id' => 'content', 'type' => 'text'], ['id' => 'class', 'type' => 'text']]);
        $this->block('elements/div', [['id' => 'class', 'type' => 'text']]);
        $this->block('layout/row', [['id' => 'width', 'type' => 'text']]);
        $this->block('layout/section', [['id' => 'variant', 'type' => 'text']]);
        $this->block('theme-components/panel', [['id' => 'title', 'type' => 'text']]);
        $this->block('elements/card', [
            ['id' => 'title', 'type' => 'text'],
            ['id' => 'cta', 'type' => 'block', 'block' => 'bsui/button', 'idStructure' => 'cta_{id}'],
        ]);
        $this->write(
            'vendor/blockstudio/blockstudio/includes/ui/blocks/button/root/block.json',
            (string) json_encode([
                'name' => 'bsui/button',
                'blockstudio' => ['attributes' => [['id' => 'label', 'type' => 'text']]],
            ])
        );

        $this->write(
            'blocks/elements/card/index.php',
            implode("\n", [
                '<div>',
                '<acme-badge text="ok" data-x="[&>p]:a" />',
                '<acme-card title="t" cta_label="Go" cta_block="bsui/button" id="kept" aria-label="Card" />',
                '<acme-badge text="ok" bogus="1" />',
                '<acme-nothing />',
                '<acme-button label="x" html-class="anything" />',
                '<acme-button label="x" wrong="1" />',
                '<theme-badge text="a" class="x" />',
                '<bs:theme-components-panel title="nested namespace" />',
                '<bs:core-separator />',
                '</div>',
            ])
        );

        $this->write('pages/home/page.json', (string) json_encode(['title' => 'Home', 'slug' => 'home']));
        $this->write(
            'pages/home/index.php',
            implode("\n", [
                '<section variant="hero" foo="1">',
                '<h2 id="intro" class="h2" style="color:red">Intro</h2>',
                '<p class="unmapped">Text</p>',
                '<div width="wide" gap="4">',
                '<div aria-hidden="true" data-animate="[&>span]:fade" role="note" class="<?php echo esc_attr( $c ); ?>">x</div>',
                '</div>',
                '<acme-badge text="a" html-onclick="alert(1)" />',
                '<acme-badge text="a" html-role="note" />',
                '<section class="x">y</section>',
                '</section>',
            ])
        );
        $this->write('pages/layout.php', "<section bar=\"1\"><acme-badge text=\"a\" html-onclick=\"x\" /></section>\n");
        $this->write('pages/docs/page.json', (string) json_encode(['title' => 'Docs', 'slug' => 'docs']));
        $this->write('pages/docs/index.html', "<section bar=\"1\">Docs</section>\n");
        $this->write('inc/module.php', "<?php\n\$html = '<bs:elements-badge text=\"x\" />';\n\$bad = '<bs:elements-badge text=\"x\" nope=\"1\" />';\n\$prose = esc_html( 'Write tags such as <bs:acme-card />.' );\n");
    }

    /**
     * @param list<array<string, string>> $attributes
     */
    private function block(string $name, array $attributes): void
    {
        $directory = 'blocks/' . $name;
        $this->write($directory . '/block.json', (string) json_encode([
            'name' => $name,
            'blockstudio' => ['attributes' => $attributes],
        ]));
        if (!is_file($this->root . '/' . $directory . '/index.php')) {
            $this->write($directory . '/index.php', '<div></div>');
        }
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
