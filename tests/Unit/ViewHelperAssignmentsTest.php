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

    public function testSubclassOverridesParentsWhateverTheOrderOfTheMap(): void
    {
        $child = new class extends AssignViewHelper {};
        $parent = [AssignViewHelper::class => ['shared' => ['type' => 'string', 'scope' => 'template'], 'inherited' => ['type' => 'int', 'scope' => 'template']]];
        $own = [$child::class => ['shared' => ['type' => 'non-empty-string', 'scope' => 'children']]];
        $expected = ['shared' => ['type' => 'non-empty-string', 'scope' => 'children'], 'inherited' => ['type' => 'int', 'scope' => 'template']];

        $cases = [
            'configured, parent first' => new ViewHelperAssignments($parent + $own),
            'configured, child first' => new ViewHelperAssignments($own + $parent),
            'detected, child first' => new ViewHelperAssignments([], $own + $parent),
        ];
        foreach ($cases as $case => $assignments) {
            $this->assertSame($expected, array_intersect_key($assignments->forViewHelper($child::class), $expected), $case);
        }

        $this->assertSame($parent[AssignViewHelper::class], array_intersect_key((new ViewHelperAssignments($own + $parent))->forViewHelper(AssignViewHelper::class), $expected), "the parent does not see the child's entries");
    }

    public function testUnknownClassHasNoAssignments(): void
    {
        $this->assertSame([], (new ViewHelperAssignments())->forViewHelper('Nope\\ViewHelper'));
    }
}
