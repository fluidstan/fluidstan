<?php

declare(strict_types=1);

namespace FluidStan\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Cast\String_ as StringCast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use TYPO3Fluid\Fluid\Core\Variables\VariableProviderInterface;

/**
 * Detects the template variables a ViewHelper assigns: every `add()` or `addAll()` on a variable
 * provider in the ViewHelper's code, with PHPStan's type of the value. Runs in a PHPStan pass over
 * the ViewHelper classes a project uses and reports each assignment as a message the analyser reads
 * back; it is not a check.
 *
 * The variable name is a constant, or it is traced back to an argument of the ViewHelper through
 * casts, single-argument helpers such as toString() and local variables, which gives the
 * `{argument}` pattern. An assignment that is not reached on every path, inside a condition, after
 * an early return or in a loop, may leave the variable unassigned, which in Fluid means null.
 *
 * @implements Rule<MethodCall>
 */
final class ViewHelperAssignmentRule implements Rule
{
    public const IDENTIFIER = 'fluidstan.viewHelperAssignment';

    /** @var array<string, array<Stmt>> parsed files */
    private array $files = [];

    public function __construct(private readonly Parser $parser) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || !\in_array($node->name->toString(), ['add', 'addAll'], true)) {
            return [];
        }

        if (!(new ObjectType(VariableProviderInterface::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $class = $scope->getClassReflection()?->getName();
        $method = $this->enclosingMethod($scope->getFile(), $node);
        if ($class === null || !$method instanceof ClassMethod) {
            return [];
        }

        $conditional = $this->isConditional($node, $method);
        $args = $node->getArgs();
        $assignments = [];
        if ($node->name->toString() === 'add' && isset($args[0], $args[1])) {
            $name = $this->namePattern($args[0]->value, $method, $scope, $node);
            if ($name !== null) {
                $assignments[$name] = $scope->getType($args[1]->value);
            }
        } elseif (isset($args[0]) && $args[0]->value instanceof Array_) {
            foreach ($args[0]->value->items as $item) {
                if ($item->key instanceof String_) {
                    $assignments[$item->key->value] = $scope->getType($item->value);
                }
            }
        }

        $errors = [];
        foreach ($assignments as $name => $type) {
            $payload = ['class' => $class, 'name' => $name, 'type' => ($conditional ? TypeCombinator::addNull($type) : $type)->describe(VerbosityLevel::precise())];
            $errors[] = RuleErrorBuilder::message((string) json_encode($payload))->identifier(self::IDENTIFIER)->build();
        }

        return $errors;
    }

    /**
     * The name of the assigned variable: a constant, or `{argument}` when it comes from an argument
     * of the ViewHelper, possibly through casts, single-argument helpers and local variables.
     */
    private function namePattern(Expr $expr, ClassMethod $method, Scope $scope, MethodCall $call, int $depth = 0): ?string
    {
        $constants = $scope->getType($expr)->getConstantStrings();
        if (\count($constants) === 1) {
            return $constants[0]->getValue();
        }

        if ($depth > 10) {
            return null;
        }

        if ($expr instanceof StringCast) {
            return $this->namePattern($expr->expr, $method, $scope, $call, $depth + 1);
        }

        if ($expr instanceof Coalesce) {
            return $this->namePattern($expr->left, $method, $scope, $call, $depth + 1);
        }

        if ($expr instanceof StaticCall || $expr instanceof MethodCall || $expr instanceof FuncCall) {
            $arguments = $expr->getArgs();
            return \count($arguments) === 1 && isset($arguments[0]) ? $this->namePattern($arguments[0]->value, $method, $scope, $call, $depth + 1) : null;
        }

        if ($expr instanceof ArrayDimFetch && $expr->dim instanceof String_ && $this->isArgumentsArray($expr->var)) {
            return '{' . $expr->dim->value . '}';
        }

        if ($expr instanceof Variable && \is_string($expr->name)) {
            $assignment = $this->lastAssignmentBefore($expr->name, $method, $call);
            return $assignment instanceof Expr ? $this->namePattern($assignment, $method, $scope, $call, $depth + 1) : null;
        }

        return null;
    }

    /**
     * `$this->arguments` of a ViewHelper instance, or `$arguments` of renderStatic()
     */
    private function isArgumentsArray(Expr $expr): bool
    {
        if ($expr instanceof Variable) {
            return $expr->name === 'arguments';
        }

        return $expr instanceof PropertyFetch && $expr->var instanceof Variable && $expr->var->name === 'this'
            && $expr->name instanceof Identifier && $expr->name->toString() === 'arguments';
    }

    private function lastAssignmentBefore(string $variable, ClassMethod $method, MethodCall $call): ?Expr
    {
        $found = null;
        foreach ((new NodeFinder())->findInstanceOf($method->stmts ?? [], Assign::class) as $assign) {
            if ($assign->var instanceof Variable && $assign->var->name === $variable && $assign->getStartFilePos() < $call->getStartFilePos()) {
                $found = $assign->expr;
            }
        }

        return $found;
    }

    /**
     * Whether the call is reached on every path of the method: a direct statement of the method body
     * with no return or throw before it. Anything nested in a condition, loop or closure, or after a
     * statement that may return, is conditional.
     */
    private function isConditional(MethodCall $call, ClassMethod $method): bool
    {
        $finder = new NodeFinder();
        $position = $call->getStartFilePos();
        $returnSeen = false;
        foreach ($method->stmts ?? [] as $statement) {
            $contains = $finder->findFirst($statement, static fn(Node $node): bool => $node instanceof MethodCall && $node->getStartFilePos() === $position) instanceof Node;
            if ($contains) {
                return $returnSeen || (!$statement instanceof Expression || $statement->expr->getStartFilePos() !== $position);
            }

            if ($finder->findFirst($statement, static fn(Node $node): bool => $node instanceof Return_ || $node instanceof Throw_) instanceof Node) {
                $returnSeen = true;
            }
        }

        return true;
    }

    private function enclosingMethod(string $file, MethodCall $call): ?ClassMethod
    {
        $this->files[$file] ??= $this->parser->parseFile($file);
        foreach ((new NodeFinder())->findInstanceOf($this->files[$file], ClassMethod::class) as $method) {
            if ($method->getStartFilePos() <= $call->getStartFilePos() && $call->getEndFilePos() <= $method->getEndFilePos()) {
                return $method;
            }
        }

        return null;
    }
}
