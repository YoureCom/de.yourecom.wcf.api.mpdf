<?php

namespace yourecom\standard\phpstan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * YoureCom Standard: an RPC endpoint that calls `validateX()` of a DatabaseObjectAction whose `x` is in `$requireACP`
 * checks the ACP access itself. `$requireACP` only blocks AJAX requests (`validateAction()`), the direct call bypasses it, so
 * a method that was meant for the ACP only is open to everyone who passes `validateX()` (e.g. moderators without ACP access
 * created shipping labels through `/api/rpc/`, WoltLab review, October 2026).
 *
 * Only own method names count (not create/update/delete: the standard puts those into `$requireACP` as a second lock for
 * frontend actions, too). Fine if the endpoint or `validateX()` mentions `canUseAcp` or an `admin.` permission; a method
 * that is meant for the frontend leaves `$requireACP` (or gets an ignore with the reason).
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * @author  Marcel Beckers
 * @license YoureCom License - Commercial (YCLC)  <https://www.yourecom.de/shop/licence-commercial/>
 * @implements Rule<MethodCall>
 */
final class EndpointRequireAcpRule implements Rule
{
    private const CRUD = ['create', 'update', 'delete', 'action'];

    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $endpoint = $scope->getClassReflection();
        if (
            $endpoint === null
            || !$endpoint->implementsInterface('wcf\system\endpoint\IController')
            || !$node->name instanceof Node\Identifier
            || \preg_match('~^validate(\w+)$~', $node->name->toString(), $match) !== 1
        ) {
            return [];
        }

        $name = \lcfirst($match[1]);
        if (\in_array(\strtolower($name), self::CRUD, true)) {
            return [];
        }

        foreach ($scope->getType($node->var)->getObjectClassReflections() as $action) {
            if (!$action->isSubclassOf('wcf\data\AbstractDatabaseObjectAction') || !\in_array($name, $this->requireAcp($action), true)) {
                continue;
            }
            if ($this->checksAcp($this->source($endpoint)) || $this->methodChecksAcp($action, $node->name->toString())) {
                continue;
            }

            return [
                RuleErrorBuilder::message(\sprintf(
                    'The endpoint calls %s::%s() directly, which bypasses $requireACP (\'%s\' is in it): check admin.general.canUseAcp or an administrator permission in the endpoint or in %s(). If the method is meant for the frontend, remove it from $requireACP.',
                    $action->getDisplayName(),
                    $node->name->toString(),
                    $name,
                    $node->name->toString(),
                ))
                    ->identifier('yourecom.endpointRequireAcp')
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * The default value of `$requireACP` of the action class.
     *
     * @return list<string>
     */
    private function requireAcp(ClassReflection $action): array
    {
        $value = $action->getNativeReflection()->getDefaultProperties()['requireACP'] ?? [];

        return \is_array($value) ? \array_values(\array_filter($value, 'is_string')) : [];
    }

    /**
     * Whether the source checks the ACP access or an administrator permission.
     */
    private function checksAcp(string $source): bool
    {
        return \str_contains($source, 'canUseAcp') || \preg_match('~[\'"]admin\.~', $source) === 1;
    }

    /**
     * Whether the validation method checks the ACP access, itself or in a `$this->validate…()` it calls (e.g.
     * validateCreateReturnLabel() calling validateCreateLabel()), up to three calls deep.
     */
    private function methodChecksAcp(ClassReflection $action, string $method, int $depth = 0): bool
    {
        $source = $this->methodSource($action, $method);
        if ($this->checksAcp($source)) {
            return true;
        }
        if ($depth >= 3 || \preg_match_all('~\$this->(validate\w+)\s*\(~', $source, $calls) === 0) {
            return false;
        }
        foreach (\array_unique($calls[1]) as $called) {
            if (\strcasecmp($called, $method) !== 0 && $this->methodChecksAcp($action, $called, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source of the class file, empty if unknown.
     */
    private function source(ClassReflection $class): string
    {
        $file = $class->getFileName();

        return $file !== null && \is_file($file) ? (string)\file_get_contents($file) : '';
    }

    /**
     * The source of a method (its lines in the declaring class file), empty if unknown.
     */
    private function methodSource(ClassReflection $class, string $method): string
    {
        $native = $class->getNativeReflection();
        if (!$native->hasMethod($method)) {
            return '';
        }

        $reflection = $native->getMethod($method);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        if ($file === false || $start === false || $end === false || !\is_file($file)) {
            return '';
        }

        $lines = \file($file) ?: [];

        return \implode('', \array_slice($lines, $start - 1, $end - $start + 1));
    }
}
