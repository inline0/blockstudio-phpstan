<?php

declare(strict_types=1);

namespace Blockstudio\PHPStan\Rules;

use Blockstudio\PHPStan\Reflection\BlockTagParser;
use Blockstudio\PHPStan\Schema\BlockJsonReader;
use Blockstudio\PHPStan\Schema\BlockstudioJsonReader;
use Blockstudio\PHPStan\Schema\ProjectScanner;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validates block tags and element-mapped page HTML.
 *
 * Block templates and page layouts are checked for <bs:>, <block>, prefix
 * and alias tags. Page sources are also checked for the HTML elements the
 * project maps to blocks, because the page parser stores every attribute
 * an element carries while the block only renders the ones it consumes.
 * A tag names a registered block and passes only attributes that block
 * declares as fields or forwards to its root element.
 *
 * @implements Rule<FileNode>
 */
final class BlockTagRule implements Rule
{
    /**
     * Global attributes Blockstudio forwards to a block's root element when
     * the block does not declare a field of the same name.
     */
    private const FORWARDED_ATTRIBUTES = [
        'dir' => true,
        'hidden' => true,
        'id' => true,
        'lang' => true,
        'role' => true,
        'style' => true,
        'tabindex' => true,
        'title' => true,
    ];

    private const TEMPLATE_EXTENSIONS = ['php', 'twig', 'blade.php'];

    private const PAGE_EXTENSIONS = ['php', 'html', 'twig', 'blade.php'];

    /** @var array<string, true> */
    private static array $scannedFiles = [];

    private readonly BlockTagParser $parser;

    /**
     * @var array{
     *   prefixes: array<string, list<string>>,
     *   aliases: array<string, string>,
     *   elements: array<string, list<array{attribute: ?string, block: string}>>,
     *   scanPhp: bool,
     *   ui: bool
     * }|null
     */
    private ?array $configuration = null;

    /** @var array<string, string>|null */
    private ?array $uiBlocks = null;

    /** @var array<string, array{keys: list<string>, prefixes: list<string>}|null> */
    private array $acceptedKeys = [];

    private readonly BlockstudioJsonReader $settings;

    /** @var array<string, true> */
    private static array $unanalysedScanned = [];

    public function __construct(
        private readonly ProjectScanner $scanner,
        private readonly BlockJsonReader $reader,
        ?BlockstudioJsonReader $settings = null,
        private readonly string $currentWorkingDirectory = ''
    ) {
        $this->parser = new BlockTagParser();
        $this->settings = $settings ?? new BlockstudioJsonReader();
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = $this->checkUnanalysedSources();
        $file = $this->normalize($scope->getFile());
        $context = $this->contextOf($file);

        if ($context === null || isset(self::$scannedFiles[$file])) {
            return $errors;
        }
        self::$scannedFiles[$file] = true;

        $content = @file_get_contents($file);
        if ($content === false) {
            return $errors;
        }

        return array_merge($errors, $this->checkContent($content, $file, $context));
    }

    /**
     * Twig templates and HTML or Twig page sources are not PHP, so PHPStan
     * never analyses them; they are scanned once per process instead.
     *
     * @return list<IdentifierRuleError>
     */
    private function checkUnanalysedSources(): array
    {
        $scope = spl_object_id($this->scanner) . '|' . ($this->projectRoot() ?? '');
        if (isset(self::$unanalysedScanned[$scope])) {
            return [];
        }
        self::$unanalysedScanned[$scope] = true;

        $errors = [];
        $sources = [];

        foreach ($this->scanner->getBlockJsonPaths() as $blockJsonPath) {
            $candidate = dirname($blockJsonPath) . '/index.twig';
            if (is_file($candidate)) {
                $sources[$this->normalize($candidate)] = 'template';
            }
        }

        foreach ($this->pageSources() as $path => $context) {
            if (!str_ends_with($path, '.php')) {
                $sources[$path] = $context;
            }
        }

        foreach ($sources as $path => $context) {
            if (isset(self::$scannedFiles[$path])) {
                continue;
            }
            self::$scannedFiles[$path] = true;

            $content = @file_get_contents($path);
            if ($content !== false) {
                $errors = array_merge($errors, $this->checkContent($content, $path, $context));
            }
        }

        return $errors;
    }

