<?php

namespace yourecom\standard\phpstan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * YoureCom Standard: every method has either `#[\Override]` (when it overrides or implements a method) or a docblock
 * that explains it. With `#[\Override]` there is no explanation and no `@inheritDoc`, a docblock only keeps tags that
 * PHPStan needs (e.g. `@param`, `@return`). Test methods of PHPUnit (`test*()` or `#[Test]`) need no docblock, their
 * name describes the test. Magic methods that override nothing (`__construct()`, `__invoke()`, `__get()` …, where
 * `#[\Override]` is not allowed) need no explanation either, PHP fixes their purpose; a docblock with `@inheritDoc` is
 * fine.
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * @author  Marcel Beckers
 * @license YoureCom License - Commercial (YCLC)  <https://www.yourecom.de/shop/licence-commercial/>
 * @implements Rule<InClassMethodNode>
 */
final class OverrideOrDocblockRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getOriginalNode();
        $class = $node->getClassReflection();
        $name = $method->name->toString();
        $docComment = $method->getDocComment()?->getText();

        $hasOverride = false;
        $isTest = false;
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = \ltrim($attribute->name->toString(), '\\');
                if (\strcasecmp($attributeName, 'Override') === 0) {
                    $hasOverride = true;
                }
                if ($attributeName === 'PHPUnit\Framework\Attributes\Test') {
                    $isTest = true;
                }
            }
        }

        // the name of a test method describes the test
        if (($isTest || \str_starts_with($name, 'test')) && $class->isSubclassOf('PHPUnit\Framework\TestCase')) {
            return [];
        }

        $parent = $this->overriddenClass($class, $name);
        if ($parent !== null && $name !== '__construct' && !$hasOverride) {
            return [$this->error("Method {$class->getDisplayName()}::{$name}() overrides {$parent}::{$name}() but has no #[\\Override] attribute.", 'missing')];
        }

        // the overridden method explains it, a docblock only keeps tags for PHPStan (e.g. @param, @return)
        if ($hasOverride) {
            if ($docComment !== null && \preg_match('~@inheritDoc~i', $docComment) === 1) {
                return [$this->error("Method {$class->getDisplayName()}::{$name}() has #[\\Override], remove the @inheritDoc docblock.", 'inheritDoc')];
            }
            if ($docComment !== null && $this->hasDescription($docComment)) {
                return [$this->error("Method {$class->getDisplayName()}::{$name}() has #[\\Override], remove the explanation from the docblock (keep only tags PHPStan needs).", 'description')];
            }

            return [];
        }

        // magic methods that override nothing cannot have #[\Override], PHP fixes their purpose (`@inheritDoc` is fine)
        if (\str_starts_with($name, '__')) {
            return [];
        }
        if ($docComment === null || !$this->hasDescription($docComment)) {
            return [$this->error("Method {$class->getDisplayName()}::{$name}() needs a docblock that explains it (or #[\\Override] if it overrides a method).", 'docblock')];
        }

        return [];
    }

    /**
     * Returns the name of the parent class, interface or trait whose method is overridden or implemented, null if none.
     */
    private function overriddenClass(ClassReflection $class, string $name): ?string
    {
        foreach ($class->getParents() as $parent) {
            if ($parent->hasNativeMethod($name) && !$parent->getNativeMethod($name)->isPrivate()) {
                return $parent->getDisplayName();
            }
        }
        foreach ($class->getInterfaces() as $interface) {
            if ($interface->hasNativeMethod($name)) {
                return $interface->getDisplayName();
            }
        }
        foreach ($class->getTraits(true) as $trait) {
            if ($trait->hasNativeMethod($name) && $trait->getNativeMethod($name)->isAbstract()) {
                return $trait->getDisplayName();
            }
        }

        return null;
    }

    /**
     * Tells whether a docblock has a line of text besides the tags (lines that continue a tag, like a multi-line
     * array shape of `@return`, belong to the tag).
     */
    private function hasDescription(string $docComment): bool
    {
        $inTag = false;
        foreach (\explode("\n", $docComment) as $line) {
            $text = \trim(\preg_replace('~^\s*(?:/\*\*|\*/|\*)~', '', $line) ?? '');
            $text = \trim(\preg_replace('~\*/$~', '', $text) ?? '');
            if ($text === '') {
                $inTag = false;
                continue;
            }
            if (\str_starts_with($text, '@') || \str_starts_with($text, '{@')) {
                $inTag = true;
                continue;
            }
            if (!$inTag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds an error of this rule.
     */
    private function error(string $message, string $identifier): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier("yourecom.method.{$identifier}")->build();
    }
}
