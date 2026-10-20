<?php

namespace yourecom\standard\phpstan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * YoureCom Standard: `getPermission()` is never compared with `true` or `false`. The session returns the raw value of the
 * group option (`'1'`, `1`, `false` …), so `=== true` denies everyone and `=== false` lets everyone through; cast with
 * `(bool)` instead. Replaces the regular expression of check-architecture.php, which missed calls over several lines and
 * arguments with parentheses.
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * @author  Marcel Beckers
 * @license YoureCom License - Commercial (YCLC)  <https://www.yourecom.de/shop/licence-commercial/>
 * @implements Rule<Expr\BinaryOp>
 */
final class PermissionStrictRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return Expr\BinaryOp::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Expr\BinaryOp\Identical && !$node instanceof Expr\BinaryOp\NotIdentical) {
            return [];
        }

        foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$call, $value]) {
            if ($this->isGetPermission($call) && $this->isBoolean($value)) {
                return [
                    RuleErrorBuilder::message('getPermission() compared with true/false: it returns the raw group value (\'1\', 1, false), cast with (bool).')
                        ->identifier('yourecom.permissionStrict')
                        ->build(),
                ];
            }
        }

        return [];
    }

    /**
     * Whether the expression calls a method named getPermission (session, user profile, any object or class).
     */
    private function isGetPermission(Expr $expression): bool
    {
        if (!$expression instanceof Expr\MethodCall && !$expression instanceof Expr\NullsafeMethodCall && !$expression instanceof Expr\StaticCall) {
            return false;
        }

        return $expression->name instanceof Node\Identifier && \strcasecmp($expression->name->toString(), 'getPermission') === 0;
    }

    /**
     * Whether the expression is the constant true or false.
     */
    private function isBoolean(Expr $expression): bool
    {
        return $expression instanceof Expr\ConstFetch && \in_array(\strtolower($expression->name->toString()), ['true', 'false'], true);
    }
}
