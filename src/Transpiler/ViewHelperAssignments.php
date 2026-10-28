<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

/**
 * Which template variables a ViewHelper assigns when it is called, their types, and their scope:
 * "template" for the rest of the template, "children" for the ViewHelper's children only with the
 * previous value restored afterwards, "removed" for the children only with the variable gone
 * afterwards, as add() followed by remove() leaves it. Fluid offers no way to
 * declare that, so it comes from three sources: what the detection pass found in the ViewHelper's
 * code, `@fluidstan-assigns` and `@fluidstan-assigns-to-children` tags in the ViewHelper's class
 * docblock, and the "viewHelperAssignments" map in fluidstan.neon, each winning over the previous.
 *
 * A variable name is literal, or `{argument}` for the value of that argument at the call site,
 * with the argument's default when the call omits it.
 */
final class ViewHelperAssignments
{
    public const TAG = '@fluidstan-assigns';

    public const CHILDREN_TAG = '@fluidstan-assigns-to-children';

    public const SCOPE_TEMPLATE = 'template';

    public const SCOPE_CHILDREN = 'children';

    public const SCOPE_REMOVED = 'removed';

    /** @var array<string, array<string, array{type: string, scope: string}>> resolved per ViewHelper class */
    private array $cache = [];

    /**
     * @param array<string, array<string, array{type: string, scope: string}>> $configured ViewHelper class => variable name or {argument} => assignment
     * @param array<string, array<string, array{type: string, scope: string}>> $detected the same, found in the ViewHelpers' code
     */
    public function __construct(
        private readonly array $configured = [],
        private readonly array $detected = [],
    ) {}

    /**
     * @return array<string, array{type: string, scope: string}> variable name or {argument} => PHPDoc type and scope
     */
    public function forViewHelper(string $viewHelperClass): array
    {
        return $this->cache[$viewHelperClass] ??= array_merge(
            $this->inherited($viewHelperClass, $this->detected),
            $this->fromDocBlocks($viewHelperClass),
            $this->inherited($viewHelperClass, $this->configured),
        );
    }

    /**
     * The entries for the class and its ancestors, a subclass overriding its parents whatever the
     * order of the map.
     *
     * @param array<string, array<string, array{type: string, scope: string}>> $map
     * @return array<string, array{type: string, scope: string}>
     */
    private function inherited(string $viewHelperClass, array $map): array
    {
        $matching = array_filter($map, static fn(string $class): bool => is_a($viewHelperClass, $class, true), ARRAY_FILTER_USE_KEY);
        // The more of the matching classes a class is a subtype of, the more specific it is
        $specificity = static fn(string $class): int => \count(array_filter(array_keys($matching), static fn(string $other): bool => is_a($class, $other, true)));
        uksort($matching, static fn(string $a, string $b): int => $specificity($a) <=> $specificity($b));

        return array_merge(...array_values($matching));
    }

    /**
     * The tags of the class and of its parents, which the class inherits.
     *
     * @return array<string, array{type: string, scope: string}>
     */
    private function fromDocBlocks(string $viewHelperClass): array
    {
        if (!class_exists($viewHelperClass)) {
            return [];
        }

        $assignments = [];
        for ($class = new \ReflectionClass($viewHelperClass); $class instanceof \ReflectionClass; $class = $class->getParentClass()) {
            $docComment = $class->getDocComment();
            if ($docComment === false) {
                continue;
            }

            preg_match_all('/^\s*\*\s*(' . preg_quote(self::CHILDREN_TAG, '/') . '|' . preg_quote(self::TAG, '/') . ')\s+(\S+)\s+(.+?)\s*$/m', $docComment, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $tag, $name, $type]) {
                // The class's own tag wins over an inherited one
                $assignments[$name] ??= ['type' => $type, 'scope' => $tag === self::CHILDREN_TAG ? self::SCOPE_CHILDREN : self::SCOPE_TEMPLATE];
            }
        }

        return $assignments;
    }
}
