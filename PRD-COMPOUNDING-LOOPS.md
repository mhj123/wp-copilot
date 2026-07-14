# PRD — Compounding Loops
## Memory, Learning → Procedure, Canonical Nodes, Evals, and Preparedness

Status: draft for review; F1 (save-message-as-item, incl. verbatim/summary/
multiple modes) shipped 2026-07-09. The legacy always-on "Relevant Memories"
injection was disabled 2026-07-09 (see P5, which replaces it).
Extends: `prd.md` (core PRD). All core principles apply unchanged — native
WordPress first, human-in-the-loop AI (proposals only, never direct writes),
atomic notes, structure-as-taxonomy, single-user — with ONE explicit,
narrowly-scoped amendment proposed in P8 (scheduled delegation).

**Plugin boundary:** features F1–F6, P1–P8, S2, S3 land in the core
`wp-copilot` plugin (P7/P8 partly in `wcp-delegation`). Everything
graph-related (provenance predicates, edge-writing API, Connections-panel
display) lands in the separate `wcp-graph` plugin and is specified in §5 as
its own iteration (G1–G3). The core plugin must never hard-depend on
`wcp-graph`: every feature works fully without it, and provenance edges are
written only when `wcp-graph` is active (feature detection via
`class_exists('WCP_Graph')`), silently skipped otherwise.

**The preparedness thesis (governs all P-features).** The constitution
forbids autonomous AI, but between "reactive" and "autonomous" lies the
useful middle: **prepared**. The agent never acts while the user is away —
but every arrival finds the work staged. The enabling division of labor:

1. **Cron creates surfaces and questions** — the scheduler makes pages,
   never AI calls.
2. **Deterministic checks run constantly and free** — plain queries over
   the user's own data (due dates, staleness, unreviewed instances).
   These render instantly, with AI unconfigured, on every page view.
3. **AI computes on arrival, one click away** — bounded context pack,
   logged, human-triggered.
4. **Nothing commits without acceptance** — unchanged, ever.

Corollary: **every loop's exhaust is the next loop's fuel.** The AI actions
log (accept/dismiss decisions), plan-vs-actual divergence, checklist
instance history, and delegation outcomes are already being produced; the
P-features exist to recycle them.

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

Six foundation areas (F-series), in build order:

| # | Area | Loop it closes |
|---|------|----------------|
| F1 | Capture: save message as item — **SHIPPED** | chat → corpus |
| F2 | Session reflection (note-to-self) | session → memory |
| F3 | Learning → Procedure (playbook updates) | execution → playbook |
| F4 | Action plan → Page template (repeatable checklist) | plan → recurring practice |
| F5 | Canonical nodes (suggest-contexts v2 + canonicalize) | new content → existing structure |
| F6 | Evals codified in structure (spec pages + check actions) | output → spec → better output |

Eight preparedness areas (P-series, §4) — the proactive-copilot layer:

| # | Area | Exhaust it recycles |
|---|------|---------------------|
| P1 | Daily Briefing + open-loops engine | live task/delegation/checklist state |
| P2 | Goal spine (`advances` edges, orphan & drift detection) | item ↔ goal structure |
| P3 | Dismissal mining → Proposal Style Guide | AI actions log decisions |
| P4 | Calibration memory (plan expected vs actual) | plan proposal snapshots |
| P5 | Tiered context + graph-expanded retrieval | taxonomy + edges at query time |
| P6 | Checklist trends, field time-series, ideal-state gap | scheduled instance history |
| P7 | Delegation quality gates (pre-flight, scored handback, ledger) | delegation outcomes |
| P8 | Scheduled delegation (constitutional amendment) | standing user instructions |

Supporting infrastructure (built alongside, not after):

| # | Area | Plugin |
|---|------|--------|
| G1–G3 | Graph provenance: predicates, edge API, panel display (§5) | `wcp-graph` |
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
| Goal | native `page` + `_wcp_is_goal` meta (P2) |
| Proposal Style Guide | one native `page`, `_wcp_is_style_guide` meta (P3) |
| Plan expectation snapshot | already captured in `wcp_ai_actions.output_snapshot`; parsed, never duplicated (P4) |
| Calibration learning | native `post`, `item_type = learning` + `calibration` tag (P4) |
| Briefing instance | scheduled page from a built-in "Daily Briefing" template (P1) |
| Field time-series sample | edge with literal object: subject = instance page, predicate = field slug (P6, requires `wcp-graph`) |
| Delegation ledger entry | meta on the existing delegation record (`wcp-delegation`), no new table (P7) |
| Standing delegation order | meta on a scheduled template page: instruction text, enabled flag, last-run (P8) |

