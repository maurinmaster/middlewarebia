---
name: ai-memory-retrieval
description: "Use this skill for any request whose goal is read-only retrieval from ai-memory: project history, prior context, decisions, rules, gotchas, recent activity, full wiki pages, or status/briefing. Trigger by semantic intent rather than exact wording, including when ai-memory is not named."
---
<!-- ai-memory-managed: routing-skill -->

# ai-memory retrieval

Use this skill for read-only ai-memory lookups, catch-up, and evaluating remembered project knowledge before you design, debug, or edit.

## Tools in this cluster

- `memory_query` searches the current project's wiki for prior decisions, gotchas, procedures, rules, and session notes.
- `memory_recent` lists the most recently updated pages when the user wants a light activity check.
- `memory_read_page` fetches a full page body after a search hit or direct path lookup. Pass `include_related: true` (optional `related_depth`, default 1, hard cap 3) to also walk the link graph outward and return a `related` array of reachable pages, each with its hop `depth` and edge `direction` (`link`/`backlink`); default off omits it.
- `memory_read_session_observations` reads one session's raw hook observations (prompts, tool calls, stops) in capture order, paged and body-capped, when the user asks what actually happened in a session or wants to check a compiled page against its evidence.
- `memory_status` reports whether ai-memory is healthy and how large the knowledge base is.
- `memory_briefing` returns a structured read-only snapshot for agent consumption, including a bounded `pinned` list of the project's pinned standing-context pages (present only when the project has pins).
- `memory_explore` returns a prose digest when the user asks for an open-ended catch-up.

## Project scope

Read the exact names from the nearest `.ai-memory.toml` (`workspace = "default"`, `project = "maurinmaster-middlewarebia"`).
For cross-project retrieval, `global=true` must omit `workspace`, `project`, and `scopes`.
