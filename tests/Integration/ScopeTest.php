<?php

declare(strict_types=1);

namespace FluidStan\Tests\Integration;

use FluidStan\Analyser;
use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Tests\Fixtures\Scope\Local;
use FluidStan\Tests\Fixtures\Scope\Provided;
use FluidStan\Tests\Fixtures\Scope\ScopeCollection;
use FluidStan\Tests\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Which value a component variable has at each point of a template: the one the collection
 * provides, a locally assigned one, or none. Every case is rendered with real Fluid in all input
 * variants to establish the truth, and FluidStan's analysis must agree.
 *
 * A read is written as `A:[{v.fromCollection}|{v.fromLocal}]`. Fluid prints "collection" or
 * "local" depending on the object in scope, nothing for a missing variable. FluidStan reports the
 * member that does not exist on the type it assumes, which reveals that assumption:
 * "fromLocal" missing on Provided means it assumes the collection's value, "fromCollection"
 * missing on Local the local one, both for a union, and "undefined" for no value at all.
 */
final class ScopeTest extends TestCase
{
    use TemporaryDirectory;

    private const CASES = __DIR__ . '/../Fixtures/Scope/Cases';

    /** The value Fluid used: C = the collection's, L = a local one, N = none */
    private const COLLECTION = 'C';

    private const LOCAL = 'L';

    private const NONE = 'N';

    /** @var array<string, array<string, list<string>>>|null case => label => values FluidStan assumes */
    private static ?array $assumptions = null;

    /**
     * @return iterable<string, array{string, array<string, list<string>>, array<string, list<string>>}>
     */
    public static function cases(): iterable
    {
        $c = self::COLLECTION;
        $l = self::LOCAL;
        $n = self::NONE;

        yield 'provided variable' => ['Plain', ['A' => [$c]], []];
        yield 'assigned in then: else and after see the collection too' => ['AssignThen', ['A' => [$l], 'B' => [$c], 'C' => [$c, $l]], []];
        yield 'assigned in both branches' => ['AssignBoth', ['A' => [$l]], []];
        yield 'assigned in else only' => ['AssignElseOnly', ['A' => [$c], 'B' => [$c, $l]], []];
        yield 'assigned in else-if only' => ['AssignElseIf', ['A' => [$c], 'B' => [$l], 'C' => [$c, $l]], []];
        yield 'assigned without else' => ['AssignWithoutElse', ['A' => [$c, $l]], []];
        yield 'assigned in a nested condition' => ['NestedIf', ['A' => [$c, $l], 'B' => [$c, $l]], []];
        yield 'loop variable shadows and is restored' => ['LoopAlias', ['A' => [$l], 'B' => [$c]], []];
        // the key of a list is an int to FluidStan
        yield 'loop key shadows and is restored' => ['LoopKeyAlias', ['A' => [$n], 'B' => [$c]], ['A' => ['other']]];
        yield 'iteration variable shadows and is restored' => ['LoopIterationAlias', ['A' => [$n], 'B' => [$c]], ['A' => ['other']]];
        yield 'assigned in the loop body persists across iterations and after' => ['LoopBodyAssign', ['A' => [$c, $l], 'B' => [$l], 'C' => [$c, $l]], []];
        yield 'assigned in the loop body, read after' => ['LoopBodyAssignThenRead', ['A' => [$c, $l]], []];
        yield 'loop variable restores an earlier local value' => ['AliasRestoresEarlierLocal', ['A' => [$c], 'B' => [$l]], []];
        yield 'loop variable restores an argument' => ['AliasRestoresArgument', ['A' => [$c], 'B' => [$l]], []];
        yield 'nested loops with the same variable' => ['NestedLoopAlias', ['A' => [$c], 'B' => [$l], 'C' => [$c]], []];
        // the group is a list to FluidStan, and the group key is approximated by the element type
        yield 'grouped loop restores group and key' => ['GroupedLoopAlias', ['A' => [$n], 'B' => [$n], 'C' => [$c], 'D' => [$n]], ['A' => ['other'], 'B' => [$l]]];
        yield 'never assigned, not provided' => ['UnprovidedNever', ['A' => [$n]], []];
        yield 'conditionally assigned, not provided' => ['UnprovidedConditional', ['A' => [$l, $n]], []];
        yield 'assigned in a loop body, not provided' => ['UnprovidedInLoop', ['A' => [$l, $n]], []];
        yield 'loop variable that is not provided' => ['UnprovidedLoopAlias', ['A' => [$l], 'B' => [$n]], []];
        // variables inside sections are mixed
        yield 'assignment inside a section stays there' => ['SectionScope', ['A' => [], 'B' => [$c]], ['A' => []]];
        yield 'reassigned from itself' => ['ReassignFromItself', ['A' => [$c]], []];
        yield 'loop in a condition restores the earlier local value' => ['AssignThenLoopAlias', ['A' => [$c], 'B' => [$l]], []];
        yield 'alias exists for its children only' => ['AliasScope', ['A' => [$l], 'B' => [$c]], []];
        yield 'cycle variable exists for its children only' => ['CycleScope', ['A' => [$l], 'B' => [$c]], []];
        // the ViewHelper assigns its mixed "value" argument, so nothing is assumed inside; remove() deletes
        // the variable for good, which Fluid renders as nothing and FluidStan types as null
        yield 'a ViewHelper that removes its variable again' => ['ScopedViewHelper', ['A' => [$l], 'B' => [$n]], ['A' => [], 'B' => []]];
    }