### Provenance (stored in `wcp-graph`)

Provenance predicates, the edge-writing API, and their display are specified
in §5 (G1–G3) as iterations to the `wcp-graph` plugin. Core features
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

## 4. Preparedness Features (P1–P8)

Every P-feature obeys the preparedness thesis (§1): cron creates surfaces,
deterministic checks are free and always-on, AI runs only on user action,
nothing commits without acceptance. P8 is the sole, explicit exception and
carries its own amendment text.

### P1 — Daily Briefing + open-loops engine

**User story:** the user opens the app in the morning and the day is already
staged: what's due, what's stale, what's waiting on them — and one click
produces a narrative briefing with a suggested focus.

**Two layers, strictly separated:**

**P1a — Open-loops engine (deterministic, zero AI).** A new
`WCP_Open_Loops` class exposing one method, `collect()`, returning a typed
array of loops. Each detector is a plain WP query:

| Loop type | Detection |
|---|---|
| `due_today` / `overdue` | `_wcp_due_date` meta ≤ today AND `task_status` ≠ done |
| `stale_in_progress` | `task_status` = in-progress AND `post_modified` older than N days (option `wcp_stale_days`, default 7) |
| `delegation_waiting` | `wcp-delegation` records with status `needs_input`, or `completed` with no user review recorded |
| `unreviewed_instance` | scheduled checklist instance (via `instantiates` edge or template meta) with unticked steps and no visit/review meta since creation |
| `unanswered_question` | Hermes clarification with empty answer |
| `orphan_work` | (requires P2) items created in the last 7 days with no `advances` path to any goal |

Rendering: a compact "Open loops" panel. It renders (a) on the Daily
Briefing page always, and (b) optionally on every page view (option
`wcp_loops_panel_everywhere`, default off) scoped to that page's subtree.
Each row deep-links to the item/delegation. **Must render correctly with
embeddings disabled and no API key configured** — this layer is pure state.

**P1b — "Brief me" chip (one AI call, user-triggered).** Context pack:
- the open-loops array (JSON, capped),
- mission + Proposal Style Guide (P3, when present),
- goal-spine summary (P2, when present): per-goal counts of items advanced
  in the last 7 days,
- delta since last briefing: titles of posts created/modified since the
  previous briefing instance's timestamp (capped at 30).

Output (strict JSON): `{narrative, suggested_focus, proposed_tasks: [≤3]}`.
Narrative and focus render inline; proposed tasks are ordinary item
proposals (accept/dismiss). Logged via `WCP_AI_Logger` as `daily_briefing`.

**Infrastructure:** a built-in "Daily Briefing" page template + opt-in daily
schedule via `WCP_Page_Scheduler`; instances land under a "Briefings" page.
The template body is chips, not content — the instance is a surface.

**REST:** `GET /wcp/v1/loops?scope=<page_id|all>` (deterministic);
`POST /wcp/v1/ai/brief {briefing_page_id}`.

**Acceptance criteria:**
- Loops panel renders in < 200 ms at current corpus scale with AI fully
  unconfigured.
