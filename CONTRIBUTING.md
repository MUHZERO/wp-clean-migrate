# Contributing

## Scope

This project is a migration utility. Changes should prioritize:

- migration safety
- predictable reruns
- straightforward code paths
- minimal behavioral surprises

## Before Opening a Change

- test on a staging dataset where possible
- keep changes focused
- avoid broad refactors unless they improve maintainability or testability directly
- document behavior changes in `CHANGELOG.md`

## Development Notes

Install dev dependencies:

```bash
composer install
```

Run tests:

```bash
vendor/bin/phpunit
```

Run a simple syntax pass:

```bash
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Coding Expectations

- use plain PHP compatible with WordPress and WooCommerce conventions
- keep CLI behavior stable unless a change is required for correctness or safety
- prefer small, testable classes and helpers
- avoid introducing framework dependencies

## Pull Requests

Include:

- a clear summary
- migration impact or risk notes
- test notes
- any limitations or follow-up work
