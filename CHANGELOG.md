# Changelog

## 0.2.0

Components get the variables their collection provides, `f:if` conditions narrow types, and the
environment contract for framework adapters grows. Two settings are gone, which is why this is 0.2.

### Added

- **Additional component variables.** Variables a component collection provides through
  `getAdditionalVariables()` are no longer reported as undefined ([#3](https://github.com/fluidstan/fluidstan/issues/3)).
  The method is not executed: PHPStan infers the array shape from its return statements, so
  `{component.class}` is a `string` and `{component.nonexistent}` is an error without any docblock. A
  `@return` array shape on the method takes precedence, a `@var` annotation in the template overrides both.
  Variables that are neither arguments nor provided by the collection are still reported as undefined,
  now with the collection named in the message. A component of a collection counts as a component even
  without `f:argument`.
- **Variable scoping as Fluid does it.** A variable assigned by `f:variable` keeps its local value on the
  paths where it was assigned and the collection's value elsewhere, across conditions and loop
  iterations; loop variables shadow a value for the loop only and are restored afterwards, as Fluid's
  `ForViewHelper` does. An integration test renders 23 templates through real Fluid in every input
  variant and checks that the analysis assumes exactly the values Fluid used.
- **Narrowing through `f:if`.** Inside `<f:if condition="{item}">` the value is not null, false, empty or
  zero, also through `&&` chains, `f:then` and `f:else if`. Conditions are the PHP code Fluid's own
  `BooleanParser` compiles for them, so grammar, precedence and comparison semantics are Fluid's;
  comparisons narrow the way PHPStan narrows PHP comparisons, `===` and size comparisons do, the loose
  `==` does not. The false branch, `||` and values that may be a `Countable` or `UnsafeHTML` are left
  alone on purpose, because Fluid's truthiness and operand conversion differ from PHP's there. The
  README's "Conditions" section lists the rules.
- **Framework-built resolver delegates.** An environment can hand over resolver delegates keyed by the
  namespace string templates refer to, for collections a framework configures without a class, such as
  TYPO3 14.1's `Configuration/Fluid/ComponentCollections.php`. Ignored namespaces (`null`) are passed
  through and Fluid leaves their tags alone.

### Removed

- **The PHPStan rule level.** `phpstan.level` in `fluidstan.neon` and the `-l` option changed nothing:
  FluidStan's checks implement Fluid's semantics and run at any level, and the generated code contains
  none of the constructs the levels add checks for. A configuration that still sets `phpstan.level` is
  rejected with a message.

### Breaking changes

- `FluidStan\Environment\EnvironmentInterface` has a new method, `getResolverDelegates()`, and
  `getNamespaces()` may return `null` for an ignored namespace. Adapters built against 0.1 do not load;
  `fluidstan/typo3` 0.2 requires this release, and this release declares a conflict with
  `fluidstan/typo3` below 0.2.
- `phpstan.level` is rejected, see above.

### Thanks

Simon Praetorius ([@s2b](https://github.com/s2b)) for the issues that shaped this release.

## 0.1.1

Small release with better argument diagnostics and a fix for the package's own Composer scripts.

### Improved

- **All argument errors of a tag are reported.** Fluid's parser stops at the first missing required
  argument or at an undeclared argument and turns the tag into text. FluidStan now lists every
  missing and every undeclared argument, keeps the tag intact so everything nested in it is still
  analysed, and no longer produces the follow-up errors this caused, such as "templating tags not
  properly nested" or values leaking out of the tag's attributes.
- **Clearer message for undeclared component arguments.** Fluid's "Invalid arguments supplied to
  component <shop:atom.price>: string" names the argument after the colon, which reads like a type.
  FluidStan reports it as `Component shop:atom.price has no argument "string".`

### Fixed

- `composer fluidstan` and `composer fluidstan:example` failed inside the package itself, because the
  package is its own Composer root and its binary is not linked into `vendor/bin`.

### Documentation

- Fluid casts scalars before validating ViewHelper arguments, so a string passed to an `int` argument
  is accepted at runtime and not reported. The README explains this with links to the Fluid source;
  an opt-in strict mode is on the roadmap, which now sits at the top of the README.

## 0.1.0

First release. Static analysis for [TYPO3 Fluid](https://github.com/TYPO3/Fluid) templates and
components, powered by [PHPStan](https://phpstan.org). FluidStan parses templates without rendering
them, resolves the PHP types behind their variables and reports what Fluid would otherwise only reveal
at runtime.

### Reports

- Property paths that cannot be resolved, following Fluid's lookup order: array key, `ArrayAccess`,
  PSR container, getter, isser, hasser, public property
- Values that cannot be rendered, such as an object without `__toString()` or an array
- `f:for` over non-iterable values
- ViewHelper and component arguments whose type does not satisfy the argument definition, following
  the rules of Fluid's `StrictArgumentProcessor`
- Non-numeric operands in `{a + b}`
- Undefined variables in components, whose scope is closed
- Unknown ViewHelpers, unknown or missing arguments, unknown component slots, `f:render` of undefined
  sections, deprecated ViewHelper usage and syntax errors

Errors are reported in PHPStan's format, mapped back to template file and line. Each one carries an
identifier (`fluidstan.property.unresolvable`, `fluidstan.argument.type`), so individual rules can be
ignored.

### Typing

Fluid components (Fluid 4.3+, TYPO3 13.4+) declare their argument types with `f:argument` and are
analysed out of the box. Plain templates, partials and layouts have `mixed` variables unless they are
declared through a `@var` annotation in an `f:comment`, where PHPDoc types are allowed.

### Requirements

PHP 8.2 or newer, Fluid 4.4 or 5.x and PHPStan 2. Argument checks always follow Fluid 5's strict
validation, so on Fluid 4, whose runtime is lenient, they report what would fail after an upgrade.
MIT-licensed.

### Known limitations

- Sections are analysed with `mixed` variables.
- Layouts and partials are not analysed with the types of their call sites.
- No baseline file and no result cache yet.
