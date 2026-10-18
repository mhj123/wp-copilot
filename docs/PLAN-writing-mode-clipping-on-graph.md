# Build Plan — Writing Mode + Phrase-Anchored References + Clippings
## Grounded in the live codebase (mhj123/wp-copilot @ main, read 2026-10-18)

> Supersedes the earlier ad-hoc `_wcp_rel` postmeta plan. The key finding: the
> typed-relationship graph we were about to build **already exists** as the
> `wcp-graph` add-on. This plan builds on it instead of reinventing it.

---

## 0. The finding that reshapes everything

`wcp-graph/` is a working subject–predicate–object graph layer (v0.2.0, ~1,667 LOC),
decoupled from core (grep confirms core never references `WCPG_*`). It already provides,
verified by reading the source:

- **One edge table** `wp_wcp_edges`: `subject_id — predicate_id — (object_id XOR object_value)`, timestamps, indexed on subject, object, and predicate.
- **Entities = existing `post`, `page`, `wcp_heading`** (`ENTITY_POST_TYPES`). No new types. Matches our reuse-primitives invariant.
- **Open predicate vocabulary** (`wcp_predicate` flat taxonomy, `get_or_create` on first use), with **display-only `inverse_label`** — "one edge, two readings; the inverse is never stored as a second edge." *This is exactly our single-direction-storage + computed-inverse decision, already implemented.*
- **Dedupe on exact triple** (`edge_exists` → 409).
- **Referential integrity by hooks**: edges deleted when either endpoint post (`before_delete_post`) or the predicate term (`pre_delete_term`) is deleted. *This solves clip/anchor orphaning for free.*
- **Derived structural edges**: `WCPG_Structure` reads `contains`/`within` from the page/heading/item hierarchy at query time — never written, so they can't drift. Containment comes free; we do NOT store `isPartOf`/`contained_in` as edges where structure already implies them.
- **Semantic tables** (`_wcpg_tables` page meta): saved queries — rows = items a page/heading contains, columns = predicates, cells = edges. A ready-made way to view clippings-by-relationship later.
- **REST** `wcp-graph/v1`: `GET /posts/{id}/edges` (inbound+outbound), `GET/POST /edges`, `DELETE /edges/{id}`, `GET /predicates`, `GET /entities` (object picker), table routes.
- **Connections panel** UI (`class-connections-panel.php` + JS/CSS) on every entity.

**Reuse verdict: the entire edge-storage, inverse, dedupe, integrity, query, REST and
object-picker layers are reusable as-is.** What's new is the research *flow* on top.

---

## 1. Decisions locked (this + prior turns)

- **Clipping = first-class reusable item** (a `post`), not an annotation bound to one reference. Reusable across anchors is fundamental to the product ethos.
- **Relationships = graph edges** (`wcp-graph`), NOT ad-hoc `_wcp_rel` postmeta. The old plan's postmeta edges are dropped.
- **Vocabulary = CiTO, seeded into the open predicate taxonomy (Option A).** Pre-create the CiTO set as predicate terms with `inverse_label` filled and the **CiTO IRI stored in term meta** (`_wcpg_cito_iri`); autocomplete steers researchers to them; a freeform label is still allowed (open escape hatch). Standards-native defaults, user ultimately in control.
- **Direction: clip → anchor** (subject = clip, object = anchor), matching CiTO's citing→cited orientation and the graph's single-direction model. Inverse (the ⚠ "contested by" on the draft) is the existing **inbound** read (`edges_for_post` already splits inbound/outbound; `KEY object` indexes it).
- **Containment via derived edges, not stored ones.** Clip `isPartOf` reference and phrase-anchor `within` paragraph are expressed through structure where possible (post_parent / context), read by `WCPG_Structure` — only store an edge when structure can't carry it.
- **Persist only the clipping excerpt**, never full source text (tier rule I4 + copyright). Viewer renders text for selection; storage keeps the short excerpt.
- **Full-text assumption**: prototype assumes accessible full text (web pages, OA PDFs); graceful "clip from abstract" fallback; production resolves access via institutional entitlements (ROADMAP note).

---

## 2. The seeded CiTO predicate set (Option A)

Create on researcher-mode activation (idempotent, by slug), each with `inverse_label` + `_wcpg_cito_iri`:

