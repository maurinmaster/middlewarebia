---
name: ai-memory-durable-pages
description: "Use this skill for any explicit wiki mutation in ai-memory: saving durable or time-bounded project knowledge, recording a rule or annotation, updating a note, or deleting a memory page. Trigger by semantic intent rather than exact wording; routine session capture is not a durable-page request."
---
<!-- ai-memory-managed: routing-skill -->

# ai-memory durable pages

Use this skill only for deliberate durable wiki mutations. Routine session capture is automatic, and permanent notes require an explicit user request.

## Tools in this cluster

- `memory_write_page` writes a durable wiki page for permanent project knowledge.
- `memory_delete_page` removes a durable wiki page by exact path.
- `memory_restore_page` lists a page's checkpoints or writes an earlier version back.

## Writing durable memory

Write a page only when the user explicitly asks to remember something permanently, save a note, add an annotation, or record project knowledge. Put the page title as a `# H1` on the first line of the body and omit the separate title argument.

Project conventions:
- `_rules/<slug>.md`: durable project convention or inviolable business rule.
- `decisions/<short>.md`: architectural decision record (ADR).
- `gotchas/<slug>.md`: failure modes, platform bugs, and critical workarounds.
- `procedures/<slug>.md`: operational step-by-step guides.
- `concepts/<slug>.md`: evergreen domain and architectural concepts.
