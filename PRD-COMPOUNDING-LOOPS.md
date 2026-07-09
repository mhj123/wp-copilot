# PRD — Compounding Loops
## Memory, Learning → Procedure, Canonical Nodes, and Evals

Status: draft for review
Extends: `prd.md` (core PRD). All core principles apply unchanged — native
WordPress first, human-in-the-loop AI (proposals only, never direct writes),
atomic notes, structure-as-taxonomy, single-user.

**Plugin boundary:** features F1–F6, S2, S3 land in the core `wp-copilot`
plugin. Everything graph-related (provenance predicates, edge-writing API,
Connections-panel display) lands in the separate `wcp-graph` plugin and is
specified in §4 as its own iteration (G1–G3). The core plugin must never
hard-depend on `wcp-graph`: every feature works fully without it, and
provenance edges are written only when `wcp-graph` is active (feature
detection via `class_exists('WCP_Graph')`), silently skipped otherwise.

---

## 1. Purpose & Vision

Work Copilot already has every *node* of a learning flywheel — capture,
structure, retrieval, action, reflection — but the *arrows* between them are
missing or weak. This PRD specifies the arrows:

```
        ┌─────────────────────────────────────────────┐
        ▼                                             │
   CAPTURE ──► STRUCTURE ──► RETRIEVAL ──► ACTION ──► REFLECTION
   (chat →     (canonical    (RAG +        (plans,    (session notes,
    items)      nodes,        graph-       checklists, playbook updates,
                provenance)   scoped)      evals)      consolidation)
```

Each loop compounds: executed action plans improve the playbooks that
generate the next plans; reflections improve the memory that scopes the next
retrieval; canonical nodes keep the taxonomy dense instead of fragmenting.

Six feature areas, in build order:

| # | Area | Loop it closes |
|---|------|----------------|
| F1 | Capture: save message as item | chat → corpus |
| F2 | Session reflection (note-to-self) | session → memory |
| F3 | Learning → Procedure (playbook updates) | execution → playbook |
| F4 | Action plan → Page template (repeatable checklist) | plan → recurring practice |
| F5 | Canonical nodes (suggest-contexts v2 + canonicalize) | new content → existing structure |
| F6 | Evals codified in structure (spec pages + check actions) | output → spec → better output |

Supporting infrastructure (built alongside, not after):

| # | Area | Plugin |
|---|------|--------|
| G1–G3 | Graph provenance: predicates, edge API, panel display (§4) | `wcp-graph` |
| S2 | Memory consolidation (scheduled review) | `wp-copilot` |
| S3 | Soul/mission amendments | `wp-copilot` |

---

## 2. Shared Data Model

No new post types. No new tables. Everything maps to existing constructs:

| Concept | Storage |
|---|---|
| Memory / Learning / Info item | native `post` + `_wcp_item_type` meta (existing pattern) |
| Memory metadata | post meta: `_wcp_memory_type`, `_wcp_memory_confidence`, `_wcp_memory_last_confirmed` (new), `_wcp_memory_source` |
| Playbook / best-practice doc | native `page` (existing docs-page + tag matching mechanism) |
| Spec | native `page` with conventional headings (see F6) |
| Eval report | native `post` under the spec's context, `_wcp_item_type = eval_report` |
| Provenance | edges in `wp_wcp_edges` (wcp-graph, existing) with seeded predicates |
| Checklist template | existing page-template definition (`WCP_Page_Template_Manager` format) |
| Recurrence | existing `WCP_Page_Scheduler` |
| All AI outputs | existing proposal/transient + accept/dismiss flow, logged via `WCP_AI_Logger` |

### Provenance (stored in `wcp-graph`)

Provenance predicates, the edge-writing API, and their display are specified
in §4 (G1–G3) as iterations to the `wcp-graph` plugin. Core features
reference them throughout but degrade gracefully when the plugin is absent.

---

## 3. Features

### F1 — Save assistant message as item

**User story:** while chatting, the assistant says something worth keeping.
One click saves it into the corpus without leaving the chat.

**Behavior:**

- Every assistant message in the chat UI gets a "Save as item" affordance.
- Clicking opens a small inline form pre-filled with:
  - **Title** — AI-condensed one-liner of the message (generated client-side
    from the first sentence; no extra AI call required for MVP)
  - **Type** — `learning` | `info` | `task` | `memory` (default `learning`)
  - **Context** — defaults to the page the chat is scoped to; editable via
    the existing context picker. If type = `memory`, context defaults to the
    Memories page term instead.
  - **Content** — the full message text, editable before save.
