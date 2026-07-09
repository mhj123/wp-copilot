# OpenBiografy — As-Built Documentation

Version 0.1.0 · plugin slug `wcp-openbiografy` · prefix `WCPO_`/`wcpo_`

## Architecture

```
Source (URL/document) ──fetch──► text snapshot
        │
        └──extract (1 LLM call/source)──► Facts [wcpo_proposed]
                                              │  human accept/dismiss (Review screen)
                                              ▼
                              Facts [wcpo_accepted] ──consolidate (LLM)──► Events [wcpo_proposed]
                                                                              │  human accept/dismiss (Timeline screen)
                                                                              ▼
                                                              Events [wcpo_accepted] ──assign──► Chapters
                                                                              │
                                                            draft narrative (LLM → proposal meta)
                                                                              │  human accept (citations validated)
                                                                              ▼
                                                              Public page: chapters + timeline + footnotes + JSON-LD
```

Dependency: Work Copilot core provides `WCP_AI_Client` (Anthropic key +
model allowlist) and `WCP_AI_Logger` (`wp_wcp_ai_actions` audit table).
`wcpo_copilot_active()` guards every AI path; the plugin degrades gracefully
without it. No custom DB tables, no cron.

## File map

| File | Class | Responsibility |
|---|---|---|
| `wcp-openbiografy.php` | — | constants, settings (`wcpo_settings` option, `wcpo_get_setting()`), CPT/status/taxonomy registration, vocab helpers (`wcpo_kinds()`, `wcpo_doc_kinds()`, `wcpo_source_tiers()`), activation, boot |
| `includes/class-edtf.php` | `WCPO_EDTF` | EDTF subset: `is_valid`, `to_sort_range` (YYYYMMDD keys), `format`, `year`, `to_iso` |
| `includes/class-llm.php` | `WCPO_LLM` | strict-JSON gateway; text via `WCP_AI_Client`, PDFs via direct Anthropic document blocks; mandatory audit row; returns `{data, action_id, model}` |
| `includes/class-person-repo.php` | `WCPO_Person_Repo` | person CRUD, `context_block()` prompt fragment |
| `includes/class-source-repo.php` | `WCPO_Source_Repo` | source CRUD, dedupe, fetch-status machine, snapshot, citation (AI prefill never clobbers human values), classification, counts |
| `includes/class-fact-repo.php` | `WCPO_Fact_Repo` | fact proposals, `decide()` (the only status transition), `edit()` with diff logging, review/consolidation queries |
| `includes/class-event-repo.php` | `WCPO_Event_Repo` | event proposals; accept stamps `_wcpo_event_id` on member facts, dismiss frees them; chapter/timeline queries |
| `includes/class-chapter-repo.php` | `WCPO_Chapter_Repo` | chapter CRUD/reorder; draft proposal meta; `accept_draft()` validates `[eNNN]` citations |
| `includes/class-fetcher.php` | `WCPO_Fetcher` | `wp_remote_get` + DOM readability (article→main→densest div), citation hints; TXT/MD read; PDF passthrough |
| `includes/class-extractor.php` | `WCPO_Extractor` | context pack → `{citation, doc_kind, source_tier, facts[]}` → proposals |
| `includes/class-reconciler.php` | `WCPO_Reconciler` | date-sorted fact chunks → proposed events; contested-conflict rule; validates fact ids |
| `includes/class-chapter-ai.php` | `WCPO_Chapter_AI` | assignment suggestions (returned, not persisted), narrative drafting (proposal) |
| `includes/class-exporter.php` | `WCPO_Exporter` | full project JSON (`format: openbiografy/v1`) |
| `includes/class-rest-api.php` | `WCPO_REST_API` | all routes; every post-proposal transition lives here |
| `includes/class-frontend.php` | `WCPO_Frontend` | `template_include`, source→footnote-number map (dedupes by source), narrative rendering, JSON-LD |
| `admin/class-dashboard.php` | `WCPO_Dashboard` | Dashboard / Review Facts / Timeline / Chapters screens |
| `admin/class-settings.php` | `WCPO_Settings` | settings form (`admin_post_wcpo_save_settings`) |
| `assets/admin.js` | — | REST client + nonce, batch loops with stop, review interactions, media picker, EDTF hint |
| `templates/single-wcpo_person.php` | — | public page (theme-overridable via `single-wcpo_person.php`) |

## Data model

**CPTs**: `wcpo_person` (public, slug `people`), `wcpo_source`, `wcpo_fact`,
`wcpo_event`, `wcpo_chapter` (all private, dashboard-managed).
**Statuses**: `wcpo_proposed` / `wcpo_accepted` / `wcpo_dismissed` on facts
and events. **Taxonomy**: `wcpo_kind` (12 seeded terms) on facts and events.

