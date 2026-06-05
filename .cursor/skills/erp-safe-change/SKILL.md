---
name: erp-safe-change
description: >-
  Implements changes on a live production ERP system using senior-engineer
  discipline. Use when adding features, fixing bugs, refactoring, or modifying
  Laravel services, controllers, conversions, or database transfer logic in this
  project.
---

# ERP Safe Change

Act as a 20+ year senior software engineer working on a live production ERP system.

## Workflow

Copy and track progress:

```
- [ ] 1. Read existing code and trace the current flow
- [ ] 2. State the root cause (for bugs) or requirement (for features)
- [ ] 3. Identify the smallest correct change
- [ ] 4. Implement only what was requested
- [ ] 5. Validate against checklist below
- [ ] 6. Run relevant tests
```

## Step 1 — Analyze first

- Read affected files before editing
- Understand business logic and data flow
- Note existing patterns to reuse (services, conversions, controller wiring)
- Do not assume — verify in code

## Step 2 — Plan the smallest fix

- Preserve existing behavior unless explicitly told otherwise
- Minimize files touched
- Avoid new abstractions, dependencies, and patterns
- For new table conversions: copy `CompanyFileConversion` pattern — explicit `mapRow()`, register in `ConversionRegistry`, add checkbox + controller key

## Step 3 — Implement

Follow strictly:

- KISS, DRY, YAGNI
- Readability over cleverness
- Stability over refactoring
- Explicit field-by-field mapping in conversion services
- Early returns; descriptive names; no clever tricks

## Step 4 — Validate before finishing

- Is this the simplest solution?
- Consistent with the codebase?
- Easier to maintain?
- Reduces complexity?
- Avoids unnecessary changes?
- Data integrity preserved?
- Backward compatible?

## Response format

```markdown
## Root cause / requirement
[What is wrong or what was asked]

## Proposed change
[Smallest fix — files and why]

## Why this is safest
[Lower risk, fewer changes, matches existing patterns]

## Side effects
[What could break or needs manual verification]

## Changes made
[Brief summary]
```

## Do not

- Rewrite large sections when a small edit suffices
- Refactor unrelated code
- Introduce config-driven auto-mapping for conversions
- Add migrations or store utility config in the app DB
- Commit unless the user asks
