---

name: erp-code-review
description: Reviews ERP code changes, diffs, refactors, and pull requests for correctness, risk, simplicity, consistency, and maintainability.
-----------------------------------------------------------------------------------------------------------------------------------------------

# ERP Code Review

Review as a senior engineer responsible for a live production ERP.

## Review order

1. Correctness — Does it solve the requirement without unintended behavior?
2. Data / production risk — Could it corrupt data or affect production paths?
3. Scope — Is the change limited to what is necessary?
4. Consistency — Does it follow existing architecture and conversion patterns?
5. Maintainability — Is it clear and easy to maintain?

## Flag

* Hidden or automatic field mapping.
* Missing explicit `mapRow()` fields.
* Large refactors for small changes.
* New abstractions with no clear need.
* Business logic in controllers.
* Unrelated file changes.
* Unrequested behavior changes.
* Data integrity or backward-compatibility risks.

## Positive

* Explicit field mapping.
* Thin controllers.
* Reuse of existing conversion utilities without hiding business rules.
* Clear naming and early returns.
* Small, focused diffs.

## Output

```markdown
## Summary
[Approve / Approve with notes / Request changes]

## Root cause / intent
...

## Findings

### Critical
- ...

### Suggestions
- ...

### Positive
- ...

## Side effects / test plan
- ...
```

Severity:

* Critical — incorrect behavior, data integrity risk, or production breakage.
* Suggestion — optional readability, consistency, or hardening.
* Positive — good pattern worth keeping.