- "Brief me" never auto-runs — including on instance creation.
- Dismissed proposed tasks are not re-proposed in the next briefing (the
  briefing context includes yesterday's dismissals).

---

### P2 — Goal spine

**User story:** goals stop being aspirational pages and become the spine
daily work visibly hangs off — the system can answer "what moved?" and
"what's drifting?" from structure, not vibes.

**Mechanics:**
- A goal is any page with `_wcp_is_goal` meta (set via a "Mark as goal"
  page action; a built-in "Goals" parent page is created on first use).
- New predicate `advances` (inverse `advanced by`) — added to the G1 seed
  list. Edges: item/plan/page → goal.
- **Proposal integration:** every AI action that proposes items or plans
  also proposes `advances` edges when a goal is plausibly related (the goal
  list — titles + one-liners, it is small — is added to those actions'
  context packs). Edges ride the existing proposal via G2 `propose_edges()`
  and are written only on acceptance. Manual chip on any item: "advances…"
  → goal picker.
- **Goal page panel (deterministic):**
  - *Moved this week* — items with `advances → this goal` modified in the
    window, grouped by status change.
  - *Momentum* — weekly count sparkline (plain SQL over edges + posts).
- **Orphan-work detector (deterministic, feeds P1):** items created in the
  window with no `advances` edge and none inherited from their context's
  pages. Rendered as a count + list, never auto-actioned.
- **Drift report (AI, user-triggered):** chip on the Goals page. Context
  pack: mission text + per-goal activity counts + top orphan items. Output:
  a short narrative naming the gap ("mission says X is primary; this week's
  volume is 80% Y") + up to 3 proposals (retag, new goal, mission amendment
  via S3). This is coaching; it reads, it never moves anything itself.

**Degradation:** without `wcp-graph`, goal marking still works but the
panel shows only items whose context sits under the goal page; `advances`
and orphan detection are disabled.

**REST:** `GET /wcp/v1/goals/{id}/activity?days=7`;
`POST /wcp/v1/ai/drift-report`.

**Acceptance criteria:**
- Accepting an item proposal with an `advances` edge writes both atomically;
  dismissing writes neither.
- Orphan detection never flags items under a goal page's own subtree.

---

### P3 — Dismissal mining → Proposal Style Guide

**User story:** the assistant visibly gets more *the user* every week,
because it studies which of its proposals were accepted, dismissed, or
edited — evidence it already records — and distills that into a style guide
the user approves.

**Mechanics:**
- **The dataset exists:** `wcp_ai_actions` rows carry prompt, output
  snapshot, and accepted/dismissed item IDs (`log_decisions`). No new
  logging required beyond one gap: record *post-acceptance edits* — when an
  accepted item's title/content is edited within 24 h, stamp
  `_wcp_edited_after_accept` meta (cheap `save_post` check).
- **The artifact:** one "Proposal Style Guide" page (`_wcp_is_style_guide`),
  human-readable, e.g. "Prefer ≤4 items per batch. Verb-led titles.
  Summaries: max 2 sentences." Created empty on first run.
- **The action (`review_my_proposals`, user-triggered):** loads the last
  100 logged actions + decisions + after-accept edits; asks the model to
  find *patterns in the decisions* (not to re-litigate content); returns a
  **diff proposal** against the style guide (reuses F3's hunk-level
  accept/dismiss mechanics). Each proposed hunk must cite ≥3 supporting
  decisions by action id — uncited hunks are rejected at parse time.
- **The injection:** `WCP_Prompt_Builder::build_system_prompt()` appends
  the style guide (capped at 1,500 chars; hard-truncated with a marker) to
  every *generation* action's system prompt (items, headings, plans,
  briefings — not chat Q&A, which shouldn't be style-constrained).
- **Trigger surface:** chip on the weekly review / Memory Review scheduled
  page (S2), and a settings-page button. Never cron-run.

**Guardrails (the self-improvement loop is human-gated at both ends):** the
evidence is user decisions; the conclusion is a diffed proposal; the
injection is a visible, editable page. The user can edit the guide directly
at any time — it is theirs, not the model's.

**REST:** `POST /wcp/v1/ai/review-proposals`.

**Acceptance criteria:**
- Running twice with no new decisions proposes no changes the second time.
- Guide injection never exceeds its cap; an over-long guide degrades by
  truncation, never by prompt failure.
- Every accepted hunk's citations resolve to real action ids.

---

### P4 — Calibration memory

**User story:** the assistant knows its own biases in the user's domains —
"I underestimate integration work ~2×" — because it compares what it
predicted with what happened, and its future plans say so.

**Mechanics:**
- **Predict:** extend the action-plan output schema with per-step
  `effort: "S|M|L"` and plan-level `expected_steps` (already implicit in
  the array length). This lands in the proposal and thus in the existing
  `output_snapshot` — the prediction is stored the moment the plan is
  logged, no new storage.
- **Measure:** when the last step of a plan completes (same trigger as
  F3), the actuals are computable: steps added after acceptance, steps
  deleted/skipped, title-edit rate, wall-clock from acceptance to
  completion (post timestamps).
- **Compare (`calibrate` chip, offered alongside F3's playbook chip):**
  context = expectation snapshot + actuals. Output: ≤2 proposed
  *calibration learnings* — atomic learning items tagged `calibration`,
  each with `applies_to → <context page>` edges: "In [Books O2C], plans
  under-count coordination steps; observed 4→7."
- **Feed back:** planning actions (`action_plan`, `plan_goal`,
  `action_plan_from_context`) retrieve up to 3 calibration learnings whose
  `applies_to` context matches the item's context (falling back to
  embedding match) and inject them under a "Known estimation biases"
  header.

**Acceptance criteria:**
- A plan executed exactly as proposed yields "well calibrated — no learning
  proposed", not a filler item.
- Calibration learnings are ordinary items: visible, editable, deletable,
  consolidatable by S2.

---

### P5 — Tiered context + graph-expanded retrieval

**Status note:** the naive always-on injection of the 5 nearest corpus
items (mislabelled "Relevant Memories") was disabled 2026-07-09. P5 is its
principled replacement. Lesson encoded here: **untrustworthy retrieval is
worse than none** — it silently pollutes every turn.

**Tier model** (enforced in `WCP_Context_Builder` / `WCP_Prompt_Builder`):

| Tier | Contents | Rule |
|---|---|---|
| T1 constitutional | mission, page AI mission, Proposal Style Guide (P3) | always in; small; human-approved text only |
| T2 structural | current page, ancestors, page items; dropdown-selected scope (page/corpus/select — unchanged) | always in; existing behavior |
| T3 episodic | memories (F2/S2), learnings, calibration items | **conditional**: enters only above a similarity threshold θ (option, default 0.55), capped at 5, and always rendered with a provenance citation — "recalled because it resembles [item] (link)" |

T3 replaces the removed block. The prompt section header is honest:
"## Recalled (cited)" — never "memories" unless every entry actually is one.

**Graph-expanded retrieval** (upgrade inside `include_rag_items()`, used by
corpus mode and any T3 lookup; requires `wcp-graph`, falls back to flat):

1. **Seed:** embed query → top-k items by cosine (existing path, k=10).
2. **Walk (one hop, deterministic):** for each seed —
   - its `wcp_context` terms → up to m sibling items per term (m=3, by
     recency),
   - edges touching the seed (both directions) → connected entities'
     titles + one-line summaries,
   - playbook docs tagged to the seed's contexts (the F3/action-plan
     matching mechanism).