- Saving creates a native post with `_wcp_item_type`, assigns the
  `wcp_context` term(s), stores `_wcp_source_conversation_id` and the message
  index, and (if embeddings enabled) generates an embedding.
- On accept, one `learned_from → <conversation>` provenance edge is proposed
  as part of the same action (single click accepts both).

**Guardrails:** this is a *user-initiated* save of *visible* content, so it
is not a proposal — the user's click **is** the acceptance. Still logged via
`WCP_AI_Logger` with the message snapshot.

**REST:** `POST /wcp/v1/conversations/{id}/messages/{index}/save-as-item`
with `{title, type, context_ids, content}`.

**Acceptance criteria:**
- A saved learning appears under its context page immediately and is
  retrievable via RAG in the next chat turn.
- Deleting the conversation does not delete saved items.

---

### F2 — Session reflection (note-to-self)

**User story:** at the end of a working session, the assistant reflects and
proposes up to three atomic memories: something learned about the mission,
about how the user works, and about itself (the assistant).

**Behavior:**

- Trigger: explicit "Reflect on this session" button in the chat UI
  (MVP). No automatic/cron trigger — consistent with "no autonomous
  behavior".
- Extends `WCP_Memory_Manager::extract_memories()` with a `reflection` mode:
  the prompt asks for **at most one** memory in each of three categories:
  - `mission_insight` — something learned about the user's goals/mission
  - `user_pattern` — something learned about how the user works
  - `self_note` — something the assistant learned about its own approach
    (what worked, what it should do differently)
- Each proposed memory is atomic (title + 1–3 sentences), carries
  `confidence`, and is presented in the existing accept/dismiss proposal UI.
- Accepted memories are saved via the existing `save_memory()` path, with
  `_wcp_memory_type` set to the category and `_wcp_memory_last_confirmed`
  set to now. Provenance edges: `learned_from → conversation`, and
  `applies_to → page` when the insight concerns a specific context.

**Retrieval rules (important — drift control):**

- `mission_insight` and `user_pattern` memories join RAG retrieval as today
  (top-5 relevant).
- `self_note` memories are **capped at 3 in any context pack**, most
  recently confirmed first. They accumulate as observations, but durable
  identity lives in the mission/soul document and changes only via S3
  amendments. This is deliberate: an AI feeding its own notes back into its
  own prompt can compound flattery and persona drift; the cap plus the
  amendment ritual keeps growth legible and human-governed.

**Acceptance criteria:**
- Reflection never produces more than 3 proposals.
- A dismissed reflection category is not re-proposed for the same
  conversation.
- Self-notes in any prompt context never exceed 3.

---

### F3 — Learning → Procedure (playbook updates)

**User story:** an action plan was executed; reality diverged from the plan
(steps reordered, added, skipped). The system proposes an update to the
matching best-practice doc so the *next* plan is better.

**Behavior:**

- Trigger points (both explicit, user-initiated):
  1. Marking the last item of an action plan complete surfaces a
     "Update playbook from this plan" suggestion chip.
  2. A standalone AI action "Update playbook" available on any completed or
     partially completed action plan's parent item.
- The action:
  1. Loads the original plan proposal snapshot from `WCP_AI_Logger` (the
     logger already stores output snapshots).
  2. Loads the current state of the plan's items (titles, order,
     completion, edits, any items added after acceptance).
  3. Loads the matched best-practice doc via the existing tag-matching
     mechanism used by "action plan from context".
  4. Asks the AI to produce a **diff-style proposal** against the doc:
     `{additions: [], modifications: [{before, after, rationale}],
     deletions: [], summary}`.
- Proposal UI renders the diff (before/after per change) for granular
  accept/dismiss — accepting applies only the accepted hunks to the doc.
- On acceptance: provenance edges `learned_from → <plan parent item>` and
  `applies_to → <playbook page>` from a new `learning` item that records the
  one-line summary of what changed and why (the doc gets the change; the
  learning item is the audit trail and RAG surface).

**Edge case:** no matching playbook doc exists → the proposal offers to
create one (a new page under the docs section, seeded from the executed
plan), reusing the existing page-creation proposal flow.

**REST:** `POST /wcp/v1/ai/item-action` with `action = update_playbook`,
`item_id`, returning a proposal; accepted via the existing
`execute_proposal` path extended with hunk selection.

**Acceptance criteria:**
- The diff never rewrites the whole doc; unaccepted hunks leave their text
  untouched byte-for-byte.
- Running the action twice on the same unchanged plan proposes no changes
  the second time ("playbook already reflects this plan").

---

### F4 — Action plan → Page template (repeatable checklist)

