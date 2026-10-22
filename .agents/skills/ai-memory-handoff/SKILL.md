---
name: ai-memory-handoff
description: "Use this skill for any request whose goal is session continuity across agents or time: finding a pending handoff, resuming previous work, saving next-session context, wrapping up, or discarding a mistaken handoff. Trigger by semantic intent rather than exact wording."
---
<!-- ai-memory-managed: routing-skill -->

# ai-memory handoff

Use this skill for single-use cross-session handoffs. Handoffs are for the next agent, not durable project documentation.

## Tools in this cluster

- `memory_handoff_list` is the read-only inspect path: returns open handoffs without claiming or expiring them.
- `memory_handoff_accept` consumes one open handoff.
- `memory_handoff_begin` creates a terse next-session handoff when wrapping up.
- `memory_handoff_cancel` expires a mistaken pending handoff by exact handoff id.