3. **Budget & rank:** seeds ranked by similarity; expansions inherit their
   seed's rank and are annotated "via [seed]". The whole block respects the
   existing char limits in `format_for_prompt()`; expansions are dropped
   before seeds when over budget.
4. **Dedup** across seeds/expansions/T2 (an item already in the page
   context never re-enters via retrieval).

Why this wins: flat cosine finds the *neighborhood*; the hand-curated
taxonomy and edges supply the neighborhood's *shape* — siblings the
embedding missed, the entity the item is about, the playbook that governs
it. The user's structural curation becomes retrieval quality.

**Config:** `wcp_retrieval_graph_expansion` (default on when `wcp-graph`
active), `wcp_t3_threshold`, `wcp_t3_cap`.

**Acceptance criteria:**
- With `wcp-graph` inactive, retrieval behaves exactly as today.
- Every T3 entry in a rendered prompt carries a resolvable citation.
- A query with no seed above θ yields an empty T3 — no filler.

---

### P6 — Checklist trends, field time-series, ideal-state gap

**User story:** recurring structure becomes a sensor network. The system
notices "step 4 skipped three weeks running" and "this number has drifted
20% in a month" — things only it can see, because only it has the
instances.

**P6a — Trend review (recurring checklists).**
- Instance gathering: all instances of a template via `instantiates` edges
  (fallback: template-id meta stamped at instantiation).