    /**
     * @param array<string, list<string>> $expected label => values Fluid uses over all variants
     * @param array<string, list<string>> $special what FluidStan assumes at labels where it cannot match Fluid exactly,
     *                                             e.g. [] for mixed or ["other"] for a non-object Fluid prints as nothing
     */
    #[DataProvider('cases')]
    public function testFluidAndFluidStanAgree(string $case, array $expected, array $special): void
    {
        $this->assertSame($expected, $this->renderWithFluid($case), 'Values Fluid actually uses');

        $assumed = $this->assumptions()[$case] ?? [];
        foreach ($expected as $label => $values) {
            $expectedAssumption = $special[$label] ?? ($values === [self::NONE] ? ['undefined'] : array_values(array_diff($values, [self::NONE])));
            sort($expectedAssumption);
            $this->assertSame($expectedAssumption, $assumed[$label] ?? [], \sprintf('What FluidStan assumes at %s of %s', $label, $case));
        }

        $this->assertSame([], array_diff_key($assumed, $expected), 'FluidStan reports at lines without a read');
    }

    /**
     * Renders the component through Fluid for every combination of inputs and collects the values
     * each read printed.
     *
     * @return array<string, list<string>>
     */
    private function renderWithFluid(string $case): array
    {
        $seen = [];
        foreach ([true, false] as $flag) {
            foreach ([[], [new Local(), new Local()]] as $items) {
                $view = new TemplateView();
                $view->getRenderingContext()->getTemplatePaths()->setTemplateSource(\sprintf(
                    '<html xmlns:sc="http://typo3.org/ns/FluidStan/Tests/Fixtures/Scope/ScopeCollection" xmlns:fv="http://typo3.org/ns/FluidStan/Tests/Fixtures/ViewHelpers" data-namespace-typo3-fluid="true"><sc:cases.%s flag="{flag}" local="{local}" items="{items}" others="{others}" /></html>',
                    lcfirst($case),
                ));
                $view->assignMultiple(['flag' => $flag, 'local' => new Local(), 'items' => $items, 'others' => [new Provided(), new Provided()]]);
                $output = $view->render();
                $this->assertIsString($output);

                preg_match_all('/(\w+):\[([a-z]*)\|([a-z]*)\]/', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as [, $label, $fromCollection, $fromLocal]) {
                    $seen[$label][] = match (true) {
                        $fromCollection === 'collection' => self::COLLECTION,
                        $fromLocal === 'local' => self::LOCAL,
                        default => self::NONE,
                    };
                }
            }
        }

        $result = [];
        foreach ($this->labels($case) as $label) {
            $values = array_values(array_unique($seen[$label] ?? []));
            sort($values);
            $result[$label] = $values;
        }

        return $result;
    }

    /**
     * Analyses all cases once and derives, from the reported diagnostics, which values FluidStan
     * assumes at every read.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function assumptions(): array
    {
        if (self::$assumptions !== null) {
            return self::$assumptions;
        }

        $root = \dirname(self::CASES);
        $configuration = (new ConfigurationLoader())->fromParameters([
            'paths' => [self::CASES],
            'namespaces' => ['sc' => ScopeCollection::class],
            'tmpDir' => $this->tmpDir,
        ], $root);
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $result = (new Analyser($configuration, $autoload, \dirname($autoload) . '/bin/phpstan'))->analyse();

        $assumptions = [];
        foreach ($result->diagnostics as $diagnostic) {
            $case = basename($diagnostic->file, '.html');
            $label = $this->labelAt($case, $diagnostic->line);
            $assumptions[$case][$label][] = $this->assumption($diagnostic);
        }

        foreach ($assumptions as &$labels) {
            foreach ($labels as &$values) {
                $values = array_values(array_unique($values));
                sort($values);
            }
        }

        return self::$assumptions = $assumptions;
    }

    private function assumption(Diagnostic $diagnostic): string
    {
        if ($diagnostic->identifier === 'variable.undefined') {
            return 'undefined';
        }

        return match (true) {
            preg_match('/^Property "fromLocal" does not exist on \S+\\\\Provided \(/', $diagnostic->message) === 1 => self::COLLECTION,
            preg_match('/^Property "fromCollection" does not exist on \S+\\\\Local \(/', $diagnostic->message) === 1 => self::LOCAL,
            default => 'other',
        };
    }

    private function labelAt(string $case, ?int $line): string
    {
        $lines = file(self::CASES . '/' . $case . '/' . $case . '.html');
        $text = $lines === false ? '' : ($lines[($line ?? 0) - 1] ?? '');
        return preg_match('/^(\w+):\[/', $text, $matches) === 1 ? $matches[1] : \sprintf('line %s: %s', $line ?? '?', trim($text));
    }

    /**
     * @return list<string>
     */
    private function labels(string $case): array
    {
        $source = (string) file_get_contents(self::CASES . '/' . $case . '/' . $case . '.html');
        preg_match_all('/^(\w+):\[/m', $source, $matches);
        return $matches[1];
    }
}
