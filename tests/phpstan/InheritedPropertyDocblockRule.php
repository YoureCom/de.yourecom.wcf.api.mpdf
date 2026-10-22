<?php

namespace yourecom\standard\phpstan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * YoureCom Standard: a property that redeclares a property of a parent class (`$className`, `$requireACP`, …) only gets
 * `/** @inheritDoc *\/`, the parent explains it; tags that PHPStan needs (e.g. `@var` with a narrower type) may follow
 * after an empty line (Marcel 22.10.2026).
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * @author  Marcel Beckers
 * @license YoureCom License - Commercial (YCLC)  <https://www.yourecom.de/shop/licence-commercial/>
 * @implements Rule<ClassPropertyNode>
 */
final class InheritedPropertyDocblockRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isPromoted() || $node->isDeclaredInTrait()) {
            return [];
        }

        $name = $node->getName();
        $class = $node->getClassReflection();
        $parent = $this->declaringParent($class, $name);
        if ($parent === null) {
            return [];
        }

        $docComment = $node->getPhpDoc();
        if ($docComment !== null && $this->hasDescription($docComment)) {
            return [$this->error("Property {$class->getName()}::\${$name} redeclares {$parent}::\${$name}, replace the explanation with @inheritDoc (keep only tags PHPStan needs).", 'description')];
        }
        if ($docComment === null || \preg_match('~@inheritDoc\b~i', $docComment) !== 1) {
            return [$this->error("Property {$class->getName()}::\${$name} redeclares {$parent}::\${$name}, give it the docblock /** @inheritDoc */.", 'inheritDoc')];
        }

        return [];
    }

    /**
     * Returns the name of the parent class that declares the property (not private), null if none.
     */
    private function declaringParent(ClassReflection $class, string $name): ?string
    {
        foreach ($class->getParents() as $parent) {
            if ($parent->hasNativeProperty($name) && !$parent->getNativeProperty($name)->isPrivate()) {
                return $parent->getName();
            }
        }

        return null;
    }

    /**
     * Tells whether a docblock has a line of text besides the tags (lines that continue a tag belong to the tag).
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
        return RuleErrorBuilder::message($message)->identifier("yourecom.property.{$identifier}")->build();
    }
}
