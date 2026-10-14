<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Diagnostics\Diagnostic;
use FluidStan\Fluid\FluidEnvironment;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\EscapingNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\NodeInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ObjectAccessorNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;

final class LocationTrackingTemplateParserTest extends TestCase
{
    private const TEMPLATE = <<<'FLUID'
        <html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers" data-namespace-typo3-fluid="true">
        <f:argument name="items" type="string[]" />
        <div>
          {items.0}
          <f:for each="{items}"
                 as="item">
            <b>{item -> f:format.raw()}</b>
          </f:for>
          <f:if condition="{items} && {x.y}">yes</f:if>
          <f:comment>
            {nope}
          </f:comment>
          {z}
        </div>
        </html>
        FLUID;

    public function testNodesGetExactLinesAndColumns(): void
    {
        $environment = new FluidEnvironment((new ConfigurationLoader())->fromParameters([], __DIR__));
        $template = $environment->parseSource(self::TEMPLATE, '/test.html');
        $this->assertTrue($template->isParsed());
        $this->assertCount(0, $template->parseDiagnostics);

        $positions = [];
        $walk = function (NodeInterface $node) use (&$walk, &$positions, $template): void {
            $inner = $node instanceof EscapingNode ? $node->getNode() : $node;
            $location = $template->locations->get($node);
            if ($inner instanceof ViewHelperNode) {
                $positions[$inner->getNamespace() . ':' . $inner->getName()] = $location?->line . ':' . $location?->column;
                foreach ($inner->getArguments() as $argument) {
                    if ($argument instanceof NodeInterface) {
                        $walk($argument);
                    }
                }
            } elseif ($inner instanceof ObjectAccessorNode) {
                $positions['{' . $inner->getObjectPath() . '}'] = $location?->line . ':' . $location?->column;
            }

            foreach ($node->getChildNodes() as $child) {
                $walk($child);
            }
        };
        $walk($template->parsingState?->getRootNode() ?? throw new \LogicException());

        $expected = [
            'f:argument' => '2:1',
            '{items.0}' => '4:3',
            'f:for' => '5:3',
            '{items}' => '5:16',
            '{item}' => '7:8',
            'f:format.raw' => '7:17',
            'f:if' => '9:3',
            '{z}' => '13:3',
        ];
        $actual = array_intersect_key($positions, $expected);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertArrayNotHasKey('{nope}', $positions);
    }

    public function testAllMissingRequiredArgumentsAreReportedAndParsingContinues(): void
    {
        $environment = new FluidEnvironment((new ConfigurationLoader())->fromParameters([], __DIR__));
        $template = $environment->parseSource("<div>\n<f:for>{x}</f:for>\n</div>", '/test.html');

        $this->assertSame(
            ['2: Required argument "each" was not supplied.', '2: Required argument "as" was not supplied.'],
            array_map(static fn(Diagnostic $d): string => $d->line . ': ' . $d->message, $template->parseDiagnostics->all()),
        );
        // The tag survived as a ViewHelper node instead of degrading to text
        $this->assertTrue($template->isParsed());
        $this->assertCount(3, $template->parsingState?->getRootNode()->getChildNodes() ?? []);
    }

    public function testUndeclaredComponentArgumentsAreNamed(): void
    {
        $root = __DIR__ . '/../Fixtures';
        $configuration = (new ConfigurationLoader())->load($root . '/fluidstan.neon', $root);
        $template = (new FluidEnvironment($configuration))->parseSource('<fs:atom.price item="{item}" string="x" nope="y" />', $root . '/test.html');

        $this->assertSame(
            ['1: Component fs:atom.price has no argument "string", "nope".'],
            array_map(static fn(Diagnostic $d): string => $d->line . ': ' . $d->message, $template->parseDiagnostics->all()),
        );
    }

    public function testParseErrorsAreCollectedWithLines(): void
    {
        $environment = new FluidEnvironment((new ConfigurationLoader())->fromParameters([], __DIR__));
        $template = $environment->parseSource("<div>\n<f:unknown />\n{x}\n<f:format.date wrong=\"1\" />\n</div>", '/test.html');
        $lines = array_map(static fn(Diagnostic $d): ?int => $d->line, $template->parseDiagnostics->all());
        $this->assertSame([2, 4], $lines);
    }
}
