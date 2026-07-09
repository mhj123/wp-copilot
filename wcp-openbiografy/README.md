# OpenBiografy (wcp-openbiografy)

Document-native biography engine for WordPress. Add URLs and documents about
a person; review AI-extracted facts; consolidate them into a life timeline;
draft narrative chapters — all human-in-the-loop. The public page shows
chapters with footnotes, a timeline, and the full source list.

Companion plugin to **Work Copilot** (wp-copilot).

## Requirements

- WordPress 6.x, PHP 8.0+
- **Work Copilot plugin active**, with its Anthropic API key configured
  (Work Copilot → Settings → AI). OpenBiografy reuses its AI client and
  audit log — without it the plugin still activates, but AI features are
  disabled.
- Pretty permalinks enabled (person pages live at `/people/{slug}/`).

## Install

1. Copy (or symlink) `wcp-openbiografy/` into `wp-content/plugins/`.
2. Activate **Work Copilot OpenBiografy** in wp-admin → Plugins.
   Activation seeds the kind taxonomy and flushes rewrite rules.
3. Check **OpenBiografy → Settings**: models, batch size, context limits.

## Workflow

1. **OpenBiografy → Dashboard**: create the person. Fill the *context note*
   carefully — it is injected into every AI prompt to disambiguate the
   subject (e.g. "Chemist, 1874–1952, worked at University X; not the
   politician of the same name").
2. Paste URLs (one per line) and/or upload documents (PDF, TXT, MD).
3. Click **Fetch next N** until sources are fetched. Failed URLs
   (JS-rendered pages, paywalls) can be retried or given text via
   **Paste text**.
4. Click **Extract facts from next N**. Each source costs one AI call.
5. **Review Facts**: accept/edit/dismiss each proposed fact, or
   *Accept all remaining* per source. This is where your judgement shapes
   the biography.
6. **Timeline** → **Consolidate**: the AI groups duplicate facts from
   different sources into proposed timeline events (conflicts are flagged
   *contested*, never flattened). Review and accept.
7. **Chapters**: create chapters with EDTF periods (e.g. `1891/1904`),
   run *Suggest assignments*, apply, then *Draft narrative* per chapter.
   Edit the draft, accept, tick *Published*.
8. View the public page (link next to the person selector). Export the full
   project as JSON from the Dashboard.

## EDTF date cheat sheet

| Input | Meaning |
|---|---|
| `1932` | year |
| `1932-03` / `1932-03-14` | month / day |
| `1932~` | circa 1932 |
| `1932?` | uncertain |
| `189X` | the 1890s |
| `1891/1894` | interval |
| `../1880` , `1935/..` | before 1880 / from 1935 |

Anything unparseable is kept and filed as *undated*.

## Notes & limits

- No cron, no background AI: every pipeline step is a button you click.
- PDFs are sent to the Anthropic API as native documents (limit configurable,
  default 20 MB); their text is not snapshotted locally.
- Readability extraction is heuristic; use the paste-text fallback for
  stubborn pages.
- All AI calls are logged in Work Copilot's AI Actions table with prompts,
  snapshots and your accept/dismiss decisions.
