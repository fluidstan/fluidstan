<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

/**
 * Which template variables a ViewHelper assigns when it is called, and their types. Fluid offers
 * no way to declare that, so it comes from two sources: the "viewHelperAssignments" map in
 * fluidstan.neon, which also covers third-party ViewHelpers, and `@fluidstan-assigns` tags in the
 * ViewHelper's class docblock. The configuration wins over the tags.
 *
 * A variable name is literal, or `{argument}` for the value of that argument at the call site,
 * with the argument's default when the call omits it.
 */
final class ViewHelperAssignments
{
    public const TAG = '@fluidstan-assigns';

    /** @var array<string, array<string, string>> resolved per ViewHelper class */
    private array $cache = [];

    /**
     * @param array<string, array<string, string>> $configured ViewHelper class => variable name or {argument} => PHPDoc type
     */
    public function __construct(private readonly array $configured = []) {}

    /**
     * @return array<string, string> variable name or {argument} => PHPDoc type
     */
    public function forViewHelper(string $viewHelperClass): array
    {
        return $this->cache[$viewHelperClass] ??= array_merge($this->fromDocBlocks($viewHelperClass), $this->fromConfiguration($viewHelperClass));
    }

    /**
     * @return array<string, string>
     */
    private function fromConfiguration(string $viewHelperClass): array
    {
        $assignments = [];
        foreach ($this->configured as $class => $variables) {
            if (is_a($viewHelperClass, $class, true)) {
                $assignments = array_merge($assignments, $variables);
            }
        }

        return $assignments;
    }

    /**
     * The tags of the class and of its parents, which the class inherits.
     *
     * @return array<string, string>
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

            preg_match_all('/^\s*\*\s*' . preg_quote(self::TAG, '/') . '\s+(\S+)\s+(.+?)\s*$/m', $docComment, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $name, $type]) {
                // The class's own tag wins over an inherited one
                $assignments[$name] ??= $type;
            }
        }

        return $assignments;
    }
}
