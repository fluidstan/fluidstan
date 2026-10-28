<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Tests\Fixtures\ViewHelpers\AssignViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\LoadViewHelper;
use FluidStan\Transpiler\ViewHelperAssignments;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\ViewHelpers\CountViewHelper;

final class ViewHelperAssignmentsTest extends TestCase
{
    public function testReadsDocBlockTags(): void
    {
        $this->assertSame(['{target}' => 'string'], (new ViewHelperAssignments())->forViewHelper(AssignViewHelper::class));
        $this->assertSame([], (new ViewHelperAssignments())->forViewHelper(CountViewHelper::class));
    }

    public function testConfigurationAppliesToSubclassesAndWinsOverTags(): void
    {
        $assignments = new ViewHelperAssignments([
            LoadViewHelper::class => ['item' => 'My\\Item'],
            AssignViewHelper::class => ['{target}' => 'non-empty-string', 'log' => 'list<string>'],
        ]);

        $this->assertSame(['item' => 'My\\Item'], $assignments->forViewHelper(LoadViewHelper::class));
        $this->assertSame(['{target}' => 'non-empty-string', 'log' => 'list<string>'], $assignments->forViewHelper(AssignViewHelper::class));
    }

    public function testInheritsTagsOfParentClasses(): void
    {
        $child = new class extends AssignViewHelper {};
        $this->assertSame(['{target}' => 'string'], (new ViewHelperAssignments())->forViewHelper($child::class));
    }

    public function testUnknownClassHasNoAssignments(): void
    {
        $this->assertSame([], (new ViewHelperAssignments())->forViewHelper('Nope\\ViewHelper'));
    }
}