    /**
     * How Blockstudio reads the tags in one file.
     *
     * `template` and `layout` content renders tags through the tag path, which
     * passes every html- attribute through. `page` content is parsed into
     * stored blocks, maps HTML elements, and forwards html- attributes only for
     * global attribute names. `php` is any other project PHP file, checked
     * when phpstan.blockTags.scanPhp is on.
     *
     * @return 'template'|'page'|'layout'|'php'|null
     */
    private function contextOf(string $file): ?string
    {
        $pages = $this->pageSources();
        if (isset($pages[$file])) {
            return $pages[$file];
        }

        $base = basename($file);
        foreach (self::TEMPLATE_EXTENSIONS as $extension) {
            if ($base === 'index.' . $extension && is_file(dirname($file) . '/block.json')) {
                return 'template';
            }
        }

        if (
            $this->configuration()['scanPhp']
            && str_ends_with($file, '.php')
            && !str_contains($file, '/vendor/')
            && !str_contains($file, '/node_modules/')
        ) {
            return 'php';
        }

        return null;
    }

    /**
     * Page sources and layouts below the project's pages directory.
     *
     * @return array<string, 'page'|'layout'>
     */
    private function pageSources(): array
    {
        static $cache = [];
        $root = $this->projectRoot();
        if ($root === null) {
            return [];
        }
        if (isset($cache[$root])) {
            return $cache[$root];
        }

        $sources = [];
        $pages = $root . '/pages';
        if (is_dir($pages)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($pages, \FilesystemIterator::SKIP_DOTS),
                    fn(\SplFileInfo $current): bool => !$current->isDir()
                        || !$this->scanner->isExcludedPath($current->getPathname())
                )
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $path = $this->normalize($file->getPathname());
                $base = $file->getFilename();
                if ($base === 'layout.php') {
                    $sources[$path] = 'layout';
                    continue;
                }

                foreach (self::PAGE_EXTENSIONS as $extension) {
                    if ($base === 'index.' . $extension) {
                        $sources[$path] = 'page';
                        break;
                    }
                }
            }
        }

        $cache[$root] = $sources;

        return $sources;
    }

    /**
     * @param 'template'|'page'|'layout'|'php' $context
     * @return list<IdentifierRuleError>
     */
    private function checkContent(string $content, string $file, string $context): array
    {
        $configuration = $this->configuration();
        $tags = $this->parser->extractTags(
            $content,
            array_keys($configuration['prefixes']),
            array_keys($configuration['aliases']),
            $context === 'page' ? array_keys($configuration['elements']) : []
        );

        $errors = [];
        foreach ($tags as $tag) {
            $errors = array_merge($errors, $this->validateTag($tag, $file, $context));
        }

        return $errors;
    }

    /**
     * @param array{
     *   name: string,
     *   tag: string,
     *   kind: 'bs'|'block'|'prefix'|'alias'|'element',
     *   candidates: list<string>,
     *   attributes: array<string, string>,
     *   line: int
     * } $tag
     * @param 'template'|'page'|'layout'|'php' $context
     * @return list<IdentifierRuleError>
     */
    private function validateTag(array $tag, string $file, string $context): array
    {
        $resolved = $this->resolve($tag);

        if ($resolved === 'skip') {
            return [];
        }

        // Other project PHP mentions tags in prose and escaped examples as
        // often as it renders them, so only resolved tags are judged there.
        if ($resolved === null && $context === 'php') {
            return [];
        }

        if ($resolved === null) {
            $message = $tag['kind'] === 'prefix'
                ? sprintf(
                    'Prefix tag <%s> resolves to no registered block in %s',
                    $tag['tag'],
                    basename($file)
                )
                : sprintf(
                    'Block tag references unknown block "%s" in %s',
                    $tag['name'],
                    basename($file)
                );

            return [
                RuleErrorBuilder::message($message)
                    ->identifier('blockstudio.blockTag.unknown')
                    ->file($file)
                    ->line($tag['line'])
                    ->build(),
            ];
        }

        [$blockName, $blockJsonPath] = $resolved;
        $accepted = $this->acceptedKeys($blockJsonPath);
        if ($accepted === null) {
            return [];
        }

        $errors = [];
        foreach (array_keys($tag['attributes']) as $attribute) {
            $attribute = (string) $attribute;
            if ($this->isAccepted($attribute, $accepted, $context)) {
                continue;
            }

            $where = $tag['kind'] === 'element'
                ? sprintf('Element <%s> mapped to "%s"', $tag['tag'], $blockName)
                : sprintf('Block tag "%s"', $blockName);

            if (strtolower($attribute) === 'class') {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    '%s passes "class", but the block declares no class field, so Blockstudio drops it in %s',
                    $where,
                    basename($file)
                ))
                    ->identifier('blockstudio.blockTag.class')
                    ->tip('Declare { "id": "class", "type": "text", "default": "" } in the block.json and print it in the template. Undeclared classes are never passed through.')
                    ->file($file)
                    ->line($tag['line'])
                    ->build();
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s has unknown attribute "%s" in %s',
                $where,
                $attribute,
                basename($file)
            ))
                ->identifier('blockstudio.blockTag.attribute')
                ->tip('Declare it as a field, or use a data-, aria- or html- attribute (id, style, role, title, lang, dir, hidden and tabindex are forwarded too).')
                ->file($file)
                ->line($tag['line'])
                ->build();
        }

        return $errors;
    }

    /**
     * @param array{
     *   name: string,
     *   tag: string,
     *   kind: 'bs'|'block'|'prefix'|'alias'|'element',
     *   candidates: list<string>,
     *   attributes: array<string, string>,
     *   line: int
     * } $tag
     * @return array{0: string, 1: string}|'skip'|null Block name and block.json path, skip, or null when unknown.
     */
    private function resolve(array $tag): array|string|null
    {
        $configuration = $this->configuration();

        switch ($tag['kind']) {
            case 'bs':
            case 'block':
                foreach ($tag['candidates'] as $candidate) {
                    $path = $this->findBlock($candidate);
                    if ($path !== null) {
                        return [$candidate, $path];
                    }
                }

                foreach ($tag['candidates'] as $candidate) {
                    if (str_starts_with($candidate, 'core/') || $this->isUnindexedUiBlock($candidate)) {
                        return 'skip';
                    }
                }

                return null;

            case 'alias':
                $blockName = $configuration['aliases'][$tag['tag']] ?? '';
                $path = $this->findBlock($blockName);

                return $path !== null ? [$blockName, $path] : 'skip';

            case 'element':
                $blockName = $this->elementBlock($tag['tag'], $tag['attributes']);
                if ($blockName === null) {
                    return 'skip';
                }
                $path = $this->findBlock($blockName);

                return $path !== null ? [$blockName, $path] : 'skip';

            default:
                return $this->resolvePrefix($tag['tag'], $configuration['prefixes']);
        }
    }

    /**
     * Resolve a prefix tag in namespace order, with nested prefixes, the way
     * Block_Tags::resolve_prefix_tag_name() does.
     *
     * @param array<string, list<string>> $prefixes
     * @return array{0: string, 1: string}|'skip'|null
     */
    private function resolvePrefix(string $tagName, array $prefixes): array|string|null
    {
        $hyphen = strpos($tagName, '-');
        if ($hyphen === false) {
            return 'skip';
        }

        $prefix = substr($tagName, 0, $hyphen);
        $slug = substr($tagName, $hyphen + 1);
        if ($slug === '' || ($prefixes[$prefix] ?? []) === []) {
            return 'skip';
        }

        foreach ($prefixes[$prefix] as $namespace) {
            $path = $this->findBlock($namespace . '/' . $slug);
            if ($path !== null) {
                return [$namespace . '/' . $slug, $path];
            }
        }

        $nested = strpos($slug, '-');
        if ($nested !== false) {
            $nestedPrefix = substr($slug, 0, $nested);
            if ($nestedPrefix !== $prefix && ($prefixes[$nestedPrefix] ?? []) !== []) {
                return $this->resolvePrefix($slug, $prefixes);
            }
        }

        foreach ($prefixes[$prefix] as $namespace) {
            if ($namespace === 'core' || $this->isUnindexedUiBlock($namespace . '/' . $slug)) {
                return 'skip';
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function elementBlock(string $element, array $attributes): ?string
    {
        foreach ($this->configuration()['elements'][$element] ?? [] as $rule) {
            if ($rule['attribute'] === null || array_key_exists($rule['attribute'], $attributes)) {
                return $rule['block'];
            }
        }

        return null;
    }

    /**
     * @param array{keys: list<string>, prefixes: list<string>} $accepted
     * @param 'template'|'page'|'layout'|'php' $context
     */
    private function isAccepted(string $attribute, array $accepted, string $context): bool
    {
        if (in_array($attribute, $accepted['keys'], true) || $attribute === 'key') {
            return true;
        }

        foreach ($accepted['prefixes'] as $prefix) {
            if (str_starts_with($attribute, $prefix)) {
                return true;
            }
        }

        $name = strtolower($attribute);
        if (str_starts_with($name, 'html-')) {
            if ($context !== 'page') {
                return true;
            }
            $name = substr($name, 5);
        }

        return isset(self::FORWARDED_ATTRIBUTES[$name])
            || (str_starts_with($name, 'data-') && strlen($name) > 5)
            || (str_starts_with($name, 'aria-') && strlen($name) > 5);
    }

    /**
     * Field keys a block accepts: its declared fields, custom fields resolved,
     * and the fields a block-typed field forwards under its idStructure. When
     * the referenced block cannot be looked up, every key its idStructure can
     * produce is accepted.
     *
     * @return array{keys: list<string>, prefixes: list<string>}|null
     */
    private function acceptedKeys(string $blockJsonPath): ?array
    {
        if (array_key_exists($blockJsonPath, $this->acceptedKeys)) {
            return $this->acceptedKeys[$blockJsonPath];
        }

        $this->acceptedKeys[$blockJsonPath] = null;
        $data = $this->reader->load($blockJsonPath);
        if ($data === null || $this->reader->getCustomFieldIssues($blockJsonPath) !== []) {
            return null;
        }

        $keys = $this->reader->getAttributeKeys($blockJsonPath) ?? [];
        $prefixes = [];
        $attributes = $data['blockstudio']['attributes'] ?? [];
        if (is_array($attributes)) {
            $keys = array_merge($keys, $this->blockFieldKeys($attributes, '', $prefixes));
        }

        $this->acceptedKeys[$blockJsonPath] = [
            'keys' => array_values(array_unique($keys)),
            'prefixes' => array_values(array_unique($prefixes)),
        ];

        return $this->acceptedKeys[$blockJsonPath];
    }

    /**
     * @param array<mixed> $attributes
     * @param list<string> $prefixes Key prefixes of block fields whose block is unknown.
     * @return list<string>
     */
    private function blockFieldKeys(array $attributes, string $prefix, array &$prefixes): array
    {
        $keys = [];

        foreach ($attributes as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
            $id = is_string($field['id'] ?? null) ? $field['id'] : '';

            if ($type === 'tabs' && is_array($field['tabs'] ?? null)) {
                foreach ($field['tabs'] as $tab) {
                    if (is_array($tab) && is_array($tab['attributes'] ?? null)) {
                        $keys = array_merge($keys, $this->blockFieldKeys($tab['attributes'], $prefix, $prefixes));
                    }
                }
                continue;
            }

            if ($type === 'group' && is_array($field['attributes'] ?? null)) {
                $groupPrefix = $id === '' ? $prefix : ($prefix === '' ? $id : $prefix . '_' . $id);
                $keys = array_merge($keys, $this->blockFieldKeys($field['attributes'], $groupPrefix, $prefixes));
                continue;
            }

            if ($type !== 'block' || $id === '' || !is_string($field['block'] ?? null)) {
                continue;
            }

            $key = $prefix === '' ? $id : $prefix . '_' . $id;
            $keys[] = $key . '_block';

            $structure = is_string($field['idStructure'] ?? null) ? $field['idStructure'] : '{id}';
            $childPath = $this->findBlock($field['block']);
            if ($childPath === null) {
                $structurePrefix = strstr($structure, '{id}', true);
                if (is_string($structurePrefix) && $structurePrefix !== '') {
                    $prefixes[] = $structurePrefix;
                }
                continue;
            }

            foreach ($this->reader->getAttributeKeys($childPath) ?? [] as $childKey) {
                $keys[] = str_replace('{id}', $childKey, $structure);
            }
        }

        return $keys;
    }

    private function findBlock(string $blockName): ?string
    {
        if ($blockName === '' || !str_contains($blockName, '/')) {
            return null;
        }

        $path = $this->scanner->findBlockJsonByName($blockName);
        if ($path !== null) {
            return $path;
        }

        return $this->uiBlocks()[$blockName] ?? null;
    }

    /**
     * A bsui block can only be looked up when the bundled UI blocks were
     * found; otherwise it is neither known nor reported.
     */
    private function isUnindexedUiBlock(string $blockName): bool
    {
        return str_starts_with($blockName, 'bsui/')
            && $this->configuration()['ui']
            && $this->uiBlocks() === [];
    }

    /**
     * Bundled UI blocks, which ship inside the Blockstudio package that the
     * project scanner skips with the rest of vendor/.
     *
     * @return array<string, string>
     */
    private function uiBlocks(): array
    {
        if ($this->uiBlocks !== null) {
            return $this->uiBlocks;
        }

        $this->uiBlocks = [];
        if (!$this->configuration()['ui']) {
            return $this->uiBlocks;
        }

        $directory = $this->uiBlocksDirectory();
        if ($directory === null) {
            return $this->uiBlocks;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getFilename() !== 'block.json') {
                continue;
            }

            $data = $this->reader->load($file->getPathname());
            $name = is_array($data) ? ($data['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $this->uiBlocks[$name] = $this->normalize($file->getPathname());
            }
        }

        return $this->uiBlocks;
    }

    private function uiBlocksDirectory(): ?string
    {
        $candidates = [];
        if (
            class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('blockstudio/blockstudio')
        ) {
            $installPath = \Composer\InstalledVersions::getInstallPath('blockstudio/blockstudio');
            if (is_string($installPath)) {
                $candidates[] = $installPath . '/includes/ui/blocks';
            }
        }

        $root = $this->projectRoot();
        if ($root !== null) {
            $candidates[] = $root . '/vendor/blockstudio/blockstudio/includes/ui/blocks';
        }

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $this->normalize($candidate);
            }
        }

        return null;
    }

    /**
     * Tag settings: blockTags.prefixes and ui.enabled as the runtime reads
     * them, and the analysis-only phpstan.blockTags section.
     *
     * @return array{
     *   prefixes: array<string, list<string>>,
     *   aliases: array<string, string>,
     *   elements: array<string, list<array{attribute: ?string, block: string}>>,
     *   scanPhp: bool,
     *   ui: bool
     * }
     */
    private function configuration(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $root = $this->projectRoot();
        $settings = $root === null ? null : $this->settings->load($root . '/blockstudio.json');
        $settings = is_array($settings) ? $settings : [];
        $analysis = $settings['phpstan']['blockTags'] ?? [];
        $analysis = is_array($analysis) ? $analysis : [];

        $this->configuration = [
            'prefixes' => self::normalizePrefixes($settings['blockTags']['prefixes'] ?? []),
            'aliases' => self::normalizeAliases($analysis['aliases'] ?? []),
            'elements' => self::normalizeElements($analysis['elements'] ?? []),
            'scanPhp' => ($analysis['scanPhp'] ?? false) === true,
            'ui' => ($settings['ui']['enabled'] ?? false) === true,
        ];

        return $this->configuration;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function normalizePrefixes(mixed $prefixes): array
    {
        if (!is_array($prefixes)) {
            return [];
        }

        $normalized = [];
        foreach ($prefixes as $prefix => $namespaces) {
            if (!is_string($prefix)) {
                continue;
            }
            $prefix = strtolower(trim($prefix));
            if (preg_match('/^[a-z][a-z0-9]*$/', $prefix) !== 1) {
                continue;
            }

            $valid = [];
            foreach (is_string($namespaces) ? [$namespaces] : (is_array($namespaces) ? $namespaces : []) as $namespace) {
                if (!is_string($namespace)) {
                    continue;
                }
                $namespace = strtolower(trim($namespace));
                if (preg_match('/^[a-z][a-z0-9-]*$/', $namespace) === 1 && !in_array($namespace, $valid, true)) {
                    $valid[] = $namespace;
                }
            }

            if ($valid !== []) {
                $normalized[$prefix] = $valid;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeAliases(mixed $aliases): array
    {
        if (!is_array($aliases)) {
            return [];
        }

        $normalized = [];
        foreach ($aliases as $tag => $blockName) {
            if (
                is_string($tag)
                && is_string($blockName)
                && preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/', strtolower($tag)) === 1
            ) {
                $normalized[strtolower($tag)] = $blockName;
            }
        }

        return $normalized;
    }

    /**
     * An element maps to a block name, or to rules tried in order: the first
     * rule whose attribute the element carries wins, a rule without an
     * attribute always matches.
     *
     * @return array<string, list<array{attribute: ?string, block: string}>>
     */
    private static function normalizeElements(mixed $elements): array
    {
        if (!is_array($elements)) {
            return [];
        }

        $normalized = [];
        foreach ($elements as $element => $target) {
            if (!is_string($element) || preg_match('/^[a-z][a-z0-9]*$/', strtolower($element)) !== 1) {
                continue;
            }

            $rules = [];
            foreach (is_array($target) && array_is_list($target) ? $target : [$target] as $rule) {
                if (is_string($rule) && $rule !== '') {
                    $rules[] = ['attribute' => null, 'block' => $rule];
                } elseif (is_array($rule) && is_string($rule['block'] ?? null) && $rule['block'] !== '') {
                    $rules[] = [
                        'attribute' => is_string($rule['attribute'] ?? null) && $rule['attribute'] !== ''
                            ? $rule['attribute']
                            : null,
                        'block' => $rule['block'],
                    ];
                }
            }

            if ($rules !== []) {
                $normalized[strtolower($element)] = $rules;
            }
        }

        return $normalized;
    }

    private function projectRoot(): ?string
    {
        $root = rtrim($this->normalize($this->currentWorkingDirectory), '/');

        return $root === '' ? null : $root;
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
