<?php

declare(strict_types=1);

namespace Blockstudio\PHPStan\Type;

use Blockstudio\PHPStan\Reflection\BlockTemplateDetector;
use Blockstudio\PHPStan\Schema\BlockJsonReader;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final class BlockTemplateVariableType implements ExpressionTypeResolverExtension
{
    private const ATTRIBUTE_VARIABLES = [
        'a' => true,
        'attributes' => true,
    ];

    private const ARRAY_VARIABLES = [
        'b' => true,
        'block' => true,
        'c' => true,
        'context' => true,
    ];

    private const STRING_VARIABLES = [
        'content' => true,
        'inner_blocks' => true,
        'islandPhase' => true,
    ];

    private const BOOLEAN_VARIABLES = [
        'isEditor' => true,
        'isPreview' => true,
        'isIsland' => true,
        'isIslandPlaceholder' => true,
        'isIslandFragment' => true,
    ];

    /** @var array<string, array<string, true>> */
    private array $writtenVariables = [];

    public function __construct(
        private readonly BlockTemplateDetector $detector,
        private readonly BlockJsonReader $reader
    ) {}

    /**
     * Whether Blockstudio passes a variable of this name to every template.
     */
    public static function isTemplateVariable(string $name): bool
    {
        return isset(self::ATTRIBUTE_VARIABLES[$name])
            || isset(self::ARRAY_VARIABLES[$name])
            || isset(self::STRING_VARIABLES[$name])
            || isset(self::BOOLEAN_VARIABLES[$name]);
    }

    public function getType(Expr $expr, Scope $scope): ?Type
    {
        $name = self::rootVariableName($expr);
        if ($name === null || !self::isTemplateVariable($name)) {
            return null;
        }

        if ($scope->getFunction() !== null || $scope->isInAnonymousFunction()) {
            return null;
        }

        $blockJson = $this->detector->getBlockJsonForTemplate($scope->getFile());
        if ($blockJson === null) {
            return null;
        }

        $written = isset($this->writtenVariables($scope->getFile())[$name]);

        if ($expr instanceof Variable) {
            $declared = $this->declaredType($name, $blockJson);

            if (!$scope->hasVariableType($name)->yes()) {
                return $declared;
            }

            // The scope also reports a template variable as defined once a
            // condition narrowed it (`isset( $a['x'] )` leaves `$a` as
            // `hasOffset('x')` on an otherwise mixed value). Unless the
            // template assigns the variable itself, that narrowing applies to
            // the type Blockstudio passes in.
            if ($written) {
                return null;
            }

            return self::narrow($declared, $scope->getVariableType($name));
        }

        // Offset reads on a template variable. PHPStan 2.3 prices the left
        // side of `??` and `isset()` from the scope's own state, which never
        // asks this extension about the variable, so `$a['x'] ?? ''` came out
        // as mixed. Answer the offset chain from the declared type instead,
        // narrowed by whatever the scope tracks for the expression.
        // The scope's tracked state is read through getStateType(), which
        // PHPStan 2.3 added to its scope. Earlier releases price these reads
        // through this extension already, so they keep the old behaviour.
        $stateReader = [$scope, 'getStateType'];
        if ($written || !is_callable($stateReader)) {
            return null;
        }

        return $this->offsetChainType($expr, $scope, $stateReader, $this->declaredType($name, $blockJson));
    }

    /**
     * The declared type of a template variable's offset chain such as
     * `$a['cta']['href']`, each link narrowed by the type the scope tracks
     * for it, or null when a link is not a known offset of its parent.
     *
     * @param callable(Expr): mixed $stateReader
     */
    private function offsetChainType(Expr $expr, Scope $scope, callable $stateReader, Type $declared): ?Type
    {
        if ($expr instanceof Variable) {
            return is_string($expr->name) && $scope->hasVariableType($expr->name)->yes()
                ? self::narrow($declared, $scope->getVariableType($expr->name))
                : $declared;
        }

        if (!$expr instanceof Expr\ArrayDimFetch || $expr->dim === null) {
            return null;
        }

        $parent = $this->offsetChainType($expr->var, $scope, $stateReader, $declared);
        if ($parent === null) {
            return null;
        }

        $dim = $scope->getType($expr->dim);
        if ($parent->hasOffsetValueType($dim)->no()) {
            return null;
        }

        $type = $parent->getOffsetValueType($dim);
        if (!$scope->hasExpressionType($expr)->yes()) {
            return $type;
        }

        $state = $stateReader($expr);

        return $state instanceof Type ? self::narrow($type, $state) : $type;
    }

    private static function narrow(Type $declared, Type $scopeType): Type
    {
        $narrowed = TypeCombinator::intersect($declared, $scopeType);

        return $narrowed instanceof NeverType ? $declared : $narrowed;
    }

    /**
     * The variable at the root of `$name` or an offset chain on it.
     */
    private static function rootVariableName(Expr $expr): ?string
    {
        while ($expr instanceof Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }

        return $expr instanceof Variable && is_string($expr->name) ? $expr->name : null;
    }

    private function declaredType(string $name, string $blockJson): Type
    {
        if (isset(self::ATTRIBUTE_VARIABLES[$name])) {
            return $this->attributeType($blockJson);
        }

        if (isset(self::ARRAY_VARIABLES[$name])) {
            return $this->openArrayType();
        }

        if (isset(self::STRING_VARIABLES[$name])) {
            return new StringType();
        }

        return new BooleanType();
    }

    /**
     * Names of the variables a template writes at file level: assignment
     * targets (including list destructuring and writes to an offset or
     * property of the variable), foreach variables, `global`,
     * `static` and `catch` variables. Function and class bodies are skipped.
     *
     * @return array<string, true>
     */
    private function writtenVariables(string $file): array
    {
        if (isset($this->writtenVariables[$file])) {
            return $this->writtenVariables[$file];
        }

        $code = @file_get_contents($file);
        $ast = null;
        if (is_string($code)) {
            try {
                $ast = (new ParserFactory())->createForHostVersion()->parse($code);
            } catch (\Throwable) {
                $ast = null;
            }
        }

        if ($ast === null) {
            // Unparseable here means unknown: treat every name as written so
            // the scope's own type wins, which is the conservative answer.
            $all = [];
            foreach ([self::ATTRIBUTE_VARIABLES, self::ARRAY_VARIABLES, self::STRING_VARIABLES, self::BOOLEAN_VARIABLES] as $names) {
                $all += $names;
            }

            return $this->writtenVariables[$file] = $all;
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $names = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof FunctionLike || $node instanceof Stmt\ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if (
                    $node instanceof Expr\Assign
                    || $node instanceof Expr\AssignRef
                    || $node instanceof Expr\AssignOp
                ) {
                    $this->collect($node->var);
                } elseif ($node instanceof Stmt\Foreach_) {
                    $this->collect($node->keyVar);
                    $this->collect($node->valueVar);
                } elseif ($node instanceof Stmt\Global_) {
                    foreach ($node->vars as $var) {
                        $this->collect($var);
                    }
                } elseif ($node instanceof Stmt\Static_) {
                    foreach ($node->vars as $var) {
                        $this->collect($var->var);
                    }
                } elseif ($node instanceof Stmt\Catch_) {
                    $this->collect($node->var);
                }

                return null;
            }

            private function collect(?Node $target): void
            {
                while (
                    $target instanceof Expr\ArrayDimFetch
                    || $target instanceof Expr\PropertyFetch
                ) {
                    $target = $target->var;
                }

                if ($target instanceof Variable) {
                    if (is_string($target->name)) {
                        $this->names[$target->name] = true;
                    }

                    return;
                }

                if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
                    foreach ($target->items as $item) {
                        if ($item !== null) {
                            $this->collect($item->value);
                        }
                    }
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $this->writtenVariables[$file] = $visitor->names;
    }

    private function attributeType(string $blockJsonPath): Type
    {
        if ($this->reader->getCustomFieldIssues($blockJsonPath) !== []) {
            return $this->openArrayType();
        }

        $keys = $this->reader->getAttributeKeys($blockJsonPath);
        if ($keys === null || $keys === []) {
            return $this->openArrayType();
        }

        return $this->reader->getAttributeType($blockJsonPath) ?? $this->openArrayType();
    }

    private function openArrayType(): Type
    {
        return new ArrayType(new StringType(), new MixedType());
    }
}
