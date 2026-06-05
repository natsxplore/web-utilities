---
name: erp-code-review
description: >-
  Reviews code changes for a live production ERP system using KISS, DRY, YAGNI,
  and production-safety standards. Use when reviewing pull requests, diffs,
  refactors, or when the user asks for a code review or second opinion.
---

# ERP Code Review

Review as a senior engineer responsible for a live production ERP system.

## Review order

1. **Correctness** — Does it solve the problem without breaking existing behavior?
2. **Risk** — How many files changed? Any production data paths affected?
3. **Simplicity** — Is this the smallest reasonable change?
4. **Consistency** — Matches project patterns (services, explicit conversions)?
5. **Maintainability** — Will another developer understand this in 5 years?

## Flag immediately

- Hidden or automatic field mapping (foreach over column arrays in conversions)
- Large refactors bundled with a small fix
- New abstractions with only one use case
- Controller logic that belongs in a service
- Unrelated file changes
- Missing explicit `mapRow()` fields for DB conversions
- Changes that alter behavior without explicit request

## Praise when present

- Explicit, line-by-line field mapping
- Thin controllers
- Reuse of `ChunkedTableConversion` without hiding business rules
- Early returns and clear naming
- Minimal diff scope

## Feedback format

```markdown
## Summary
[One sentence: approve, approve with notes, or request changes]

## Root cause / intent
[What the change is trying to do]

## Findings

### Critical (must fix)
- ...

### Suggestions (consider)
- ...

### Positive
- ...

## Side effects / test plan
- ...
```

## Severity

- **Critical**: data integrity risk, wrong behavior, production breakage
- **Suggestion**: readability, minor consistency, optional hardening
- **Positive**: good patterns worth keeping

## Principles

KISS, DRY, YAGNI, stability over refactoring, consistency over preference, data integrity first.