Meta keys (all `_wcpo_*`):

- person: `birth_edtf, death_edtf, birth_place, death_place, occupation, context_note`
- source: `person_id, source_type(url|document), url, attachment_id, fetch_status(new|fetched|fetch_failed|extracted|extract_failed|skipped), fetched_at, http_status, fetch_error, extracted_at, facts_extracted_count, cite_title, cite_author, cite_date, cite_publisher, doc_kind, source_tier, tier_confidence, ai_action_id`
- fact: `person_id, source_id, date_edtf, date_sort_start, date_sort_end, place, quote, locator, confidence, event_id(0=unconsolidated), ai_action_id, dismiss_reason`
- event: `person_id, fact_ids(json), date_edtf, date_sort_start/end, place, confidence, importance, contested, contested_note, chapter_id(0=unassigned), ai_action_id`
- chapter: `person_id, period_edtf, period_sort_start/end, draft_proposal, draft_ai_action_id, draft_created_at` (+ `menu_order`)

## REST API — `wcp-openbiografy/v1`

All routes require `manage_options` + REST nonce. POST unless noted.

| Route | Purpose |
|---|---|
| `status` (GET) | pipeline counts + warnings for a person |
| `add-person`, `update-person` | person CRUD |
| `add-sources` | bulk URLs (newline text) |
| `add-document-source` | attachment → source |
| `update-source` | citation edits (overwrite) / manual paste-text fallback |
| `retry-source`, `delete-source` | failure recovery; trash (+ proposed facts) |
| `fetch-next`, `extract-next`, `consolidate-next` | process ONE item; JS loops N |
| `accept-fact` (with optional inline edits), `dismiss-fact`, `edit-fact`, `accept-source-facts` | fact review |
| `accept-event`, `dismiss-event`, `edit-event` | event review |
| `create-chapter`, `update-chapter`, `reorder-chapters` | chapter CRUD |
| `suggest-assignments` → `apply-assignments` | AI proposal → human apply |
| `draft-chapter` → `accept-draft` / `dismiss-draft` | narrative proposal → decision |
| `export-json` (GET) | full project dump |

## Guardrails (where enforced)

- Facts/events are ALWAYS born `wcpo_proposed`: `WCPO_Fact_Repo::create()`,
  `WCPO_Event_Repo::create()` — no other creation path exists.
- Status transitions exist only in the repos' `decide()` methods, reached
  only from REST handlers (human click). No cron exists at all.
- A fact without `source_id` is rejected at creation → citation coverage is
  structural.
- Every LLM call goes through `WCPO_LLM::call()` which always writes an
  audit row (including errors); decisions are appended to that row on
  accept/dismiss; human edits are logged as `wcpo_human_edit` diffs.
- PDF base64 payloads are never logged (attachment reference only).
- Draft acceptance strips `[eNNN]` citations that don't belong to the
  chapter and reports them as warnings.
- Low-confidence items are flagged (threshold `min_confidence_display`),
  never dropped.

## Settings (`wcpo_settings`)

`batch_size` (5) · `model` / `model_draft` (Sonnet 4.6 default; allowlist =
WCP_AI_Client's: Haiku 4.5, Sonnet 4.6, Opus 4.8) · `max_context_chars`
(60 000) · `max_snapshot_chars` (200 000) · `fetch_timeout` (30 s) ·
`max_pdf_mb` (20) · `consolidate_chunk` (60) · `min_confidence_display` (0.6).

## Frontend

- Template: `templates/single-wcpo_person.php` via `template_include`
  (theme override respected). CSS enqueued only on that view.
- Footnotes: page-wide source→number map (`WCPO_Frontend::source_map`);
  chapters' `[eNNN]` markers and timeline entries share numbers per source;
  the Sources section anchors `#wcpo-fn-{n}` with tier badges.
- Timeline: accepted events chronologically (lexical sort on YYYYMMDD keys),
  contested marker + note; accepted-but-unconsolidated dated facts render in
  a lighter style; undated items last.
- JSON-LD: schema.org `Person` + `Event` `@graph`; only clean ISO dates are
  emitted (fuzzy EDTF is never presented as exact).

## Known trade-offs

- PDFs: no local text snapshot; re-extraction re-sends the PDF; API document
  limits apply (~100 pages).
- Fetched text lives in `wp_posts` (revisions disabled on sources); ~200
  sources ≈ tens of MB — fine for a personal site.
- Readability is heuristic; manual paste-text fallback is the escape hatch.
- EDTF subset only (no seasons/sets/Level 2).
- Deferred to later versions: autonomous source discovery, AutoResearch
  loops, OCR, PDF/Markdown export, Wikidata reconciliation, multi-user.