- **Deterministic stats** (computed in PHP, shown as a table on the
  template's parent page): per-step completion rate over the last N
  instances, average completion delay vs schedule, streak length.
- **AI narrative ("review trends" chip):** context = the stats table +
  step titles. Output: observations + ≤3 proposals, each mapping to a real
  mutation: *remove step* (template edit proposal), *recommit* (a task
  item), *reschedule* (scheduler change proposal). Template edits reuse
  F4's mapping UI.

**P6b — Field time-series.** When a scheduled instance with template
fields is saved, each field value is written as an edge literal (subject =
instance page, predicate = field-slug term, object = value) — via the same
acceptance-free path as manual edits (the user typed the value; the save is
the acceptance). The template parent page renders a history table/sparkline
per field (semantic-table machinery). Requires `wcp-graph`; without it,
values remain in page content only and P6b is hidden.

**P6c — Ideal-state gap.** Persistent (non-recurring) checklists marked as
specs (F6) get: a deterministic staleness banner ("last reviewed 12 days
ago" — `_wcp_last_eval` meta) rendered on visit, and the F6
`evaluate_against_spec` chip pre-wired with subject = the page's own
current state. Review rituals that summon the user instead of waiting.

**Acceptance criteria:**
- Trend stats render with AI unconfigured.
- Removing a step via an accepted proposal never mutates past instances.
- P6b writes exactly one edge per field per instance save (idempotent on
  re-save: update, not duplicate).

---

### P7 — Delegation quality gates (`wcp-delegation` + core)

**User story:** delegation leverage dies at handoff (bad briefs) and
handback (unreviewable walls of text). Instrument both, and keep a ledger
so the system learns what delegates well.

**P7a — Pre-flight critique.**
- Ship a built-in **"Good delegation" spec page** (F6 conventions):
  criteria like "acceptance criteria present", "scope bounded", "examples
  included", "relevant playbook attached".
- On "Delegate", before dispatch: run the F6 eval engine with subject =
  the compiled brief, spec = the good-delegation spec. Score < threshold
  (option, default 60) → show per-criterion failures + suggested fixes;
  the user may fix, or override with one click ("send anyway"). Overrides
  are recorded in the ledger.
- The brief compiler itself is upgraded to assemble from structure:
  mission excerpt + item + its context page summary + matched playbook +
  attached spec (if the item's context has one) + goal (via `advances`).

**P7b — Scored handback.**
- On Hermes completion, if the delegation carries a spec, the eval engine
  scores the report automatically — **this is a read/score, not a write**,
  and it is the one AI call not user-triggered per-run; it is user-armed
  per-delegation ("score handback against spec ☑", default on when a spec
  exists) at dispatch time, which is the human trigger.
- Review UI shows: overall score, per-criterion verdicts, suggested
  deltas — each delta individually acceptable (F6 mechanics). Accepting
  deltas edits the proposal Hermes produced, not live content.

**P7c — Delegation ledger.**
- Per delegation, record on the existing delegation record: pre-flight
  score, override flag, handback score, user outcome (accepted / rejected /
  heavily edited — edit distance on acceptance), task-type signals (item
  type, context, tags).
- After ≥10 completed delegations, a deterministic "delegate this?" hint
  appears on items whose context/type profile matches historically
  successful delegations (simple nearest-profile match; an optional AI
  rationale chip explains why). The hint is a suggestion chip, never an
  action.

**Acceptance criteria:**
- Pre-flight adds ≤1 AI call and is skippable by override.
- A delegation without a spec skips scoring silently.
- Ledger fields are visible on the delegation record (transparency).

---

### P8 — Scheduled delegation (constitutional amendment)

**This feature deliberately crosses the "no cron-based AI" line.** It is
specified as an explicit amendment, not scope drift, and ships **last** —
after P7's trust machinery exists.

**Amendment text (to be added to CLAUDE.md / core PRD if adopted):**
> The scheduler MAY dispatch a delegation whose instruction text was
> authored verbatim by the user and attached to a schedule (a "standing
> order"). Outputs are always proposals requiring explicit acceptance.
> Every run is logged. A global kill switch and per-order pause exist.
> Frequency is capped (≥ daily interval). The AI never authors, edits, or
> schedules standing orders.

**Mechanics:**
- A standing order is meta on a scheduled template page: `instruction`
  (user-typed, verbatim), `enabled`, `last_run`, `delegation_target`.
- On the scheduler tick that instantiates the page, if a standing order is
  enabled: queue a `wcp-delegation` job whose brief = the standing
  instruction + the P7a-compiled context for the template's parent page.
- Hermes output lands as a proposal attached to the new instance page,
  pre-scored per P7b if a spec is attached. The instance page (which the
  user will visit — it's their checklist) is the review surface; P1's
  open-loops engine lists unreviewed standing-order results.
- Global setting `wcp_standing_orders_enabled` (default **off**); each
  order individually pausable; every dispatch logged with the instruction
  snapshot.

**Canonical first use:** "Every Monday 06:00 — draft the weekly review
skeleton from last week's completed items and open loops." The user wakes
to a proposal, not a change.

**Acceptance criteria:**
- With the global switch off, no scheduler tick ever triggers an AI call
  (verifiable from the log).
- A standing order's instruction text is byte-identical to what the user
  typed — no AI preprocessing.
- Deleting the template deletes the standing order.

---

## 5. wcp-graph Plugin Iterations (G1–G3)

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
| `instantiates` | `instantiated as` | F4, P6 — scheduled checklist page → its template source |
| `advances` | `advanced by` | P2 — item/plan/page → goal it advances |

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

## 6. Supporting Infrastructure (`wp-copilot`)

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

## 7. REST Surface (summary)

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
| `/loops?scope=` (GET, deterministic — no AI) | P1a |
| `/ai/brief` `{briefing_page_id}` | P1b |
| `/goals/{id}/activity?days=` (GET, deterministic) | P2 |
| `/ai/drift-report` | P2 |
| `/ai/review-proposals` | P3 |
| `/ai/item-action` `action=calibrate` | P4 |
| `/templates/{id}/trends` (GET, deterministic) | P6a |
| `/ai/item-action` `action=review_trends` | P6a |
| Delegation pre-flight/score/ledger routes | P7 (`wcp-delegation/v1`) |

Every route: bounded context pack in, strict JSON out, proposal transient,
`WCP_AI_Logger` entry with prompt + input/output snapshots + decision.

---

## 8. Sequencing

| Phase | Ships | Plugin | Rationale |
|---|---|---|---|
| 1 | ~~F1~~ (shipped), F2 | `wp-copilot` | Cheapest; immediately increases capture; exercises no new infrastructure |
| 2 | **P1a + P1** | `wp-copilot` | Highest magic-per-effort in the PRD; open-loops engine is pure queries and immediately changes the product's daily character |
| 3 | F3, F4 | `wp-copilot` | The procedure loop — where compounding starts; F4 reuses template manager + scheduler as-is |
| 4 | G1–G3, **P2** | `wcp-graph` + core | Graph iteration + goal spine together (P2 is G1's first real consumer); core `wcp_proposal_accepted` hook ships in Phase 1 |
| 5 | **P3, P4** | `wp-copilot` | Exhaust-recycling loops; need a few weeks of logged decisions/plans to have material — which phases 1–4 will have produced |
| 6 | F5a, then F5b; **P5** | `wp-copilot` (+graph) | Retrieval work as one block: canonical nodes, tiered context, graph expansion share plumbing |
| 7 | F6, **P6**, S2 | `wp-copilot` | Eval engine + its two consumers (trends, ideal-state gap); S2 by now has memory volume |
| 8 | **P7**, S3, Hermes pack | `wcp-delegation` + core | Delegation gates need F6's eval engine (Phase 7) |
| 9 | **P8** | `wcp-delegation` + core | Last, by design: the amendment should arrive only after P7's trust machinery has a track record |

The `wcp_proposal_accepted` hook (G2's core-side half) should ship in
Phase 1 even though `wcp-graph` may consume it later — it is one
`do_action()` call and avoids retrofitting.

Each phase is independently shippable and independently valuable.

## 9. Out of Scope

- Any automatic/cron-triggered AI call, **with exactly two carve-outs**:
  1. P7b handback scoring, which is user-armed per-delegation at dispatch
     time;
  2. P8 standing orders, governed by the explicit constitutional amendment
     in §4/P8 (verbatim user-authored instructions, proposals-only output,
     global off-by-default switch, full logging).
  Everything else holds: the scheduler creates *pages*; every other AI
  call remains user-initiated.
- Auto-assignment of suggested contexts without a click
- Hard deletion of memories by the system
- Multi-user memory namespacing
- Numeric "AI personality" tuning — identity evolves only via S3 text
  amendments
- Push notifications / email digests (the briefing is a page, not a
  message — revisit only after P1 proves the surface)
- Background embedding of the open-loops state (loops are queries, not
  vectors)

## 10. Open Questions

1. F4: should in-page *fields* support types (date, number, yes/no) in MVP,
   or text-only first? (Recommend text-only.)
2. F6: where does eval score history render — on the spec page, the subject
   page, or both? (Recommend spec page first.)
3. S2: is 90-day half-life right for `user_pattern` memories, or should
   decay be per-type (mission insights decay slower)?
4. F5b: minimum corpus size before canonicalize is offered? (Recommend
   hiding the action below ~30 nodes.)
5. P1: does the open-loops panel render everywhere by default, or only on
   the briefing page? (Recommend briefing-only first; everywhere is one
   option flip once trusted.)
6. P3: should chat Q&A also receive the style guide, or generation actions
   only? (Recommend generation-only — spec'd that way — revisit if chat
   tone feels off.)
7. P5: θ default of 0.55 is a guess; needs tuning against the real corpus
   after a week of citations being visible.
8. P7c: is 10 completed delegations the right ledger threshold before
   "delegate this?" hints appear, and should the hint be dismissible
   per-item-type?
9. P8: should standing orders require a spec attached (forcing scored
   handback), or is that too much friction for the first order?
