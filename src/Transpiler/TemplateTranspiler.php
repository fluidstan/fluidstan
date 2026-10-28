<?php

declare(strict_types=1);

namespace FluidStan\Transpiler;

use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Diagnostics\DiagnosticCollection;
use FluidStan\Fluid\ComponentTemplate;
use FluidStan\Fluid\NodeLocations;
use FluidStan\Fluid\ParsedTemplate;
use FluidStan\Runtime\F;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use TYPO3Fluid\Fluid\Core\Compiler\TemplateCompiler;
use TYPO3Fluid\Fluid\Core\Component\ComponentAdapter;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Parser\BooleanParser;
use TYPO3Fluid\Fluid\Core\Parser\ParsingState;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ArrayNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\BooleanNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\EscapingNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\CastingExpressionNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\ExpressionNodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\MathExpressionNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\Expression\TernaryExpressionNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NumericNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ObjectAccessorNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\RootNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\TextNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;
use TYPO3Fluid\Fluid\ViewHelpers\AliasViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\ArgumentViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\CommentViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\CycleViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\ElseViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\ForViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\FragmentViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\GroupedForViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\IfViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\LayoutViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\RenderViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\SectionViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\SlotViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\SpacelessViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\ThenViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\VariableViewHelper;

/**
 * Turns a parsed Fluid template into PHP code for PHPStan. Fluid's variable scope becomes PHP
 * scope: component arguments are function parameters, f:for is a foreach, f:variable an
 * assignment. Everything dynamic is delegated to FluidStan\Runtime\F, whose semantics the
 * PHPStan extension implements.
 */
final class TemplateTranspiler
{
    private NodeLocations $locations;

    private DiagnosticCollection $diagnostics;

    private string $file = '';

    private int $currentLine = 1;

    /** Scoped blocks emitted so far, which numbers their backup variables */
    private int $scopes = 0;

    /** Whether a section is being emitted; its closure is a scope of its own */
    private bool $inSection = false;

    /** @var array<string, true> */
    private array $referencedRoots = [];

    private ?ParsedTemplate $template = null;

    /**
     * Fluid's ViewHelpers that assign template variables and are turned into PHP constructs with
     * their exact types: a foreach, an assignment, a scoped block. Detection skips them.
     *
     * @var list<class-string>
     */
    public const ASSIGNING_VIEW_HELPERS = [
        ForViewHelper::class,
        GroupedForViewHelper::class,
        CycleViewHelper::class,
        VariableViewHelper::class,
        AliasViewHelper::class,
    ];

    public function __construct(
        private readonly TypeMapper $typeMapper = new TypeMapper(),
        private readonly Annotations $annotations = new Annotations(),
        private readonly ViewHelperAssignments $assignments = new ViewHelperAssignments(),
    ) {
        $this->locations = new NodeLocations();
        $this->diagnostics = new DiagnosticCollection();
    }

    public function transpile(ParsedTemplate $template): GeneratedFile
    {
        $state = $template->parsingState;
        if (!$state instanceof ParsingState) {
            throw new \LogicException('Only successfully parsed templates can be transpiled.');
        }

        $this->template = $template;
        $this->locations = $template->locations;
        $this->diagnostics = new DiagnosticCollection();
        $this->file = $template->file;
        $this->currentLine = 1;
        $this->scopes = 0;
        $this->inSection = false;
        $this->referencedRoots = [];

        $root = $state->getRootNode();
        $this->collectReferencedRoots($root);
        $annotations = $this->annotations->parse($template->source);
        $argumentDefinitions = $state->getArgumentDefinitions();
        // A component of a collection may declare no arguments at all
        $isComponent = $argumentDefinitions !== [] || $template->component instanceof ComponentTemplate;

        $preamble = [];
        if ($isComponent) {
            // "settings" is passed into every component scope by Fluid
            if (isset($this->referencedRoots['settings']) && !isset($argumentDefinitions['settings']) && !isset($annotations['settings'])) {
                $preamble[] = $this->mark($this->assignVariable('settings', $this->call('variable', [$this->string('settings')])), 1);
            }

        } else {
            foreach (array_keys($this->referencedRoots) as $name) {
                if (!isset($annotations[$name])) {
                    $preamble[] = $this->mark($this->assignVariable($name, $this->call('variable', [$this->string($name)])), 1);
                }
            }
        }

        foreach ($annotations as $name => ['type' => $type, 'line' => $line]) {
            $statement = $this->assignVariable($name, $this->call('variable', [$this->string($name)]));
            $statement->setDocComment(new Doc(\sprintf('/** @var %s $%s */', $this->docType($type), VariableNames::php($name))));
            $preamble[] = $this->mark($statement, $line);
        }

        $body = array_merge($preamble, $this->emitBlock($root->getChildNodes()));
        if ($template->component instanceof ComponentTemplate) {
            $assigned = array_fill_keys(array_map(VariableNames::php(...), array_keys($argumentDefinitions)), true);
            $body = (new AdditionalVariableRouter($template->component, $assigned))->route($body);
        }

        $function = $this->buildFunction($template, $argumentDefinitions, $body);
        $function = $this->mark($function, $this->firstArgumentLine($root));

        $statements = [
            new Declare_([new DeclareItem('strict_types', $this->int(1))]),
            $function,
        ];
        $code = (new Standard())->prettyPrintFile($statements);
        $code = preg_replace('/^<\?php/', "<?php\n// Generated by FluidStan from " . str_replace(["\r", "\n"], ' ', $template->file), $code, 1) ?? $code;

        return new GeneratedFile($template->file, $code, $this->diagnostics);
    }

