<?php

declare(strict_types=1);

namespace FluidStan\Tests\Fixtures\ViewHelpers;

/**
 * Declares with a docblock tag what its parent lets detection find out.
 *
 * @fluidstan-assigns-to-children {as} mixed
 */
final class TaggedViewHelper extends ProviderViewHelper {}