**User story:** an action plan turned out to be a *recurring* procedure
(weekly review, release checklist). Convert it into a page template so the
scheduler can instantiate it on a cadence, or so new subpages inherit it.

**Behavior:**

- New AI action on any action plan (or any heading with items):
  "Convert to template".
- The proposal maps plan steps to the existing
  `WCP_Page_Template_Manager` definition format, with the user choosing per
  step whether it becomes:
  - an **item** (checklist entry under a heading), or
  - an **in-page field** (for steps that capture a value rather than a
    to-do, e.g. "record this week's numbers").
  The AI pre-selects a sensible mapping; every mapping is editable in the
  proposal UI.
- The user chooses the target:
  1. **Parent page template** — future child pages of a chosen page inherit
     it (existing inheritance behavior).
  2. **New template page** — a standalone template.
  3. **Scheduled checklist** — target 2 plus a `WCP_Page_Scheduler`
     schedule (daily/weekly/monthly/custom), so instances are generated
     automatically. Instance pages get an `instantiates → template`
     provenance edge and land with all checklist items unticked.
- Scheduled instances are ordinary pages — reviewable, editable, and
  visible to the assistant like everything else. This is the substrate for
  "repeating checklists for regular actions".

**Persistent (non-repeating) checklists** are the degenerate case: target 2
with no schedule; the page holds current-state-vs-ideal-state and is
reviewed via F6's compare action rather than re-instantiated.

**Acceptance criteria:**
- A converted weekly checklist generates its first instance at the next
  scheduled tick with all steps present and unticked.
- Deleting the template does not delete already-generated instances.

---

### F5 — Canonical nodes

Two halves: **suggest-contexts v2** (at capture time) and **canonicalize**
(periodic repair).

#### F5a — Suggest-contexts v2

The current implementation (`class-rest-api.php`, `suggest_contexts`) dumps
every context term name flat into the prompt and asks for raw IDs. Failure
modes: no hierarchy (AI can't tell `Launch` under Acme from `Launch` under
Beta), no semantic pre-filter (prompt grows linearly with the taxonomy and
recall collapses), no confidence, and the result applies without a proposal
surface. Replace it:

1. **Candidate generation (retrieval, not prompting).** Embed the item text;
   collect candidates from two sources:
   - contexts of the top-K semantically similar existing items
     (`find_similar_posts`, K≈10) — "items like this live here";
   - top-N semantically similar pages/headings directly (N≈10) — catches
     contexts that have few items yet.
   Union, dedupe, cap at ~20 candidates. Fallback when embeddings are
   disabled: keyword match of item tokens against term names/paths (degraded
   but functional; the UI notes reduced quality).
2. **Ranking (small AI call).** Present each candidate with its **full
   hierarchical path** ("Projects › Acme › Launch") and a one-line summary
   (page excerpt or top item titles). AI returns strict JSON:
   `[{term_id, confidence: 0–100, rationale: "..."}]`, max 3 suggestions,
   and may return an empty array ("none fit") — which the UI must surface
   as a first-class outcome, optionally suggesting a *new* node (name +
   parent) via the existing structure-proposal flow.
3. **Proposal UX.** Suggestions render as chips on the item composer
   (path + confidence); clicking a chip assigns that context. Nothing is
   assigned automatically.

**When it runs:** on demand (button in the composer) in MVP. A later option
can auto-run on blur of the title field — still assign-on-click only.

**Acceptance criteria:**
- Prompt size is bounded by the candidate cap, independent of taxonomy size.
- Ambiguous names are disambiguated by path in both prompt and UI.
- "No suitable context" is a supported result, offering new-node creation.

#### F5b — Canonicalize (merge duplicates)

- AI action, run on demand or from a scheduled review page (same pattern as
  S2): scan for near-duplicate nodes — pairs of pages/headings with high
  embedding similarity on title+content and overlapping item sets.
- Proposal per pair: merge B into A — items and child structure reassigned
  to A, B's edges rewritten to A, B trashed (with `supersedes` edge from A
  recorded for audit). Full effect list shown before acceptance; merge is a
  single transaction-like operation in the repository layer.
- Never proposed for nodes the user has explicitly marked distinct
  (`_wcp_not_duplicate_of` meta, set when a merge proposal is dismissed —
  a dismissed pair is not re-proposed).

---

### F6 — Evals codified into structure

**Concept:** a **spec is a page**, its **criteria and examples are items**,
and an **eval is an AI action** producing a scored report as a proposal.
Output-iteration ("generate → compare to examples → refine") and
state-review coaching ("current state vs ideal state checklist") are the
same mechanism with different subjects.