    /**
     * @param array<string, ArgumentDefinition> $argumentDefinitions
     * @param list<Stmt> $body
     */
    private function buildFunction(ParsedTemplate $template, array $argumentDefinitions, array $body): Function_
    {
        $params = [];
        $docLines = [];
        $optional = [];
        foreach ($argumentDefinitions as $name => $definition) {
            $phpName = VariableNames::php($name);
            $nullable = !$definition->isRequired() && $definition->getDefaultValue() === null;
            $docLines[] = \sprintf(' * @param %s $%s', $this->docType($this->typeMapper->parameterType($definition->getType(), $nullable)), $phpName);
            $param = new Param(new Variable($phpName));
            if ($definition->isRequired()) {
                $params[] = $param;
            } else {
                $param->default = $this->literal($definition->getDefaultValue());
                $optional[] = $param;
            }
        }

        $function = new Function_(
            'fluidstan_template_' . md5($template->file),
            ['params' => array_merge($params, $optional), 'stmts' => $body, 'returnType' => new Identifier('void')],
        );
        if ($docLines !== []) {
            $function->setDocComment(new Doc("/**\n" . implode("\n", $docLines) . "\n */"));
        }

        return $function;
    }

    /**
     * @param array<NodeInterface> $nodes
     * @param bool $raw true for the single child of a ViewHelper tag, which is not cast to string
     * @return list<Stmt>
     */
    private function emitBlock(array $nodes, bool $raw = false): array
    {
        $statements = [];
        foreach ($nodes as $node) {
            foreach ($this->emitStatements($node, $raw) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @return list<Stmt>
     */
    private function emitStatements(NodeInterface $node, bool $raw = false): array
    {
        if ($node instanceof EscapingNode) {
            $node = $node->getNode();
        }

        if ($node instanceof TextNode) {
            return [];
        }

        $line = $this->lineOf($node);
        $this->currentLine = $line;

        if ($node instanceof ViewHelperNode) {
            $class = $node->getViewHelperClassName();
            return match (true) {
                is_a($class, CommentViewHelper::class, true),
                is_a($class, LayoutViewHelper::class, true),
                is_a($class, ArgumentViewHelper::class, true) => [],
                is_a($class, IfViewHelper::class, true) => $this->emitIf($node),
                is_a($class, ForViewHelper::class, true) => $this->emitFor($node),
                is_a($class, GroupedForViewHelper::class, true) => $this->emitGroupedFor($node),
                is_a($class, CycleViewHelper::class, true) => $this->emitCycle($node),
                is_a($class, VariableViewHelper::class, true) => $this->emitVariable($node),
                is_a($class, AliasViewHelper::class, true) => $this->emitAlias($node),
                is_a($class, SectionViewHelper::class, true) => $this->emitSection($node),
                is_a($class, ThenViewHelper::class, true),
                is_a($class, ElseViewHelper::class, true),
                is_a($class, SpacelessViewHelper::class, true) => $this->emitBlock($node->getChildNodes()),
                is_a($class, ComponentAdapter::class, true) => $this->emitComponentStatements($node),
                is_a($class, RenderViewHelper::class, true),
                is_a($class, SlotViewHelper::class, true) => [$this->mark($this->output($this->viewHelperNodeExpr($node)), $line)],
                default => $this->emitGenericViewHelperStatements($node),
            };
        }

        if ($node instanceof RootNode) {
            return $this->emitBlock($node->getChildNodes());
        }

        $expr = $this->expr($node);
        return [$this->mark($raw ? new Expression($this->call('child', [$expr])) : $this->output($expr), $line)];
    }

    /**
     * @return list<Stmt>
     */
    private function emitIf(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $arguments = $node->getArguments();
        $condition = $this->conditionExpr($arguments['condition'] ?? null);

        $thenNodes = [];
        $elseNodes = [];
        $hasThenTag = false;
        foreach ($node->getChildNodes() as $child) {
            if ($child instanceof ViewHelperNode && is_a($child->getViewHelperClassName(), ThenViewHelper::class, true)) {
                $hasThenTag = true;
            }
        }

        foreach ($node->getChildNodes() as $child) {
            if ($child instanceof ViewHelperNode && is_a($child->getViewHelperClassName(), ThenViewHelper::class, true)) {
                $thenNodes = array_merge($thenNodes, $child->getChildNodes());
            } elseif ($child instanceof ViewHelperNode && is_a($child->getViewHelperClassName(), ElseViewHelper::class, true)) {
                $elseNodes[] = $child;
            } elseif (!$hasThenTag) {
                $thenNodes[] = $child;
            }
        }

        $thenStatements = isset($arguments['then'])
            ? [$this->mark($this->output($this->expr($arguments['then'])), $line)]
            : $this->emitBlock($thenNodes);
        $elseIfs = [];
        $else = null;
        foreach ($elseNodes as $elseNode) {
            $elseArguments = $elseNode->getArguments();
            $elseLine = $this->lineOf($elseNode);
            $statements = $this->emitBlock($elseNode->getChildNodes());
            if (isset($elseArguments['if'])) {
                $elseIfs[] = $this->mark(new ElseIf_($this->conditionExpr($elseArguments['if']), $statements), $elseLine);
            } else {
                $else = $this->mark(new Else_($statements), $elseLine);
            }
        }

        if (!$else instanceof Node && isset($arguments['else'])) {
            $else = new Else_([$this->mark($this->output($this->expr($arguments['else'])), $line)]);
        }

        return [$this->mark(new If_($condition, ['stmts' => $thenStatements, 'elseifs' => $elseIfs, 'else' => $else]), $line)];
    }

    /**
     * @return list<Stmt>
     */
    private function emitFor(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $arguments = $node->getArguments();
        $each = $this->call('each', [$this->expr($arguments['each'] ?? null)]);
        $as = $this->constantString($arguments['as'] ?? null) ?? '__item';
        $key = $this->constantString($arguments['key'] ?? null);
        $iteration = $this->constantString($arguments['iteration'] ?? null);

        $statements = [];
        if ($iteration !== null) {
            $statements[] = $this->mark($this->loopAlias($this->assignVariable($iteration, $this->call('iteration', []))), $line);
        }

        $statements = array_merge($statements, $this->emitBlock($node->getChildNodes()));
        $foreach = new Foreach_($each, $this->variable($as), [
            'keyVar' => $key === null ? null : $this->variable($key),
            'stmts' => $statements,
        ]);
        return [$this->mark($foreach, $line)];
    }

    /**
     * @return list<Stmt>
     */
    private function emitGroupedFor(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $arguments = $node->getArguments();
        $each = $this->expr($arguments['each'] ?? null);
        $as = $this->constantString($arguments['as'] ?? null) ?? '__group';
        $groupKey = $this->constantString($arguments['groupKey'] ?? null) ?? 'groupKey';

        // The group is a slice of the input; its elements have the element type of the input.
        $statements = [
            $this->mark($this->loopAlias($this->assignVariable($groupKey, $this->call('valueOf', [$this->call('each', [$each])]))), $line),
            $this->mark($this->loopAlias($this->assignVariable($as, $this->call('each', [$each]))), $line),
        ];
        $statements = array_merge($statements, $this->emitBlock($node->getChildNodes()));
        return [$this->mark(new Foreach_($this->call('each', [$each]), $this->variable('__grouped'), ['stmts' => $statements]), $line)];
    }

    /**
     * @return list<Stmt>
     */
    private function emitCycle(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $arguments = $node->getArguments();
        $as = $this->constantString($arguments['as'] ?? null) ?? '__cycle';
        $statements = [$this->mark($this->assignVariable($as, $this->call('valueOf', [$this->expr($arguments['values'] ?? null)])), $line)];
        return $this->scopedBlock([$as], $statements, $this->emitBlock($node->getChildNodes()), $line);
    }

    /**
     * An argument value without the wrapping Fluid adds: escaping, and a root node around a single node
     */
    private function unwrap(mixed $value): mixed
    {
        if ($value instanceof EscapingNode) {
            return $this->unwrap($value->getNode());
        }

        if ($value instanceof RootNode && \count($value->getChildNodes()) === 1) {
            $children = $value->getChildNodes();

            return $this->unwrap(reset($children));
        }

        return $value;
    }

    /**
     * Statements with variables that exist for them only, as Fluid's ScopedVariableProvider gives
     * them to the children of f:alias, f:cycle and scoping ViewHelpers. A PHP block has no scope of
     * its own, so the previous values are backed up before it and restored after it. In a component
     * the AdditionalVariableRouter does that, since it also keeps track of which variables exist;
     * everywhere else, in a plain template and in a section, every variable that is read has a value
     * from the preamble, and that is what is restored.
     *
     * @param list<string> $names Fluid names of the scoped variables
     * @param list<Stmt> $assignments
     * @param list<Stmt> $children
     * @return list<Stmt>
     */
    private function scopedBlock(array $names, array $assignments, array $children, int $line): array
    {
        foreach ($assignments as $assignment) {
            if ($assignment instanceof Expression) {
                $this->loopAlias($assignment);
            }
        }

        $block = $this->mark(new Block([...$assignments, ...$children], [AdditionalVariableRouter::SCOPE => array_map(VariableNames::php(...), $names)]), $line);
        if ($this->template?->component instanceof ComponentTemplate && !$this->inSection) {
            return [$block];
        }

        $pre = [];
        $post = [];
        $restored = array_filter($names, fn(string $name): bool => isset($this->referencedRoots[$name]));
        foreach ($restored as $name) {
            $backup = $this->backupVariable($name);
            $pre[] = $this->mark(new Expression(new Assign($backup, $this->variable($name))), $line);
            $post[] = $this->mark(new Expression(new Assign($this->variable($name), $backup)), $line);
        }

        return [...$pre, $block, ...$post];
    }

    /**
     * A variable for the backup of a scoped variable, numbered consecutively and skipping every name
     * the template uses as a variable of its own. Fluid 5 rejects identifiers starting with "_", so
     * there the names are free anyway; Fluid 4 does not.
     */
    private function backupVariable(string $name): Variable
    {
        $used = array_map(VariableNames::php(...), array_keys($this->referencedRoots));
        do {
            $backup = \sprintf('__scope%d_%s', ++$this->scopes, VariableNames::php($name));
        } while (\in_array($backup, $used, true));

        return new Variable($backup);
    }

    /**
     * @return list<Stmt>
     */
    private function emitVariable(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $arguments = $node->getArguments();
        $name = $this->constantString($arguments['name'] ?? null);
        if ($name === null) {
            return [];
        }

        if (\array_key_exists('value', $arguments)) {
            return [$this->mark($this->assignVariable($name, $this->expr($arguments['value'])), $line)];
        }

        $children = $node->getChildNodes();
        if ($this->allExpressible($children)) {
            return [$this->mark($this->assignVariable($name, $this->concatExpr($children)), $line)];
        }

        $statements = $this->emitBlock($children);
        $statements[] = $this->mark($this->assignVariable($name, $this->call('captured', [])), $line);
        return $statements;
    }

    /**
     * @return list<Stmt>
     */
    private function emitAlias(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $arguments = $node->getArguments();
        $statements = [];
        $names = [];
        $map = $this->unwrap($arguments['map'] ?? null);
        if ($map instanceof ArrayNode) {
            foreach ($this->arrayNodeItems($map) as $name => $value) {
                $names[] = (string) $name;
                $statements[] = $this->mark($this->assignVariable((string) $name, $this->expr($value)), $line);
            }
        }

        return $this->scopedBlock($names, $statements, $this->emitBlock($node->getChildNodes()), $line);
    }

    /**
     * Sections become closures. Their variables come from the f:render call site, which can be in
     * another template, so every root variable used inside is treated as mixed.
     *
     * @return list<Stmt>
     */
    private function emitSection(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $name = $this->constantString($node->getArguments()['name'] ?? null) ?? 'section';
        $outerRoots = $this->referencedRoots;
        $this->referencedRoots = [];
        foreach ($node->getChildNodes() as $child) {
            $this->collectReferencedRoots($child);
        }

        $preamble = [];
        foreach (array_keys($this->referencedRoots) as $root) {
            $preamble[] = $this->mark($this->assignVariable($root, $this->call('variable', [$this->string($root)])), $line);
        }

        // Nested sections happen; the outer one is still a section afterwards
        $wasInSection = $this->inSection;
        $this->inSection = true;
        $closure = new Closure([
            'stmts' => array_merge($preamble, $this->emitBlock($node->getChildNodes())),
            'returnType' => new Identifier('void'),
        ]);
        $this->inSection = $wasInSection;
        $this->referencedRoots = $outerRoots;
        return [$this->mark(new Expression(new Assign($this->variable('__section_' . $name), $closure)), $line)];
    }

    /**
     * @return list<Stmt>
     */
    private function emitGenericViewHelperStatements(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        [$scoped, $scopedNames, $persistent, $removed] = $this->assignedByViewHelper($node);
        // Fluid casts children to string only when there are several of them
        $children = $this->emitBlock($node->getChildNodes(), \count($node->getChildNodes()) === 1);
        $statements = $scoped === [] ? $children : $this->scopedBlock($scopedNames, $scoped, $children, $line);
        $statements[] = $this->mark($this->output($this->viewHelperExpr($node, false)), $line);
        foreach ($removed as $name) {
            // The ViewHelper removed the variable: it is gone, whatever it was before
            $statements[] = $this->mark($this->assignVariable($name, $this->null()), $line);
        }

        return array_merge($statements, $persistent);
    }

    /**
     * The variables the ViewHelper assigns, detected in its code or declared in the configuration or
     * its docblock: those its children see, with their names, those the rest of the template sees
     * after the call, and the names of those removed after the call. A name given by an argument
     * whose value is not a constant string cannot be resolved and is skipped.
     *
     * @return array{list<Stmt>, list<string>, list<Stmt>, list<string>}
     */
    private function assignedByViewHelper(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $scoped = [];
        $scopedNames = [];
        $persistent = [];
        $removed = [];
        foreach ($this->assignments->forViewHelper($node->getViewHelperClassName()) as $name => ['type' => $type, 'scope' => $scope]) {
            if (preg_match('/^\{(\w+)\}$/', $name, $matches) === 1) {
                $argument = $matches[1];
                $arguments = $node->getArguments();
                $definitions = $node->getArgumentDefinitions();
                $name = match (true) {
                    \array_key_exists($argument, $arguments) => $this->constantString($arguments[$argument]),
                    isset($definitions[$argument]) => $this->constantString($definitions[$argument]->getDefaultValue()),
                    default => null,
                };
                if ($name === null) {
                    continue;
                }
            }

            $statement = $this->assignVariable($name, $this->call('variable', [$this->string($name)]));
            $statement->setDocComment(new Doc(\sprintf('/** @var %s $%s */', $this->docType($type), VariableNames::php($name))));
            if ($scope === ViewHelperAssignments::SCOPE_TEMPLATE) {
                $persistent[] = $this->mark($statement, $line);
                continue;
            }

            $scoped[] = $this->mark($statement, $line);
            $scopedNames[] = $name;
            if ($scope === ViewHelperAssignments::SCOPE_REMOVED) {
                $removed[] = $name;
            }
        }

        return [$scoped, $scopedNames, $persistent, $removed];
    }

    /**
     * @return list<Stmt>
     */
    private function emitComponentStatements(ViewHelperNode $node): array
    {
        $line = $this->lineOf($node);
        $statements = [];
        foreach ($this->componentSlotNodes($node) as $slotNodes) {
            $statements = array_merge($statements, $this->emitBlock($slotNodes));
        }

        $statements[] = $this->mark($this->output($this->componentExpr($node, false)), $line);
        return $statements;
    }

    private function viewHelperExpr(ViewHelperNode $node, bool $withChildren): StaticCall
    {
        $name = $node->getNamespace() . ':' . $node->getName();
        $arguments = [
            new Arg(new ClassConstFetch(new FullyQualified($node->getViewHelperClassName()), 'class')),
            new Arg($this->argumentsArray($node, $name)),
            new Arg($this->string($name)),
        ];
        if ($withChildren && $node->getChildNodes() !== []) {
            $arguments[] = new Arg($this->childrenArray($node->getChildNodes()));
        }

        return new StaticCall(new FullyQualified(F::class), 'viewHelper', $arguments);
    }

    private function componentExpr(ViewHelperNode $node, bool $withChildren): StaticCall
    {
        $name = $node->getNamespace() . ':' . $node->getName();
        $this->validateComponentSlots($node, $name);
        $arguments = [
            new Arg($this->string($name)),
            new Arg($this->argumentsArray($node, $name)),
        ];
        if ($withChildren && $node->getChildNodes() !== []) {
            $arguments[] = new Arg($this->childrenArray($node->getChildNodes()));
        }

        return new StaticCall(new FullyQualified(F::class), 'component', $arguments);
    }

    private function argumentsArray(ViewHelperNode $node, string $viewHelperName): Array_
    {
        $definitions = $node->getArgumentDefinitions();
        $items = [];
        foreach ($node->getArguments() as $argumentName => $value) {
            $definition = $definitions[$argumentName] ?? null;
            $type = $definition === null ? 'mixed' : $this->typeMapper->acceptedType($definition->getType());
            if ($definition !== null && $definition->isBooleanType()) {
                $type = 'bool';
            }

            $items[] = new ArrayItem(
                $this->call('arg', [
                    $this->string((string) $argumentName),
                    $this->expr($value),
                    $this->string($type),
                    new ConstFetch(new Name($definition !== null && $definition->isRequired() ? 'true' : 'false')),
                    $this->string($viewHelperName),
                ]),
                $this->string((string) $argumentName),
            );
        }

        return new Array_($items);
    }

    /**
     * @param array<NodeInterface> $children
     */
    private function childrenArray(array $children): Array_
    {
        $items = [];
        foreach ($children as $child) {
            if ($child instanceof EscapingNode) {
                $child = $child->getNode();
            }

            if ($child instanceof TextNode) {
                continue;
            }

            if (!$this->isExpressible($child)) {
                continue;
            }

            $items[] = new ArrayItem($this->expr($child));
        }

        return new Array_($items);
    }

    /**
     * @return array<string, array<NodeInterface>> slot name => nodes
     */
    private function componentSlotNodes(ViewHelperNode $node): array
    {
        $slots = [];
        foreach ($node->getChildNodes() as $child) {
            if ($child instanceof ViewHelperNode && is_a($child->getViewHelperClassName(), FragmentViewHelper::class, true)) {
                $slotName = $this->constantString($child->getArguments()['name'] ?? null) ?? SlotViewHelper::DEFAULT_SLOT;
                $slots[$slotName] = array_merge($slots[$slotName] ?? [], $child->getChildNodes());
            }
        }

        if ($slots === [] && $node->getChildNodes() !== []) {
            $slots[SlotViewHelper::DEFAULT_SLOT] = $node->getChildNodes();
        }

        return $slots;
    }

    private function validateComponentSlots(ViewHelperNode $node, string $name): void
    {
        $delegate = $node->getResolverDelegate();
        if (!$delegate instanceof ComponentDefinitionProviderInterface) {
            return;
        }

        try {
            $definition = $delegate->getComponentDefinition($node->getName());
        } catch (\Throwable $throwable) {
            $this->diagnostics->add(new Diagnostic($this->file, $this->lineOf($node), \sprintf('Component %s could not be loaded: %s', $name, $throwable->getMessage()), 'fluid.component.unresolvable'));
            return;
        }

        $available = $definition->getAvailableSlots();
        foreach (array_keys($this->componentSlotNodes($node)) as $slotName) {
            if (!\in_array($slotName, $available, true)) {
                $message = $slotName === SlotViewHelper::DEFAULT_SLOT
                    ? \sprintf('Component %s has no default slot, but content was passed.', $name)
                    : \sprintf('Component %s has no slot "%s".', $name, $slotName);
                $this->diagnostics->add(new Diagnostic($this->file, $this->lineOf($node), $message, 'fluid.component.unknownSlot'));
            }
        }
    }

    private function renderExpr(ViewHelperNode $node): StaticCall
    {
        $arguments = $node->getArguments();
        $section = $this->constantString($arguments['section'] ?? null);
        $partial = $this->constantString($arguments['partial'] ?? null);
        $optional = ($arguments['optional'] ?? null) instanceof BooleanNode;
        if ($section !== null && $partial === null && !isset($arguments['delegate']) && !$optional && !isset($arguments['partial'])) {
            $sections = $this->definedSections();
            // A layout renders the sections of the template using it; those are unknown here. A layout is
            // recognised by having neither an f:layout declaration, nor sections, nor arguments of its own.
            $state = $this->template?->parsingState;
            $isLayout = $sections === [] && $state instanceof ParsingState && !$state->hasLayout() && $state->getArgumentDefinitions() === [];
            if ($sections !== null && !$isLayout && !\in_array($section, $sections, true)) {
                $this->diagnostics->add(new Diagnostic($this->file, $this->lineOf($node), \sprintf('Section "%s" is not defined in this template.', $section), 'fluid.render.unknownSection'));
            }
        }

        return $this->call('render', [
            isset($arguments['section']) ? $this->expr($arguments['section']) : $this->null(),
            isset($arguments['partial']) ? $this->expr($arguments['partial']) : $this->null(),
            isset($arguments['arguments']) ? $this->expr($arguments['arguments']) : new Array_([]),
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function definedSections(): ?array
    {
        $state = $this->template?->parsingState;
        if (!$state instanceof ParsingState) {
            return null;
        }

        $container = $state->getVariableContainer();
        if (!$container->exists(TemplateCompiler::SECTIONS_VARIABLE)) {
            return [];
        }

        $sections = $container->get(TemplateCompiler::SECTIONS_VARIABLE);
        return \is_array($sections) ? array_map(strval(...), array_keys($sections)) : null;
    }

    private function expr(mixed $value): Expr
    {
        if ($value instanceof EscapingNode) {
            $value = $value->getNode();
        }

        if (!$value instanceof NodeInterface) {
            return $this->literal($value);
        }

        if ($value instanceof TextNode) {
            return $this->string($value->getText());
        }

        if ($value instanceof NumericNode) {
            return $this->literal($value->getValue());
        }

        if ($value instanceof RootNode) {
            return $this->concatExpr($value->getChildNodes());
        }

        if ($value instanceof ObjectAccessorNode) {
            return $this->accessorExpr($value->getObjectPath());
        }

        if ($value instanceof ArrayNode) {
            $items = [];
            foreach ($this->arrayNodeItems($value) as $key => $item) {
                $items[] = new ArrayItem($this->expr($item), \is_int($key) ? $this->int($key) : $this->string($key));
            }

            return new Array_($items);
        }

        if ($value instanceof BooleanNode) {
            return $this->booleanExpr($value);
        }

        if ($value instanceof TernaryExpressionNode) {
            return $this->ternaryExpr($value);
        }

        if ($value instanceof MathExpressionNode) {
            return $this->mathExpr($value);
        }

        if ($value instanceof CastingExpressionNode) {
            return $this->castingExpr($value);
        }

        if ($value instanceof ExpressionNodeInterface) {
            return $this->call('variable', [$this->string('__expression')]);
        }

        if ($value instanceof ViewHelperNode) {
            return $this->viewHelperNodeExpr($value);
        }

        return $this->call('variable', [$this->string('__unknown')]);
    }

    private function viewHelperNodeExpr(ViewHelperNode $node): Expr
    {
        $class = $node->getViewHelperClassName();
        if (is_a($class, IfViewHelper::class, true)) {
            return $this->ifExpr($node);
        }

        if (is_a($class, RenderViewHelper::class, true)) {
            return $this->renderExpr($node);
        }

        if (is_a($class, SlotViewHelper::class, true)) {
            return $this->call('slot', [$this->string($this->constantString($node->getArguments()['name'] ?? null) ?? SlotViewHelper::DEFAULT_SLOT)]);
        }

        if (is_a($class, CommentViewHelper::class, true) || is_a($class, ArgumentViewHelper::class, true) || is_a($class, LayoutViewHelper::class, true)) {
            return $this->string('');
        }

        if (is_a($class, ThenViewHelper::class, true) || is_a($class, ElseViewHelper::class, true) || is_a($class, SpacelessViewHelper::class, true)) {
            return $this->concatExpr($node->getChildNodes());
        }

        if (is_a($class, ComponentAdapter::class, true)) {
            return $this->componentExpr($node, true);
        }

        if (!$this->isExpressible($node)) {
            return $this->call('captured', []);
        }

        return $this->viewHelperExpr($node, true);
    }

    private function ifExpr(ViewHelperNode $node): Ternary
    {
        $arguments = $node->getArguments();
        $then = isset($arguments['then']) ? $this->expr($arguments['then']) : null;
        $else = isset($arguments['else']) ? $this->expr($arguments['else']) : null;
        $thenNodes = [];
        $elseNodes = [];
        $hasThen = false;
        foreach ($node->getChildNodes() as $child) {
            if ($child instanceof ViewHelperNode && is_a($child->getViewHelperClassName(), ThenViewHelper::class, true)) {
                $hasThen = true;
                $thenNodes = array_merge($thenNodes, $child->getChildNodes());
            } elseif ($child instanceof ViewHelperNode && is_a($child->getViewHelperClassName(), ElseViewHelper::class, true)) {
                $elseNodes = array_merge($elseNodes, $child->getChildNodes());
            }
        }

        if (!$hasThen) {
            $thenNodes = array_values(array_filter($node->getChildNodes(), static fn(NodeInterface $child): bool => !$child instanceof ViewHelperNode || !is_a($child->getViewHelperClassName(), ElseViewHelper::class, true)));
        }

        $then ??= $thenNodes === [] ? $this->null() : $this->concatExpr($thenNodes);
        $else ??= $elseNodes === [] ? $this->null() : $this->concatExpr($elseNodes);
        return new Ternary($this->conditionExpr($arguments['condition'] ?? null), $then, $else);
    }

    private function conditionExpr(mixed $condition): Expr
    {
        if ($condition instanceof BooleanNode) {
            return $this->booleanExpr($condition);
        }

        return $condition === null ? $this->call('condition', []) : $this->call('bool', [$this->expr($condition)]);
    }

    /**
     * The condition as Fluid's BooleanParser compiles it to PHP, with the operands substituted for
     * its placeholders: the same grammar, precedence and operators as at runtime, `&&`, `||` and
     * `!` as PHP operators, comparisons as PHP comparisons, truthiness as `F::bool()`. Should the
     * compiled code not be usable, the operands are checked through `F::condition()` instead.
     */
    private function booleanExpr(BooleanNode $node): Expr
    {
        $stack = $node->getStack();
        $operands = [];
        foreach ($stack as $key => $item) {
            if ($item instanceof NodeInterface && !$item instanceof TextNode) {
                $operands['node' . $key] = $this->expr($item);
            }
        }

        try {
            $code = (new BooleanParser())->compile(BooleanNode::reconcatenateExpression($stack));
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php return ' . $code . ';');
        } catch (\Throwable) {
            $statements = null;
        }

        $return = $statements[0] ?? null;
        if (!$return instanceof Return_ || !$return->expr instanceof Expr) {
            return $this->call('condition', array_values($operands));
        }

        $rewriter = new ConditionCodeRewriter($operands);
        $traverser = new NodeTraverser(new ParentConnectingVisitor(), $rewriter);
        $rewritten = $traverser->traverse([$return->expr])[0] ?? null;
        if (!$rewriter->isComplete() || !$rewritten instanceof Expr) {
            return $this->call('condition', array_values($operands));
        }

        // Fluid converts the result with convertToBoolean() too: a bare literal such as "yes" is true
        return ConditionCodeRewriter::isBoolean($rewritten) ? $rewritten : $this->call('bool', [$rewritten]);
    }

    private function ternaryExpr(TernaryExpressionNode $node): Ternary
    {
        $expression = trim(trim($node->getExpression()), '{}');
        $split = preg_split('/([\?:])/s', $expression);
        $parts = array_map(trim(...), $split === false ? [] : $split);
        $check = $parts[0] ?? '';
        $then = $parts[1] ?? '';
        $else = $parts[2] ?? '';
        if ($then === '') {
            $then = str_starts_with($check, '!') ? $else : ltrim($check, '!');
        }

        $operands = [];
        $withoutStrings = preg_replace('/\'[^\']*\'|"[^"]*"/', ' ', $check) ?? $check;
        if (preg_match_all('/[_a-zA-Z][_a-zA-Z0-9\.]*/', $withoutStrings, $matches) > 0) {
            foreach ($matches[0] as $identifier) {
                if (\in_array(strtolower($identifier), ['true', 'false', 'null'], true)) {
                    continue;
                }

                $operands[] = $this->accessorExpr($identifier);
            }
        }

        return new Ternary($this->call('condition', $operands), $this->valueOrAccessor($then), $this->valueOrAccessor($else));
    }

    private function mathExpr(MathExpressionNode $node): StaticCall
    {
        $expression = trim(trim($node->getExpression()), '{}');
        $operands = [];
        if (preg_match_all('/([+\-*\^\/\%]|[_a-zA-Z0-9\.]+)/s', $expression, $matches) > 0) {
            foreach ($matches[0] as $token) {
                $token = trim($token);
                if ($token === '' || \in_array($token, ['+', '-', '*', '^', '/', '%'], true)) {
                    continue;
                }

                $operands[] = $this->valueOrAccessor($token);
            }
        }

        return $this->call('math', $operands);
    }

    private function castingExpr(CastingExpressionNode $node): StaticCall
    {
        $expression = trim(trim($node->getExpression()), '{}');
        $parts = explode(' as ', $expression, 2);
        $variable = $parts[0];
        $type = $parts[1] ?? 'string';
        return $this->call('cast', [$this->valueOrAccessor(trim($variable)), $this->string(trim($type))]);
    }

    private function valueOrAccessor(string $token): Expr
    {
        $token = trim($token);
        if ($token === '') {
            return $this->null();
        }

        if (is_numeric($token)) {
            return $this->literal(str_contains($token, '.') ? (float) $token : (int) $token);
        }

        if (preg_match('/^(["\']).*\1$/s', $token) === 1) {
            return $this->string(substr($token, 1, -1));
        }

        if (\in_array(strtolower($token), ['true', 'false', 'null'], true)) {
            return new ConstFetch(new Name(strtolower($token)));
        }

        if (preg_match('/^[_a-zA-Z][_a-zA-Z0-9\.]*$/', $token) === 1) {
            return $this->accessorExpr($token);
        }

        return $this->string($token);
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function concatExpr(array $nodes): Expr
    {
        $expressions = [];
        foreach ($nodes as $node) {
            if ($node instanceof EscapingNode) {
                $node = $node->getNode();
            }

            if ($node instanceof TextNode) {
                $expressions[] = $this->string($node->getText());
                continue;
            }

            $expressions[] = $this->expr($node);
        }

        if ($expressions === []) {
            return $this->string('');
        }

        if (\count($expressions) === 1) {
            return $expressions[0];
        }

        return $this->call('concat', $expressions);
    }

    private function accessorExpr(string $path): Expr
    {
        $lower = strtolower($path);
        if ($lower === '_all') {
            return $this->call('all', []);
        }

        if (\in_array($lower, ['true', 'false', 'null'], true)) {
            return new ConstFetch(new Name($lower));
        }

        // Dynamic sub-references: {items.{key}}
        $dynamic = [];
        $path = preg_replace_callback('/\{([^{}]+)\}/', static function (array $matches) use (&$dynamic): string {
            $dynamic[] = $matches[1];
            return "\0" . (\count($dynamic) - 1) . "\0";
        }, $path) ?? $path;

        $segments = explode('.', $path);
        $first = array_shift($segments);
        if ($first === '') {
            return $this->call('variable', [$this->string('__invalid')]);
        }

        $dynamicPath = $this->dynamicPath($first, $dynamic);
        $expr = $dynamicPath === null
            ? $this->variable($first)
            : $this->call('getDynamic', [$this->call('all', []), $this->accessorExpr($dynamicPath)]);
        foreach ($segments as $segment) {
            $dynamicPath = $this->dynamicPath($segment, $dynamic);
            $expr = $dynamicPath === null
                ? $this->call('get', [$expr, $this->string($segment)])
                : $this->call('getDynamic', [$expr, $this->accessorExpr($dynamicPath)]);
        }

        return $expr;
    }

    /**
     * @param list<string> $dynamic
     * @return string|null the inner path of a dynamic segment placeholder
     */
    private function dynamicPath(string $segment, array $dynamic): ?string
    {
        if (preg_match('/^\0(\d+)\0$/', $segment, $matches) === 1) {
            return $dynamic[(int) $matches[1]] ?? null;
        }

        return null;
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function allExpressible(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!$this->isExpressible($node)) {
                return false;
            }
        }

        return true;
    }

    private function isExpressible(NodeInterface $node): bool
    {
        if ($node instanceof EscapingNode) {
            $node = $node->getNode();
        }

        if (!$node instanceof ViewHelperNode) {
            return true;
        }

        $class = $node->getViewHelperClassName();
        foreach ([ForViewHelper::class, GroupedForViewHelper::class, CycleViewHelper::class, VariableViewHelper::class, AliasViewHelper::class, SectionViewHelper::class] as $structural) {
            if (is_a($class, $structural, true)) {
                return false;
            }
        }

        if (is_a($class, IfViewHelper::class, true) || is_a($class, ThenViewHelper::class, true) || is_a($class, ElseViewHelper::class, true) || is_a($class, SpacelessViewHelper::class, true)) {
            return $this->allExpressible($node->getChildNodes());
        }

        return true;
    }

    private function collectReferencedRoots(NodeInterface $node): void
    {
        if ($node instanceof EscapingNode) {
            $node = $node->getNode();
        }

        if ($node instanceof ObjectAccessorNode) {
            $this->addReferencedRootsOfPath($node->getObjectPath());
        } elseif ($node instanceof ExpressionNodeInterface) {
            $expression = trim(trim($node->getExpression()), '{}');
            $withoutStrings = preg_replace('/\'[^\']*\'|"[^"]*"/', ' ', $expression) ?? $expression;
            if (preg_match_all('/[_a-zA-Z][_a-zA-Z0-9\.]*/', $withoutStrings, $matches) > 0) {
                foreach ($matches[0] as $identifier) {
                    if ($identifier === 'as') {
                        continue;
                    }

                    $this->addReferencedRootsOfPath($identifier);
                }
            }
        } elseif ($node instanceof ViewHelperNode) {
            foreach ($node->getArguments() as $argument) {
                if ($argument instanceof NodeInterface) {
                    $this->collectReferencedRoots($argument);
                }
            }
        } elseif ($node instanceof ArrayNode) {
            foreach ($this->arrayNodeItems($node) as $item) {
                if ($item instanceof NodeInterface) {
                    $this->collectReferencedRoots($item);
                }
            }
        } elseif ($node instanceof BooleanNode) {
            foreach ($node->getStack() as $item) {
                if ($item instanceof NodeInterface) {
                    $this->collectReferencedRoots($item);
                }
            }
        }

        foreach ($node->getChildNodes() as $child) {
            $this->collectReferencedRoots($child);
        }
    }

    private function addReferencedRootsOfPath(string $path): void
    {
        if (preg_match_all('/\{([^{}]+)\}/', $path, $matches) > 0) {
            foreach ($matches[1] as $inner) {
                $this->addReferencedRootsOfPath($inner);
            }
        }

        $path = preg_replace('/\{[^{}]+\}/', '', $path) ?? $path;
        $root = explode('.', $path)[0];
        if ($root === '' || \in_array(strtolower($root), ['_all', 'true', 'false', 'null'], true)) {
            return;
        }

        if (preg_match('/^[_a-zA-Z][_a-zA-Z0-9]*$/', $root) !== 1) {
            return;
        }

        $this->referencedRoots[$root] = true;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arrayNodeItems(ArrayNode $node): array
    {
        $property = new \ReflectionProperty(ArrayNode::class, 'internalArray');
        $items = $property->getValue($node);
        return \is_array($items) ? $items : [];
    }

    private function constantString(mixed $value): ?string
    {
        if ($value instanceof EscapingNode) {
            $value = $value->getNode();
        }

        if (\is_string($value)) {
            return $value;
        }

        if ($value instanceof TextNode) {
            return $value->getText();
        }

        if ($value instanceof RootNode && \count($value->getChildNodes()) === 1) {
            $children = $value->getChildNodes();

            return $this->constantString(reset($children));
        }

        if ($value instanceof NumericNode) {
            return (string) $value->getValue();
        }

        return null;
    }

    private function firstArgumentLine(RootNode $root): int
    {
        foreach ($root->getChildNodes() as $child) {
            if ($child instanceof ViewHelperNode && is_a($child->getViewHelperClassName(), ArgumentViewHelper::class, true)) {
                return $this->lineOf($child);
            }
        }

        return 1;
    }

    /**
     * Types come from templates; a comment terminator or line break in them would let template
     * content escape the doc comment and end up as code in the generated file.
     */
    private function docType(string $type): string
    {
        return preg_replace('#\*/|[\r\n]#', '', $type) ?? 'mixed';
    }

    private function lineOf(NodeInterface $node): int
    {
        return $this->locations->line($node) ?? $this->currentLine;
    }

    /**
     * @template T of Node
     * @param T $node
     * @return T
     */
    private function mark(Node $node, int $line): Node
    {
        // The doc comment must stay the last comment, otherwise php-parser no longer treats it as such
        $node->setAttribute('comments', [new Comment(GeneratedFile::LINE_MARKER . $line), ...$node->getComments()]);
        return $node;
    }

    /**
     * @param list<Expr> $arguments
     */
    private function call(string $method, array $arguments): StaticCall
    {
        return new StaticCall(new FullyQualified(F::class), $method, array_map(static fn(Expr $e): Arg => new Arg($e), $arguments));
    }

    private function output(Expr $expr): Expression
    {
        return new Expression($this->call('output', [$expr]));
    }

    private function assignVariable(string $fluidName, Expr $value): Expression
    {
        return new Expression(new Assign($this->variable($fluidName), $value));
    }

    /**
     * Marks the assignment of a loop variable Fluid restores when the loop ends.
     */
    private function loopAlias(Expression $statement): Expression
    {
        $statement->expr->setAttribute(AdditionalVariableRouter::LOOP_ALIAS, true);
        return $statement;
    }

    private function variable(string $fluidName): Variable
    {
        // The PHP name is sanitized; the Fluid name is kept for messages and lookups
        return new Variable(VariableNames::php($fluidName), [AdditionalVariableRouter::FLUID_NAME => $fluidName]);
    }

    private function literal(mixed $value): Expr
    {
        return match (true) {
            $value === null => $this->null(),
            \is_bool($value) => new ConstFetch(new Name($value ? 'true' : 'false')),
            \is_int($value) => $this->int($value),
            \is_float($value) => new Float_($value),
            \is_string($value) => $this->string($value),
            \is_array($value) => new Array_(array_map(
                fn($v, $k): ArrayItem => new ArrayItem($this->literal($v), \is_int($k) ? $this->int($k) : $this->string($k)),
                $value,
                array_keys($value),
            )),
            $value instanceof NodeInterface => $this->expr($value),
            default => $this->call('variable', [$this->string('__literal')]),
        };
    }

    private function string(string $value): String_
    {
        return new String_($value);
    }

    private function int(int $value): Int_
    {
        return new Int_($value);
    }

    private function null(): ConstFetch
    {
        return new ConstFetch(new Name('null'));
    }
}
