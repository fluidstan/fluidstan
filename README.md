# FluidStan

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/logo-dark.svg">
  <img src="docs/logo.svg" alt="FluidStan" height="75">
</picture>

<a href="https://www.flowd.de">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://www.flowd.de/assets/logo/flowd-logo-white.svg">
    <img src="https://www.flowd.de/assets/logo/flowd-logo-dark.svg" alt="Flowd GmbH" align="right" height="60">
  </picture>
</a>

<hr />

Static analysis for [TYPO3 Fluid](https://github.com/TYPO3/Fluid) templates and components, powered by
[PHPStan](https://phpstan.org). Developed and maintained by [Flowd GmbH](https://flowd.de).

<p>
  <a href="https://github.com/fluidstan/fluidstan/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/fluidstan/fluidstan/actions/workflows/ci.yml/badge.svg"></a>
  <a href="https://packagist.org/packages/fluidstan/fluidstan"><img alt="Packagist" src="https://img.shields.io/packagist/v/fluidstan/fluidstan"></a>
  <a href="LICENSE"><img alt="MIT" src="https://img.shields.io/badge/license-MIT-blue"></a>
</p>

<img alt="FluidStan output for the example in docs/example" src="docs/screenshot.svg" width="906">

FluidStan knows the PHP types behind your template variables and reports what Fluid would only reveal at
runtime. The screenshot shows the analysis of [`docs/example`](docs/example), a product card component
with a typo in a property path, a wrong argument type, an object rendered without `__toString()`, a loop
over a string, an undefined variable and an unknown slot. Run it yourself with `composer fluidstan:example`.

It reports:

- property paths that cannot be resolved (`{item.nonexistent}`), following Fluid's lookup order:
  array key, `ArrayAccess`, PSR container, getter, isser, hasser, public property
- values that cannot be rendered (`{item.money}` where `Money` has no `__toString()`, arrays)
- `f:for` over something that is not iterable
- ViewHelper and component arguments whose type does not satisfy the argument definition, with the
  same rules Fluid's `StrictArgumentProcessor` applies at runtime. Note that Fluid casts scalars
  before validating: a string passed to an `int` argument, or a float to a `string`, is accepted at
  runtime and therefore not reported; objects, arrays and `null` for required arguments are. See
  [`StrictArgumentProcessor::process()`](https://github.com/TYPO3/Fluid/blob/main/src/Core/ViewHelper/StrictArgumentProcessor.php)
  and its use in [`ViewHelperInvoker`](https://github.com/TYPO3/Fluid/blob/main/src/Core/ViewHelper/ViewHelperInvoker.php).
- non-numeric operands in `{a + b}`
- undefined variables in components (their scope is closed)
- all of that with the knowledge that a value checked by `f:if` is not null, false or empty inside
  the condition (see "Conditions" below for what narrows and what does not)
- unknown ViewHelpers, unknown or missing arguments, syntax errors (Fluid's parser), unknown component
  slots, `f:render` of undefined sections, deprecated ViewHelper usage

Fluid components (Fluid 4.3+, TYPO3 13.4+) declare their argument types with `f:argument`, so they are
analysed out of the box. Plain templates have `mixed` variables unless you declare them (see below).

Requires PHP 8.2+, Fluid 4.4 or 5.x, PHPStan 2. Argument checks always follow Fluid 5's strict
validation; on Fluid 4, whose runtime is lenient, they report what would fail after an upgrade.

> Status: early. The pipeline is complete, the rule set is small and the design is settled; see "How it works" and "Roadmap".

## Roadmap

- Layouts, partials and sections analysed with the types of their call sites (sections are currently
  analysed with `mixed` variables)
- Type inference from `assign()` / `assignMultiple()` calls in PHP code
- Result cache, more output formats
- Upstream proposal: positions on Fluid syntax tree nodes

## Installation

```bash
composer require --dev fluidstan/fluidstan
# TYPO3 projects:
composer require --dev fluidstan/typo3
```

## Usage

```bash
vendor/bin/fluidstan                          # uses ./fluidstan.neon
vendor/bin/fluidstan path/to/Components       # explicit paths
vendor/bin/fluidstan --error-format=json
vendor/bin/fluidstan --generate-baseline      # writes the current errors to fluidstan-baseline.neon
vendor/bin/fluidstan --debug                  # prints the directory of the generated PHP code
```

`fluidstan.neon`:

```neon
includes:
    - fluidstan-baseline.neon  # optional, see "Baseline and ignored errors"
parameters:
    paths:
        - packages/my_site/Resources/Private/Components
        - packages/my_site/Resources/Private/Templates
    suffixes: [.html]
    namespaces:
        # identifier => PHP namespaces or component collection classes; "f" defaults to TYPO3Fluid\Fluid\ViewHelpers
        my: My\Site\Components\ComponentCollection
    bootstrapFiles:
        - vendor/autoload.php
    tmpDir: var/cache/fluidstan
    strict: true               # also report what Fluid tolerates silently, see "Strict mode"
    ignoreErrors: []           # as in PHPStan, see "Baseline and ignored errors"
    phpstan:
        includes: []           # extra PHPStan config files for the generated code
        parameters: {}         # extra PHPStan parameters
```

Argument values are checked the way Fluid validates them: a scalar passed to a `string`, `int`,
`float` or `bool` argument is cast to that type first, so `{item.title}` passes as an `int`
argument and becomes `0` at runtime. Union types such as `string|int` are never cast and are
checked member by member, as Fluid does. Strict mode reports the casts.

PHPStan's rule level is not configurable: FluidStan's checks implement Fluid's semantics and run
regardless of the level, and the generated code contains none of the constructs the levels add checks for.
The file is read by PHPStan's own configuration loader and validated against a schema, so `includes`
work as in PHPStan and an unknown or mistyped parameter is an error.

TYPO3 projects use the adapter, which boots TYPO3 without a database, registers the namespaces from
`$GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']` and resolves `EXT:` paths:

```neon
parameters:
    environment: FluidStan\TYPO3\Typo3Environment
    bootstrapFiles:
        - vendor/autoload.php
    paths:
        - EXT:my_site/Resources/Private/Components
```

## Declaring variable types

Components: `<f:argument name="item" type="My\Model\Item" />`. Fluid's type grammar applies:
scalars, `mixed`, `object`, `iterable`, class names, `Item[]`, unions.

Plain templates (and overrides in components): a `@var` annotation in a comment, PHPDoc types allowed.

```html
<f:comment>
    @var My\Model\Item item
    @var list<My\Model\Item> items
</f:comment>
```

Variables a component collection provides through `getAdditionalVariables()` are known as well, without
running the method: PHPStan infers the array shape from its return statements, the way it infers closure
return types, so a collection that builds `['component' => ['class' => ..., 'prefix' => ...]]` gives
`{component.class}` the type `string`. A `@return` array shape in the method's PHPDoc takes precedence,
and `@var` in the template overrides both.

## Variables assigned by ViewHelpers

`f:variable`, `f:alias`, `f:cycle`, `f:for` and `f:groupedFor` are the ViewHelpers Fluid ships that put
variables into a template, and FluidStan knows them. A ViewHelper of your own or of an extension may do
the same through the variable provider. FluidStan finds that out by itself: before the templates are
analysed, PHPStan runs once over the classes of the ViewHelpers they use and records every `add()` and
`addAll()` on a variable provider, with the type of the value. A constant name is taken as is; a name
that comes from an argument of the ViewHelper, through casts, local variables or a `toString()`-like
helper, becomes that argument, so `$provider->add($this->arguments['target'], ...)` assigns the
variable the call's `target` argument names, or the argument's default. An assignment that is not
reached on every path, after an early `return` or inside a condition, may leave the variable unassigned,
which in Fluid means `null`, so its type gets `|null`. The pass is cached in `tmpDir` per set of
ViewHelper files, by their content and the FluidStan and PHPStan versions, and only runs again when one
of them changed. A type a ViewHelper takes from another file, such as a repository's return type, is not
tracked: after such a change, touch the ViewHelper or delete the cache.

A ViewHelper may also give a variable to its children only, the way `f:alias` and `f:cycle` do. Both
forms Fluid offers are detected and modelled as Fluid behaves: a `ScopedVariableProvider` around a
`StandardVariableProvider` restores the previous value after the tag, while `add()`, `renderChildren()`
and `remove()` deletes the variable for good, so it is `null` afterwards even if the collection provided it.

Where detection cannot follow the code, the name is computed or the ViewHelper delegates to a service,
or where it is more cautious than you know to be true, declare the assignment in `fluidstan.neon`:

```neon
parameters:
    viewHelperAssignments:
        # ViewHelper class => variable => PHPDoc type; {target} names the variable after the "target" argument
        My\Site\ViewHelpers\ClassBuilderViewHelper:
            '{target}': string
        My\Site\ViewHelpers\LoadProductViewHelper:
            product: My\Site\Domain\Model\Product
        # a variable the children see only; scope "removed" when the ViewHelper deletes it afterwards
        My\Site\ViewHelpers\WithProductViewHelper:
            '{as}': { type: My\Site\Domain\Model\Product, scope: children }
```

For your own ViewHelpers the same can live in the class docblock, where it is kept next to the code:

```php
/**
 * @fluidstan-assigns {target} string
 * @fluidstan-assigns-to-children {as} My\Site\Domain\Model\Product
 */
final class ClassBuilderViewHelper extends AbstractViewHelper
```

`{target}` resolves to the call's `target` argument, or to the argument's default when the call omits
it; a call whose argument is not a constant string assigns nothing. Subclasses inherit detected and
declared assignments alike. The configuration wins over a tag, and a tag wins over detection. After the
call the variable is local, with everything that follows from that: its type is the declared one, and a
read before the first call is still reported.

## Strict mode

Fluid tolerates a lot at runtime without a word. The default checks report what Fluid rejects or what
cannot work; `strict: true` adds what Fluid does silently and what is probably not meant. Until the
setting is in the configuration the analysis runs without it and says so after the results; `strict:
false` keeps it off and ends the note.

| Identifier | Reported |
| --- | --- |
| `fluidstan.argument.cast` | A scalar passed to a `string`, `int` or `float` argument that Fluid casts, losing information: a string to `int` becomes `0`, a bool to `string` becomes `1` or an empty string. An integer or an integer-like numeric string for `int`, a number for `string`, still passes. |
| `fluidstan.property.nullable` | A path through a value that may be null, such as `{item.parent.title}` with a nullable parent: Fluid yields null. Inside `<f:if condition="{item.parent}">` the value is known to be set and nothing is reported. |
| `if.alwaysTrue`, `if.alwaysFalse`, `booleanAnd.*`, `booleanOr.*`, `booleanNot.*`, `ternary.*` | A condition the types decide, such as `<f:if condition="{item}">` on an object that cannot be null or a bare `'false'`; reported by PHPStan's own rules on the generated code. A string stays open because `"false"` and `"0"` are false, and so does a `Countable`, which may be empty. |
| `fluidstan.comparison.loose` | A comparison of scalars of different kinds, such as `{count} == '5'` or `{flag} == 1`: Fluid compares with PHP's loose operators, so the outcome depends on type juggling. `===` and `!==` are never reported; a `Countable` compares as a bool. |
| `fluidstan.output.bool` | A bool rendered into the output, which prints `1` or nothing. |

## Baseline and ignored errors

```bash
vendor/bin/fluidstan --generate-baseline                      # fluidstan-baseline.neon next to the configuration
vendor/bin/fluidstan --generate-baseline=build/baseline.neon
```

The baseline records every current error as an `ignoreErrors` entry, in PHPStan's format with the
message as a pattern, the identifier, the count and the template path relative to the baseline file.
Include it from `fluidstan.neon` and only new errors are reported; regenerate it to take the current
state as the new reference, the entries of the file being written do not hide anything while it is
generated.

`ignoreErrors` takes what PHPStan takes, a pattern or a map with `message` and/or `identifier`, `path`
or `paths` relative to the file that defines the entry, `count` and `reportUnmatched`:

```neon
parameters:
    ignoreErrors:
        # a pattern, anywhere
        - '#^Variable "legacy" is not defined#'
        # an identifier in some templates
        -
            identifier: fluidstan.output.bool
            paths:
                - Components/Atom/Toggle/*.html
        # exactly this many occurrences in one template; a fourth one, or only two, is reported
        -
            message: '#^Cannot access "length" on int\.$#'
            path: Components/Molecule/Card/Card.html
            count: 3
        # an entry that may match nothing without being reported
        -
            identifier: fluidstan.property.nullable
            path: Templates/Legacy.html
            reportUnmatched: false
    # or for every entry at once
    reportUnmatchedIgnoredErrors: false
```

The matching, the counting and the report of an entry that no longer matches anything are PHPStan's
own code, so they behave as in PHPStan: with a `count`, that many occurrences are ignored and a
differing number is reported as `ignore.count`; an entry that matches nothing is reported as
`ignore.unmatched` unless its `reportUnmatched: false` or the global `reportUnmatchedIgnoredErrors:
false` says otherwise; and nothing is reported about unmatched entries when single files are analysed
rather than directories.

## Conditions

Inside `<f:if condition="{item}">` the type of `item` loses `null`, `false`, empty strings and
zero, so passing it to an argument typed `Item` is fine there. The same holds for every operand of
an `&&` chain, inside `f:then` and inside `f:else if="{other}"` for `other`.

What does **not** narrow, on purpose:

- the `f:else` branch and a negated check `!{item}`: Fluid treats the string `"false"` and an empty
  `Countable` as false while PHP treats them as true, so FluidStan cannot claim the value is empty there
- the operands of `||`
- a condition on a property path, `{item.title}`, says nothing about `item` itself
- the loose comparisons `==` and `!=`, even against a literal: `{label} == 'abc'` leaves `label` as
  `string|null`. Fluid applies the PHP operator, and PHPStan is deliberately cautious with loose
  comparisons. `===`, `!==`, `<`, `<=`, `>` and `>=` narrow the way they do in PHP
- comparisons involving a value that may be a `Countable` or `UnsafeHTML`: Fluid compares a bool or
  a string in their place, so an empty `ObjectStorage` does equal `0` there, and PHPStan's reasoning
  about the object would be wrong

Conditions are not re-implemented: the PHP code Fluid's own `BooleanParser` compiles for them is
reused with the operands substituted, so grammar, precedence and comparison semantics are Fluid's.

## How it works

1. Templates are parsed with Fluid's own `TemplateParser`, configured with the real ViewHelper
   namespaces so argument definitions are real. Fluid's nodes carry no positions, so a parser subclass
   records one per node. Parser errors are collected instead of aborting.
2. Each template is transpiled to a PHP function: component arguments become parameters, `f:for`
   becomes `foreach`, `f:variable` an assignment, `f:if` an `if`. Everything dynamic becomes a call on
   `FluidStan\Runtime\F`, e.g. `{item.title}` → `F::get($item, 'title')`.
3. PHPStan analyses the generated code with the FluidStan extension (`extension.neon`): return type
   extensions resolve the facade calls, rules implement Fluid's runtime semantics.
4. Errors are mapped back to template lines and printed in PHPStan's style.

Fluid's compiled template cache is not used: it resolves every variable through one opaque variable
provider, which erases the scope information PHPStan needs.

## What comes from Fluid, what is mirrored

FluidStan reuses Fluid wherever Fluid exposes the behaviour: the parser and syntax tree, namespace
detection, ViewHelper resolution and argument definitions, the parser's own argument validation
(unknown and missing arguments), component definitions and slots, the component list of a collection
(Fluid 5), section registration,
template discovery (`TemplateFinder`, Fluid 5), deprecation reporting (as `TemplateValidator` does) and
the unwrapping of wrapped parse exceptions.

Some runtime behaviour has no static counterpart in Fluid and is mirrored in the PHPStan extension or
the transpiler. When Fluid changes one of these, FluidStan must follow:

| Behaviour | Source of truth in Fluid |
| --- | --- |
| Property path lookup order | `StandardVariableProvider::getByPath()` |
| Argument type validation | `StrictArgumentProcessor` (`process()` then `isValid()`) |
| Casting of output and of multiple children | `AbstractNode::evaluateChildNodes()` / `castToString()` |
| Ternary, math and casting expression syntax | `TernaryExpressionNode`, `MathExpressionNode`, `CastingExpressionNode` |
| Fragment to slot mapping | `ComponentAdapter` (private helpers) |
| Argument parsing with non-fatal validation | `TemplateParser::parseArguments()` and `abortIfRequiredArgumentsAreMissing()` |
| Iteration variable keys | `ForViewHelper` |
| Truthiness of conditions | `BooleanNode::convertToBoolean()` |
| Operand conversion in conditions | `BooleanParser::convertNodeToBoolean()` |
| Condition grammar, precedence and comparisons | `BooleanParser::compile()`, whose PHP code is reused with the operands substituted |
| Component names from template files (Fluid 4 only) | `AbstractComponentCollection::getAvailableComponents()` (Fluid 5) |

Candidates for upstream contributions that would remove mirrored code: source positions on syntax
tree nodes, a public fragment extraction on `ComponentAdapter`, and a reusable type grammar helper
for argument definitions.

## Changes

See [CHANGELOG.md](CHANGELOG.md).

## Development

```bash
composer install
composer check            # everything below
composer lint             # syntax, composer.json, php-cs-fixer, PHPStan (max, strict), Rector, dependency analysis
composer fix              # apply Rector, php-cs-fixer and composer normalize
composer test             # PHPUnit
composer test:coverage    # PHPUnit with coverage (Xdebug or pcov)
composer test:mutation    # Infection, full run, minimum MSI 70 % (CI runs it on main and weekly; pull requests only mutate changed lines)
composer fluidstan        # analyse the fixture templates
```

The runtime facade `src/Runtime/F.php` is excluded from Rector: its stubs' signatures are the contract with
the generated code. Fluid's parser and syntax tree are `@internal`; PHPStan's internal-usage checks are
disabled for the `TYPO3Fluid` namespace only. CI runs the suite on PHP 8.2 to 8.5 against Fluid 4 and 5,
with lowest and highest dependencies.