**Spec page convention** (no new post type; a `_wcp_is_spec` meta flag on
the page enables the eval actions):

- Heading **Requirements** — each item is one criterion (atomic,
  testable phrasing).
- Heading **Examples** — each item is a worked example: content holds the
  example output, or an input→expected-qualities pair.
- Heading **Anti-examples** (optional) — what bad looks like.

Page templates (F4) make specs cheap to stamp out: ship a built-in "Spec"
template with these headings.

**Action: `evaluate_against_spec`.** Inputs: a spec page + a subject —
either (a) a draft/output (item content, generated page content, Hermes
result) or (b) a *live page* whose current state is being reviewed against
an ideal-state spec.

- Context pack: the criteria items, up to N examples/anti-examples
  (bounded), and the subject.
- Output: strict JSON —
  `{overall: 0–100, criteria: [{criterion_item_id, verdict:
  "pass|partial|fail", rationale, suggested_delta}], summary}`.
- The report is a **proposal** with two acceptance targets:
  1. **Save report** — persists as an `eval_report` item under the spec's
     context with `evaluated_against → spec` edge; scores over time become
     readable history on the spec page (simple list in MVP; sparkline
     later).
  2. **Apply deltas** — each `suggested_delta` is individually acceptable:
     for output-iteration it revises the draft (feeding the existing
     expand/rewrite flow); for state-review it creates task items under the
     reviewed page.
- **Example iteration:** from any report, a one-click action proposes
  promoting the (possibly corrected) subject into the spec's Examples
  heading — this is how the spec itself improves. Examples carry
  `learned_from → <report>` edges.

**Coaching loop composition:** a scheduled checklist (F4) whose template is
a spec + an `evaluate_against_spec` chip on instantiation = the recurring
"review and confirm current state against ideal state" ritual, entirely
from existing parts.

**Acceptance criteria:**
- Evaluating the same subject against the same spec twice yields the same
  criteria set (report is deterministic in *shape*, not necessarily scores).
- A spec with zero examples still evaluates against criteria alone.
- Reports are inert unless accepted; dismissing a report leaves no trace
  except the AI log entry.

---

## 4. wcp-graph Plugin Iterations (G1–G3)

These land in `wcp-graph` (currently 0.2.0) and follow its existing design
(`CONTEXT-GRAPH-DESIGN.md`): one `edges` table, predicates as a flat
taxonomy, AI may propose edges but never write them.

### G1 — Seeded provenance predicates

Created on `wcp-graph` activation as `wcp_predicate` terms (user-editable,
never enforced):

| Predicate | Inverse label | Used by |
|---|---|---|
| `learned_from` | `taught` | F2, F3 — memory/learning → source conversation or plan |
| `applies_to` | `informed by` | F2, F3 — memory/learning → page/heading it concerns |
| `supersedes` | `superseded by` | S2, F5b — consolidated memory → retired memories; merged node → trashed node |
| `produced_by` | `produced` | Hermes results, generated pages → delegation/action |
| `evaluated_against` | `evaluated` | F6 — eval report → spec page |
| `instantiates` | `instantiated as` | F4 — scheduled checklist page → its template source |

Seeding is idempotent and never overwrites user edits to labels/inverses.

### G2 — Proposal edge API

A small public API the core plugin calls when present:

- `WCP_Graph::propose_edges( array $triples, string $proposal_id )` —
  attaches pending edges to a core proposal; they are written **only when
  that proposal is accepted** (core fires an action hook on acceptance,
  e.g. `wcp_proposal_accepted`, which `wcp-graph` subscribes to). Same
  guardrail as content: dismissal discards the edges with no trace beyond
  the AI log.
