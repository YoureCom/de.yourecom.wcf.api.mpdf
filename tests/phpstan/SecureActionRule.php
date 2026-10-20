<?php

namespace yourecom\standard\phpstan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * YoureCom Standard: a legacy action (`wcf\action\AbstractAction`) that changes something extends
 * `AbstractSecureAction`, which checks the security token (`t={csrfToken type=url}` in links, `{csrfToken}` in forms).
 * The core checks the XSRF header only for PSR-15 controllers (`RequestHandlerInterface`, middleware `Xsrf`), a legacy
 * action without the token can be triggered by any page the user visits (found by an external review in the bank transfer
 * plugin, October 2026).
 *
 * "Changes something": it calls `executeAction()`, creates a command (`…\command\…`), creates or calls a
 * `…Editor`, or prepares an INSERT/UPDATE/DELETE/REPLACE. An action that checks the token itself (`checkSecurityToken()`)
 * is fine, and so is an action that does not act for the signed-in user (no WCF::getUser()/getSession(), no login or
 * permissions required: a server-to-server API with a signature, a webhook). A download whose write is harmless (e.g. a download counter) gets an ignore with the reason.
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * @author  Marcel Beckers
 * @license YoureCom License - Commercial (YCLC)  <https://www.yourecom.de/shop/licence-commercial/>
 * @implements Rule<InClassNode>
 */
final class SecureActionRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        if (
            !$class->isSubclassOf('wcf\action\AbstractAction')
            || $class->isSubclassOf('wcf\action\AbstractSecureAction')
            || $class->implementsInterface('Psr\Http\Server\RequestHandlerInterface')
        ) {
            return [];
        }

        $finder = new NodeFinder();
        $statements = $node->getOriginalNode()->stmts;
        $checksToken = $finder->findFirst($statements, static fn (Node $found): bool => ($found instanceof Expr\MethodCall || $found instanceof Expr\StaticCall)
            && $found->name instanceof Node\Identifier
            && \strcasecmp($found->name->toString(), 'checkSecurityToken') === 0);
        if ($checksToken !== null) {
            return [];
        }

        // without the session (e.g. a server-to-server API with a signature, a webhook) a forged request gains nothing
        if (!$this->actsForTheUser($class, $statements, $finder)) {
            return [];
        }

        $writes = [];
        foreach ($finder->find($statements, static fn (Node $found): bool => $found instanceof Expr) as $expression) {
            $write = $this->describeWrite($expression);
            if ($write !== null) {
                $writes[$write] = true;
            }
        }
        if ($writes === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                '%s changes data (%s) but does not extend AbstractSecureAction: links need t={csrfToken type=url}, forms {csrfToken}. A harmless write (e.g. a download counter, a signed webhook) gets an ignore with the reason.',
                $class->getDisplayName(),
                \implode(', ', \array_slice(\array_keys($writes), 0, 3)),
            ))
                ->identifier('yourecom.secureAction')
                ->line($node->getOriginalNode()->getStartLine())
                ->build(),
        ];
    }

    /**
     * Whether the action acts for the signed-in user: it reads the user or the session (WCF::getUser(),
     * WCF::getSession(), also in a parent class of the plugin), requires a login or permissions.
     *
     * @param array<Node\Stmt> $statements
     */
    private function actsForTheUser(ClassReflection $class, array $statements, NodeFinder $finder): bool
    {
        $usesSession = static fn (Node $found): bool => $found instanceof Expr\StaticCall
            && $found->class instanceof Node\Name
            && \in_array(\ltrim($found->class->toString(), '\\'), ['wcf\system\WCF', 'WCF'], true)
            && $found->name instanceof Node\Identifier
            && \in_array(\strtolower($found->name->toString()), ['getuser', 'getsession', 'getuserprofilehandler'], true);
        if ($finder->findFirst($statements, $usesSession) !== null) {
            return true;
        }

        $defaults = $class->getNativeReflection()->getDefaultProperties();
        if (($defaults['loginRequired'] ?? false) === true || (\is_array($defaults['neededPermissions'] ?? null) && $defaults['neededPermissions'] !== [])) {
            return true;
        }

        // parent classes of the plugin (not the core's AbstractAction, which reads the session for its permission check)
        foreach ($class->getParents() as $parent) {
            if (\str_starts_with($parent->getName(), 'wcf\action\\')) {
                break;
            }
            $file = $parent->getFileName();
            if ($file !== null && \preg_match('~WCF::get(?:User|Session|UserProfileHandler)\(~', (string)\file_get_contents($file)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Describes the expression if it changes data, null otherwise.
     */
    private function describeWrite(Expr $expression): ?string
    {
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Node\Identifier) {
            $method = $expression->name->toString();
            if (\strcasecmp($method, 'executeAction') === 0) {
                return 'executeAction()';
            }
            if (\preg_match('~^prepare(?:Statement|Unmanaged)?$~i', $method) === 1 && isset($expression->args[0]) && $expression->args[0] instanceof Node\Arg) {
                $sql = $this->leadingString($expression->args[0]->value);
                if ($sql !== null && \preg_match('~^\s*(INSERT|UPDATE|DELETE|REPLACE)\b~i', $sql, $match) === 1) {
                    return \strtoupper($match[1]) . ' statement';
                }
            }
        }

        if ($expression instanceof Expr\New_ && $expression->class instanceof Node\Name) {
            $name = $expression->class->toString();
            if (\str_contains(\strtolower($name), '\command\\')) {
                return 'command ' . $this->shortName($name);
            }
            if (\str_ends_with($name, 'Editor')) {
                return $this->shortName($name);
            }
        }

        if ($expression instanceof Expr\StaticCall && $expression->class instanceof Node\Name && \str_ends_with($expression->class->toString(), 'Editor')) {
            return $this->shortName($expression->class->toString()) . '::' . ($expression->name instanceof Node\Identifier ? $expression->name->toString() : '…') . '()';
        }

        return null;
    }

    /**
     * The literal start of a string expression (a literal, or the left end of a concatenation or interpolation).
     */
    private function leadingString(Expr $expression): ?string
    {
        while ($expression instanceof Expr\BinaryOp\Concat) {
            $expression = $expression->left;
        }
        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value;
        }
        if ($expression instanceof Node\Scalar\InterpolatedString && ($expression->parts[0] ?? null) instanceof Node\InterpolatedStringPart) {
            return $expression->parts[0]->value;
        }

        return null;
    }

    /**
     * The class name without its namespace.
     */
    private function shortName(string $name): string
    {
        $position = \strrpos($name, '\\');

        return $position === false ? $name : \substr($name, $position + 1);
    }
}
