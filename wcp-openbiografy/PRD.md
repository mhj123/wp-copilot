# PRD — OpenBiografy (wcp-openbiografy)

## 1. Product summary

A WordPress plugin that turns URLs and documents about a person into a
source-backed biography site. Users add sources; the system (AI-assisted,
human-in-the-loop at every step) extracts atomic facts, reconciles them into
a life timeline, and drafts narrative chapters. Every statement on the public
page traces back to a source.

**The core principle:**

> Documents become sources. Sources yield facts (claims). Facts are
> reconciled into timeline events. Events become chapters and narrative.
> The biography emerges from the archive, not from the model's memory.

Companion plugin to **Work Copilot** (wp-copilot), whose AI client, Anthropic
API key and AI audit log it reuses. Runs on any WordPress install that has
Work Copilot active.

## 2. Guiding principles (non-negotiable)

1. **Evidence first, narrative second.** A fact cannot exist without a source
   link — enforced by the data model, not by review.
2. **Human-in-the-loop.** AI never publishes. All AI output lands in a
   proposal state (`wcpo_proposed`); only human-triggered REST calls
   accept/dismiss/edit. No cron, no background agents — batch processing is
   user-triggered ("process next N").
3. **Every AI call is audited** in Work Copilot's `wp_wcp_ai_actions` table:
   prompt, input snapshot, output snapshot, and the human's accept/dismiss
   decisions.
4. **Uncertainty is preserved, not hidden.** Fuzzy dates stay fuzzy (EDTF),
   conflicting sources become *contested* events with an explanatory note,
   low-confidence facts are flagged — never silently dropped.
5. **Native WordPress constructs**, open standards where practical:
   schema.org Person/Event JSON-LD, EDTF dates, Dublin-Core-ish citations.

## 3. Data model

| Entity (PRD concept) | Storage | Review state |
|---|---|---|
| Person (BiographySubject) | `wcpo_person` CPT (public, `/people/{slug}/`) | native publish |
| Source (SourceDocument) | `wcpo_source` CPT; fetched text in post_content | pipeline meta `_wcpo_fetch_status` |
| Fact (Claim) | `wcpo_fact` CPT; claim in post_content | `wcpo_proposed/accepted/dismissed` status |
| Timeline event (TimelineEvent) | `wcpo_event` CPT; member fact ids in meta | `wcpo_proposed/accepted/dismissed` status |
| Chapter (NarrativeSection) | `wcpo_chapter` CPT; narrative in post_content, AI draft in proposal meta | native draft/publish |

- Person linkage: `_wcpo_person_id` meta on every source/fact/event/chapter.
- Kinds taxonomy `wcpo_kind` (facts + events), 12 coarse terms: birth, death,
  education, move, employment, publication, relationship, marriage, award,
  conflict, health, other.
- Source classification: document kind (letter…unknown) and source tier
  (definite_primary…unknown) with confidence, AI-prefilled, human-editable.
- Dates: EDTF subset (`1932`, `1932-03`, `1932~`, `1891/1894`, `../1880`,
  `19XX`) + zero-padded YYYYMMDD sort keys.

## 4. Pipeline (all user-triggered)

1. **Add** — paste URLs (one per line) and/or upload documents (PDF/TXT/MD).
   Each becomes a source in state `new` (dedupe per person).
2. **Fetch** — "Fetch next N": one source per REST call. URLs are
   dereferenced with a DOM readability heuristic; page title/author/date
   captured as citation hints. TXT/MD read directly. PDFs pass through
   (sent to the model natively at extraction). Failures are retryable, with
   a manual paste-text fallback for JS-rendered/paywalled pages.
3. **Extract** — "Extract next N": one bounded LLM call per source returns
   strict JSON `{citation, doc_kind, source_tier, facts[]}`. Facts are
   created as proposals; classification prefills the source.
4. **Review facts** — the critical loop. Proposed facts grouped by source;
   inline-edit claim/date/place/kind, view supporting quote, Accept /
   Dismiss per fact, Accept-all per source.
5. **Consolidate** — reconciliation: accepted facts (date-sorted, chunked)
   go to the model, which groups duplicates from different sources into
   proposed timeline events; material conflicts become contested events.
   Human reviews each event (with its member facts visible) and accepts or
   dismisses (dismissal frees the facts for re-consolidation).
6. **Chapters** — human creates chapters (title + EDTF period). AI suggests
   event→chapter assignments (checklist, human applies). AI drafts chapter
   narrative citing events inline as `[e123]`; draft is a proposal the human
   edits, accepts (citations validated, foreign markers stripped with
   warnings) or dismisses. Publishing a chapter puts it on the public page.
7. **Publish/Export** — the person page renders chapters with footnotes,
   the timeline, and the source list, plus schema.org JSON-LD. Full project
   JSON export for data portability.

## 5. Evals / warnings (lightweight)

Surfaced on the dashboard, never auto-resolved:
- fetch/extract failures (retryable)
- proposed facts below the confidence threshold (flag only)
- contested accepted events
- citation markers stripped at draft acceptance
- citation coverage is structurally 100% (facts require sources)

## 6. Out of scope for MVP (deliberate)

Autonomous source discovery / scout agents; AutoResearch-style improvement
loops; OCR; PDF/Markdown/EPUB export; multi-user collaboration; Wikidata
reconciliation; full EDTF Level 2; auto-publishing of anything.

## 7. Acceptance criteria

- Create a person; add ≥3 URLs and ≥1 PDF; fetch, extract, review, and
  consolidate entirely from the dashboard with no background processing.
- Every fact links to a source; every timeline event links to ≥1 fact.
- `wp_wcp_ai_actions` rows exist for every AI call with prompts, outputs and
  decisions.
- Chapter draft accepted only after human review; invalid citations stripped
  with a warning.
- `/people/{slug}/` renders header, chapters with working footnotes, sorted
  timeline with contested markers, and valid JSON-LD.
- Deactivating Work Copilot degrades gracefully (notice + AI endpoints
  return errors; no fatals).
