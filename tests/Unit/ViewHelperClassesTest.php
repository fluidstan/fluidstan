<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Detection\ViewHelperClasses;
use FluidStan\Fluid\FluidEnvironment;
use FluidStan\Tests\Fixtures\ViewHelpers\AssignViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\LoadViewHelper;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\ViewHelpers\CountViewHelper;
use TYPO3Fluid\Fluid\ViewHelpers\IfViewHelper;

final class ViewHelperClassesTest extends TestCase
{
    public function testCollectsTagsInlineCallsAndNestedArguments(): void
    {
        $environment = new FluidEnvironment((new ConfigurationLoader())->fromParameters(['namespaces' => ['fv' => 'FluidStan\\Tests\\Fixtures\\ViewHelpers']], __DIR__));
        $parsed = $environment->parseSource(
            '<f:if condition="{f:count(subject: items)}"><fv:assign value="{fv:load(uid: 1)}" /></f:if>',
            '/t.html',
        );

        $this->assertSame([AssignViewHelper::class, LoadViewHelper::class, CountViewHelper::class, IfViewHelper::class], ViewHelperClasses::inTemplates([$parsed]));
    }
}
