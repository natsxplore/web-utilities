---

name: erp-safe-change
description: Implements or fixes ERP code safely with minimal production risk. Use for features, bugs, refactors, Laravel services, controllers, conversions, and data transfer logic.
--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

# ERP Safe Change

## Workflow

1. Read affected code and trace the current flow.
2. State the requirement or root cause.
3. Identify the smallest correct change.
4. Implement only the required change.
5. Validate behavior and data integrity.
6. Run relevant tests.

## Analyze

* Verify assumptions in the code.
* Identify existing patterns to reuse.
* Check affected data flow and dependencies before editing.

## Conversion changes

For a new table conversion:

* Follow the existing conversion class pattern.
* Use explicit `mapRow()`.
* Register in `ConversionRegistry`.
* Add required UI/controller wiring.

Do not introduce generic auto-mapping.

## Finish

Report:

```markdown
## Requirement / Root cause
...

## Change
...

## Why this approach
...

## Side effects / validation
...

## Changes made
...
```

## Do not

* Refactor unrelated code.
* Rewrite code unnecessarily.
* Add unnecessary abstractions or dependencies.
* Add migrations or utility configuration to the application database unless required.
* Commit changes unless requested.
