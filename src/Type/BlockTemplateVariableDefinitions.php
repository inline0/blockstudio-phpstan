<?php

declare(strict_types=1);

namespace Blockstudio\PHPStan\Type;

use Blockstudio\PHPStan\Reflection\BlockTemplateDetector;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\IgnoreErrorExtension;
use PHPStan\Analyser\Scope;

/**
 * Blockstudio defines $a, $attributes, $b, $block, $c, $context and the other
 * template variables before it includes a block template, so reading one
 * there is not an undefined variable. BlockTemplateVariableType types them;
 * this keeps the undefined-variable check from reporting them, which it does
 * for every direct read such as `echo $a['title'];` at level 1 and above.
 */
final class BlockTemplateVariableDefinitions implements IgnoreErrorExtension
{
    public function __construct(
        private readonly BlockTemplateDetector $detector
    ) {}

    public function shouldIgnore(Error $error, Node $node, Scope $scope): bool
    {
        if (
            $error->getIdentifier() !== 'variable.undefined'
            || !$node instanceof Variable
            || !is_string($node->name)
            || !BlockTemplateVariableType::isTemplateVariable($node->name)
            || $scope->getFunction() !== null
            || $scope->isInAnonymousFunction()
        ) {
            return false;
        }

        return $this->detector->getBlockJsonForTemplate($scope->getFile()) !== null;
    }
}