- One conversation/message endpoint problem to solve here: conversations
  are not posts, so `learned_from → conversation` needs an object.
  Resolution: edges store the conversation as a **literal**
  (`object_value = conversation_id`) rather than an entity — consistent
  with the existing literal rule ("if you'd never traverse from it, it's a
  literal"). If conversations later become traversable, a migration
  upgrades literals to entity references.

### G3 — Provenance display

The existing Connections panel groups provenance edges under a
"Provenance" section (read-only rendering is automatic since they are
ordinary edges; this iteration is only the grouping + conversation-literal
display, linking to the conversation view where one exists).

---

## 5. Supporting Infrastructure (`wp-copilot`)

### S2 — Memory consolidation (scheduled review)

- A built-in "Memory Review" page template + optional schedule (default:
  weekly, opt-in). The instance page carries one chip: "Consolidate
  memories".
- The action clusters existing memories by embedding similarity and
  proposes, per cluster: **merge** (one consolidated memory superseding
  N originals — originals set to `draft` status, `supersedes` edges
  recorded), **confirm** (bump `_wcp_memory_last_confirmed`), or **retire**
  (stale/contradicted — set to draft). Contradictions between memories are
  flagged for the user to resolve rather than auto-resolved.
- Confidence decay: retrieval ranking multiplies similarity by a recency
  factor on `_wcp_memory_last_confirmed` (half-life ~90 days, filterable).
  Nothing is ever hard-deleted by the system.

### S3 — Soul/mission amendments

- The mission document (loaded by `WCP_Mission_Loader`) becomes amendable
  only through proposals: F2 `mission_insight` memories accumulate as
  observations; a "Propose mission amendment" action (on the mission page,
  or offered when ≥5 unincorporated mission insights exist) drafts a
  **diff** against the current mission text.
- The proposal UI shows before/after; acceptance saves through the normal
  page-update path so **native WP revisions** provide version history for
  free. Incorporated insights get `applies_to → mission` edges and are
  marked incorporated (excluded from future amendment prompts).
- Amendments are expected to be rare and small — constitution, not
  scratchpad. The assistant's context pack always uses the *current*
  mission text only, never the insight backlog.

### Hermes context packs (touchpoint, not a feature here)

Delegation briefs should be assembled from this same substrate: mission +
top memories (respecting the F2 caps) + page structure + matched playbooks
+ relevant spec (if the delegated work has one, Hermes receives its
criteria and examples — and the handback can be pre-scored via F6 before
the user reviews it). Hermes results carry `produced_by` edges on
acceptance. Detailed spec belongs in the wcp-delegation PRD.

---

## 6. REST Surface (summary)

All under `wcp/v1`, all `POST` unless noted, all requiring the existing
auth/nonce model:

| Route | Feature |
|---|---|
| `/conversations/{id}/messages/{i}/save-as-item` | F1 |
| `/ai/reflect` `{conversation_id}` | F2 |
| `/ai/item-action` `action=update_playbook` | F3 |
| `/ai/item-action` `action=convert_to_template` | F4 |
| `/ai/suggest-contexts` `{title, content}` (replaces current) | F5a |
| `/ai/canonicalize` `{scope_term?}` | F5b |
| `/ai/evaluate` `{spec_page_id, subject}` | F6 |
| `/ai/consolidate-memories` | S2 |
| `/ai/propose-mission-amendment` | S3 |

Every route: bounded context pack in, strict JSON out, proposal transient,
`WCP_AI_Logger` entry with prompt + input/output snapshots + decision.

---

## 7. Sequencing

| Phase | Ships | Plugin | Rationale |
|---|---|---|---|
| 1 | F1, F2 | `wp-copilot` | Cheapest; immediately increases capture; exercises no new infrastructure |
| 2 | F3, F4 | `wp-copilot` | The procedure loop — where compounding starts; F4 reuses template manager + scheduler as-is |
| 3 | F5a, then F5b | `wp-copilot` | Fixes the known-broken feature; F5b needs F5a's retrieval plumbing |
| 4 | G1–G3, S2 | `wcp-graph` + core | Graph iteration can ship any time after Phase 1 (core hooks are cheap to add up front); S2 requires enough memory volume to be worth it |
| 5 | F6 | `wp-copilot` | Largest surface; benefits from templates (Phase 2) and canonical structure (Phase 3) being solid |
| 6 | S3, Hermes pack | `wp-copilot` | Rare-path features; need accumulated insights to be meaningful |

The `wcp_proposal_accepted` hook (G2's core-side half) should ship in
Phase 1 even though `wcp-graph` may consume it later — it is one
`do_action()` call and avoids retrofitting.

Each phase is independently shippable and independently valuable.

## 8. Out of Scope

- Any automatic/cron-triggered AI call (scheduler creates *pages*, never
  invokes AI; every AI call remains user-initiated)
- Auto-assignment of suggested contexts without a click
- Hard deletion of memories by the system
- Multi-user memory namespacing
- Numeric "AI personality" tuning — identity evolves only via S3 text
  amendments

## 9. Open Questions

1. F4: should in-page *fields* support types (date, number, yes/no) in MVP,
   or text-only first? (Recommend text-only.)
2. F6: where does eval score history render — on the spec page, the subject
   page, or both? (Recommend spec page first.)
3. S2: is 90-day half-life right for `user_pattern` memories, or should
   decay be per-type (mission insights decay slower)?
4. F5b: minimum corpus size before canonicalize is offered? (Recommend
   hiding the action below ~30 nodes.)