| Label (UI) | slug | inverse_label | CiTO IRI | Rung |
|---|---|---|---|---|
| discusses | discusses | discussed by | cito:discusses | R1 (default) |
| supports | supports | supported by | cito:supports | R3 |
| contests | contests | contested by | cito:disagreesWith | R3 |
| qualifies | qualifies | qualified by | cito:qualifies | R3 |
| is method for | is-method-for | has method in | cito:usesMethodIn | R3 |

Default on save = **discusses**. Structural `is part of` / `within` are derived, not seeded.

---

## 3. What's already built vs. new (first-hand)

**Reuse as-is (graph):** edge table, add/delete/query edges, inbound/outbound split, dedupe, cascade-delete integrity, predicates + autocomplete, inverse labels, entity search/object-picker, REST `wcp-graph/v1`, semantic tables, Connections panel.

**Reuse as-is (core, per Hermes notes + audit):** Find References via Exa; proposal → accept → `execute_proposal`; audit logging; `require_object()` ownership gating; PDF-via-document-blocks (for later clip-from-PDF); two-level nesting; researcher-mode gating.

**New — the research flow:**
- Writing-mode toggle on an item (`_wcp_writing_mode`), prose renderer with escaped text + server-known `<mark>` spans only (XSS-safe — the one security-critical new surface).
- Selection → floating "Find references" → seed query from the highlighted phrase (extend existing `find_references_*`).
- Phrase-anchor as an entity (lazy-created on first accept; `_wcp_anchor_text` + offset hint; resolved by text match at render; unresolved → "detached", not lost).
- On accept: create/find anchor entity, create reference + clipping items, **write edges via `WCPG_Graph_Repository::add_edge()`** (clip —discusses→ anchor by default).
- In-context viewer (R2): render accessible source text, highlight → clipping item, anchor phrase pinned visible, default edge `discusses`, optional CiTO type.
- Clip reuse: clipping is a normal item, so it's already findable/attachable to other anchors via the existing edge routes.

---

## 4. Rungs

- **R1 — whole-reference save, phrase-anchored.** Writing mode + phrase highlight + Find References seeded by phrase; on accept, reference item + anchor entity + `discusses` edge. (Core search exists; new = phrase seeding, anchor entity, edge write.)
- **R2 — in-context viewer + manual clipping (MVP of the extraction moment).** Clipping = reusable item; default `discusses` edge to anchor; containment to reference derived/structural. The big new build.
- **R3 — CiTO relationship types.** Expose the seeded `supports/contests/qualifies/is-method-for` as optional one-tap refinements on a clip's edge. Rides entirely on existing edge machinery — just offers more predicates.
- **R4 — surface `contests` in the draft + AI-suggested clippings.** The ⚠ marker = existing inbound-edge read filtered to `contests`. AI-suggested clips = the "most excited about" next feature; propose-not-act, ≤2–3 per source, anchor-relative.

---

## 5. Coupling & packaging decision (needs a call)

Clipping edges live in `wcp-graph`, a **separate plugin**. So researcher-mode clipping would **depend on wcp-graph being active**. Options:
- (a) Make wcp-graph a required dependency of researcher mode (activation check + admin notice).
- (b) Fold wcp-graph into core.
- (c) Degrade gracefully: if wcp-graph inactive, references still save (whole-reference, no typed edges), clipping disabled.
Recommendation: **(a)** for now (keeps the clean decoupling, lowest churn), with (c)'s graceful degrade as the fallback behaviour. Revisit (b) only if the two always ship together.

---

## 6. Must-verify-against-live-code before building (don't trust these assumptions)

1. **Graph permission gap**: `WCPG_REST_API::check_permission()` is bare `current_user_can('edit_posts')` — NO per-object `require_object()`. Fine for single-admin; a gap on multi-user. Decide whether to align it with core's gating as part of this work (recommended, low effort).
2. Core Find References is page-level today (Hermes notes) — confirm the exact function and how to inject a phrase seed.
3. Confirm the `execute_proposal` accept branch where reference items are created, to hook anchor-entity + edge creation.
4. Confirm `wcp_heading`/item post_status and capability floors (audit found Editor-floor for page/heading types) don't block writing-mode items (they're `post`, so Author-floor — should be fine).
5. Confirm `WCPG_Structure` can express clip→reference containment, or whether a stored edge is needed there.
