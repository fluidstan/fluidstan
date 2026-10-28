<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Tests\Fixtures\ViewHelpers\AssignViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\LoadViewHelper;
use FluidStan\Tests\Fixtures\ViewHelpers\TaggedViewHelper;
use FluidStan\Transpiler\ViewHelperAssignments;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\ViewHelpers\CountViewHelper;

final class ViewHelperAssignmentsTest extends TestCase
{
    public function testReadsDocBlockTags(): void
    {
        $this->assertSame(['{target}' => ['type' => 'string', 'scope' => 'template']], (new ViewHelperAssignments())->forViewHelper(AssignViewHelper::class));
        $this->assertSame(['{as}' => ['type' => 'mixed', 'scope' => 'children']], (new ViewHelperAssignments())->forViewHelper(TaggedViewHelper::class));
        $this->assertSame([], (new ViewHelperAssignments())->forViewHelper(CountViewHelper::class));
    }

    public function testConfigurationWinsOverTagsWhichWinOverDetection(): void
    {
        $assignments = new ViewHelperAssignments(
            [
                LoadViewHelper::class => ['item' => ['type' => 'My\\Item', 'scope' => 'template']],
                AssignViewHelper::class => ['{target}' => ['type' => 'non-empty-string', 'scope' => 'template']],
            ],
            [
                LoadViewHelper::class => ['item' => ['type' => 'mixed', 'scope' => 'template'], 'other' => ['type' => 'int', 'scope' => 'children']],
                AssignViewHelper::class => ['{target}' => ['type' => 'string|null', 'scope' => 'template']],
                TaggedViewHelper::class => ['{as}' => ['type' => 'My\\Item', 'scope' => 'children']],
            ],
        );

        $this->assertSame(['item' => ['type' => 'My\\Item', 'scope' => 'template'], 'other' => ['type' => 'int', 'scope' => 'children']], $assignments->forViewHelper(LoadViewHelper::class));
        $this->assertSame(['{target}' => ['type' => 'non-empty-string', 'scope' => 'template']], $assignments->forViewHelper(AssignViewHelper::class));
        $this->assertSame(['{as}' => ['type' => 'mixed', 'scope' => 'children']], $assignments->forViewHelper(TaggedViewHelper::class), 'the tag wins over detection');
    }

    public function testInheritsTagsOfParentClasses(): void
    {
        $child = new class extends AssignViewHelper {};
        $this->assertSame(['{target}' => ['type' => 'string', 'scope' => 'template']], (new ViewHelperAssignments())->forViewHelper($child::class));
    }

    public function testUnknownClassHasNoAssignments(): void
    {
        $this->assertSame([], (new ViewHelperAssignments())->forViewHelper('Nope\\ViewHelper'));
    }
}
