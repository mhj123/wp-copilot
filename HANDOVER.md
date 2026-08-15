# Handover — work-copilot launch preparation

Written for a fresh Claude Code session picking this up on the desktop app.
The preceding work happened in a remote container that could not run
WordPress, which shapes what is done, what is merely written down, and what
you must verify before trusting any of it.

Read sections 0 and 1 before touching anything.

---

## 0. Re-clone before you start — and rescue the old clone first

The repository history was **rewritten twice** with `git filter-repo`. Every
commit SHA changed. An existing local clone cannot be reconciled: `git pull`
will attempt to merge two unrelated histories and make a mess.

**Before deleting the old clone, check it for work that exists nowhere else:**

```bash
cd /path/to/old/work-copilot
git status                  # uncommitted changes?
git stash list              # stashes?
git log --branches --not --remotes --oneline   # local-only commits?
```

Anything those surface must be copied out **as files** — the old commits
cannot be cherry-picked onto the new history, because their parents no longer
exist. Only once the old clone is confirmed empty of unique work:

```bash
git clone https://github.com/mhj123/work-copilot.git
cd work-copilot
git checkout claude/work-copilot-gtm-planning-1rps0q
```

All current work is on `claude/work-copilot-gtm-planning-1rps0q`. `main` is
behind and carries none of it.

### Why history was rewritten

Two passes, both already pushed:

1. Purged `scripts/` (an 8MB personal tweet archive and bookmark exports),
   `deploy.sh` (contained a production server IP) and
   `wp-copilot/version-check.php` (directly-accessible PHP, leaked local paths).
2. Purged `wcp-graph/` and `wp-copilot-voice/`, both extracted to standalone
   repos first (see §4).

Verified afterwards: zero commits touch any purged path, no blobs over 200KB,
no occurrences of the server IP. **No credentials were ever in history** — a
full-history scan found only documentation placeholders — so nothing needed
rotating.

One stale branch, `claude/optimistic-hawking-r2fupq`, still exists on the
remote. It is scrubbed and 0 commits ahead of `main`. It should be deleted via
the GitHub web UI (the remote environment was refused permission to delete
refs, HTTP 403).

---

## 1. Nothing in the last four commits has been runtime-tested

The remote container had no WordPress and could not obtain one —
`wordpress.org`, `downloads.wordpress.org` and `api.wordpress.org` were all
blocked by network policy. Every change below passed `php -l` and nothing
more. **`php -l` proves syntax, not behaviour.**

Your first substantive task is to stand up WordPress and smoke-test these.

| Commit | Change | What to verify |
|---|---|---|
| `6fe951e` | SortableJS + marked vendored locally, replacing jsDelivr CDN | Drag-to-reorder still works; markdown still renders in items. Script handles were kept identical (`sortablejs`, `marked`) so the `theme.js` dependency array should still resolve — confirm no console 404s |
| `6fe951e` | Delegation gate shim no longer defaults to a hardcoded username | If you use the Caddy basic-auth gate, set `WCPD_GATE_AUTH_USER` in `wp-config.php` or the shim is now inert — Application Passwords may misbehave without it |
| `96e4d3c` | `wcp-graph` and `wp-copilot-voice` removed; four `function_exists()` call sites deleted from the theme | Pages and single-item views render without PHP notices |
| `96e4d3c` | `WCP_AI_Actions::generate_single_item()` deleted | **Single-item generation must still work.** It routes through the hyphenated `generate-single` action type, which falls through to `generate_items()` with an item count (`class-rest-api.php:1289`) and has its own prompt template (`class-prompt-builder.php:161`). The deleted method was an unreferenced duplicate — no JS called it — but this is the change most worth exercising |
| `4a95de5` | Feature flag scaffold added | Plugin still activates cleanly. `wcp_feature('page_templates')` returns `false` by default and `true` with `define('WCP_EXPERIMENTAL', true)` |
| `0327b4c` | REST permission audit | Documentation only, no code |

Resolution logic in the flag scaffold *was* tested, in a standalone PHP
harness with WordPress functions stubbed: default 0/16 on, `WCP_EXPERIMENTAL`
16/16, per-flag constant overriding the master switch 15/16, unknown slug
false with a debug notice. That covers the resolver. It does not cover
anything about WordPress.

---

## 2. Getting a test environment up

The author has a local install — the old `version-check.php` referenced
`localhost:8888`, suggesting MAMP or similar. **Test against that**, not a
clean install: judgments like "is the item row too dense" require a real
corpus, and fixture data will mislead you.

For the permission work in §6 you additionally need a **second user account at
Contributor level**. That single account is what turns the entire audit from
theory into a pass/fail test.

---

## 3. Repository map

| Path | What it is | Status |
|---|---|---|
| `wp-copilot/` | The core plugin. This is the WP.org submission | Ships |
| `work-copilot-theme/` | Workspace UI. Currently **required** for the plugin to be usable | Ships — but see §5 |
| `wcp-wiretap/` | Crypto/equity signal tracking. Zero coupling to core | Own repo eventually |
| `wcp-openbiografy/` | Biographical research. Couples to core only via `WCP_AI_Client`/`WCP_AI_Logger`, funnelled through `includes/class-llm.php` | Own repo eventually |
| `wcp-delegation/` | Delegation to an external Hermes agent | Stays, already flag-gated |
| `gtm/` | Launch planning material | Reference |
| `agent-os/` | Standards docs | Reference |

`wcp-wiretap` and `wcp-openbiografy` are in the repo but are **not** part of
the launch. They serve entirely different audiences and want their own
repositories. OpenBiografy needs one small seam first: give `WCPO_LLM::call()`
a null-logger fallback and a direct Anthropic path so it stands alone.

### Worth stealing from OpenBiografy

`wcp-openbiografy/includes/class-extractor.php` and `class-reconciler.php`
implement a two-stage AI pipeline where the model never writes to the
canonical store: it proposes into a `wcpo_proposed` state, a human accepts,
and only accepted material feeds the next stage. Conflicts become
`contested=true` with a note rather than being silently merged.

Core's AI actions do not work this way — `edit_items()` and
`rewrite_page_content()` write directly, and the latter has no undo. Porting
the propose/accept split into `class-ai-actions.php` would retire two
launch blockers structurally instead of patching them.

---

## 4. Reference documents

**`gtm/work-copilot-feature-inventory.xlsx`** — the working document. 129
feature rows, each with a recommendation, plus a ranked blocker sheet.
Recommendation counts: 62 `Keep - core`, 25 `Fix before launch`, 18 `Keep -
flag off by default`, 13 `Move to backup repo`, 7 `Extract to add-on`, 4
`Delete`.

**Do not work from memory of this file — parse it.** The generator
`gtm/build_inventory.py` holds the data as a Python literal, and reading it
with `ast` is exact and cheap:

```python
import ast
tree = ast.parse(open('gtm/build_inventory.py').read())
rows = next(ast.literal_eval(n.value) for n in ast.walk(tree)
            if isinstance(n, ast.Assign)
            for t in n.targets
            if isinstance(t, ast.Name) and t.id == 'ROWS')
# row = (area, category, feature, description, location,
#        persona, effort, overlap, recommendation, note)
```

Every number quoted in this handover came from that parse. Two `Fix before
launch` rows are already done (CDN bundling, readme placeholders), so 23
remain.

**`gtm/rest-permission-audit.md`** — seven findings against the REST layer,
each cited to file and line, with a proposed capability map and remediation
order. One finding is marked **VERIFY** and needs a runtime check.

**`gtm/gtm-plan.html`** — positioning, messaging, launch sequence. Also
published at
https://claude.ai/code/artifact/30ba62f5-52a1-4a17-be2e-9c75e5f4b432

**`claude.md`** — the project's standing principles. Read it. The
human-in-the-loop guarantee in particular is load-bearing for the product's
positioning, and at least one flagged-off feature (`page_scheduler`,
cron-created pages) exists in direct tension with it.

---

## 5. The one decision blocking everything

**Does the workspace UI move into the plugin, or does this ship as a
plugin + theme pair?**

The inventory row reads *"DECIDE FIRST: everything else depends on this."*
That is still true and still unresolved.

Today the core workspace UI lives in `work-copilot-theme/`. WordPress.org
distributes plugins and themes through separate directories with separate
review queues, so **"install the plugin" does not currently give anyone a
working product**.

Roughly six of the 23 remaining `Fix before launch` rows change shape
depending on the answer — where the REST lockdown filter belongs, how the
mission metabox reaches the workspace, the item row work, the widget, the CSS
pass. Doing them before the decision risks doing them twice.

Do not make this call unilaterally. Lay out the trade-offs and let the author
decide.

---

## 6. Work plan, in order

### First: the REST permission findings

Highest severity, and the fixes are small. Full detail in
`gtm/rest-permission-audit.md`; the order there is:

1. **`delete_heading` force-deletes any heading** (`class-rest-api.php:2927`).
   `wp_delete_post($id, true)` skips the trash — unrecoverable. Add
   `current_user_can('delete_post', $heading_id)` and drop the force flag.
   Two lines, worst consequence on the list.
2. **35 write endpoints take caller-supplied IDs with no object-level check.**
   Only five per-object checks exist across 49 routes. The correct pattern is
   already in the file (`update_item` at 2355, `delete_item` at 2445) — build a
   `WCP_REST_Auth::require_object($id, $cap)` helper returning `true|WP_Error`
   and apply it down the list.
3. **Unmetered API spend.** Every `/ai/*` route and `/embeddings/batch` bills
   the site owner's keys behind `edit_posts`, uncapped. Introduce a
   `wcp_use_ai` capability and a hard batch cap.
4. **`delete_prompt` mutates a site-wide option by array index**
   (`class-rest-api.php:1814`) — Contributor-writable, and racy: concurrent
   deletes shift indices and remove the wrong prompt. Address prompts by
   stable ID, gate at `manage_options` or make them per-user.
5. **Delete `/version`** — the sole `__return_true` route, discloses
   `PHP_VERSION` to anonymous callers, and its hardcoded `'1.2.1'` is already
   stale against the header's `1.2.2`.
6. **Verify the corpus-read scope.** `semantic_search()` and
   `get_context_items()` contain no author or capability filtering — that much
   is certain — but whether `post_status` narrows them needs two users and a
   running install.

**Framing that matters:** `claude.md` says *"Single-user first — no multi-user
permissions in MVP."* The blanket gate was a deliberate decision, correct for
a personal install. It simply does not survive public distribution. Present it
that way — this is not remedial work.

### Then: data integrity

- **Schema migrations never run.** `wcp_db_version` is stored but never
  compared, so `dbDelta` only fires on activation and an upgrade silently skips
  migration. Add an `admin_init` version check. This only harms people who
  already have data worth losing, which is the worst failure profile available.
- **No `uninstall.php`** for five custom tables. Reviewers check for this.
- **AI audit log has no retention or purge.** It stores full prompts, grows
  unbounded, and `readme.txt` names it as a privacy feature — so it must hold up.

### Then: the AI actions with blast radius

- `edit_items()` — hardened parsing and a hard batch cap. Already patched twice.
- `rewrite_page_content()` — snapshot to a WP revision before applying.
- Markdown rendering — confirm output escaping. XSS surface.
- Anthropic client — retry, backoff, and legible errors. BYO-key is the
  headline feature; a bad key must say so rather than fail silently.

### Then: wire the feature flags

The scaffold exists; **no feature is gated on a flag yet**. 16 flags are
registered covering the 18 features the review marked flag-off. Wiring them
needs a running install to confirm each surface actually disappears.

### Blocked on §5

Item row density, theme CSS, the AI widget, the REST lockdown filter's correct
home, surfacing the mission metabox.

---

## 7. Feature flags

`wp-copilot/includes/class-features.php`, loaded first in the bootstrap and
again in `activate()` (which runs outside it).

```php
if (wcp_feature('page_templates')) { /* surface */ }
```

Resolution, first match wins: a `WCP_FEATURE_<SLUG>` constant → the
`WCP_EXPERIMENTAL` master switch → the registry default. A per-flag constant
therefore beats the master switch, so a development instance can run
everything on while pinning one feature off:

```php
define('WCP_EXPERIMENTAL', true);
define('WCP_FEATURE_PAGE_SCHEDULER', false);
```

There is a `wcp_feature_enabled` filter, and add-ons can register flags via
`wcp_feature_registry`. Unknown slugs resolve to `false` so a typo hides a
feature rather than exposing an unfinished one.

**Two rules, both deliberate:**

- **Flags gate user-facing surfaces, never schema.** A flagged-off install and
  a flagged-on one must have identical database structure, or they drift and no
  migration can reconcile them. Table creation runs regardless of any flag.
- **No admin UI, by design.** Flags are `wp-config.php` constants. A release
  should not offer users switches for unfinished features, and reviewers should
  not find half-built surfaces behind a toggle. Don't add a settings screen for
  these without a deliberate decision to change that.

`wcp_ai_enabled` and `wcp_embeddings_enabled` are **not** part of this system
and should stay out of it. They are user settings that happen to be booleans;
folding them in turns a preference into a developer switch.

---

## 8. Traps

- **Never re-add `scripts/`, `deploy.sh` or `version-check.php`.** They are
  purged from history. `.gitignore` now blocks `scripts/` and `*.csv` (with
  `wp-copilot/sample-import.csv` explicitly excepted).
- **No third-party CDN assets, ever.** A hard WP.org rejection and it leaks
  visitor IPs. Both libraries are vendored at
  `work-copilot-theme/assets/js/vendor/` with their MIT licence files. Note the
  old marked enqueue was *unpinned* while declaring 12.0.0 — pin anything new.
- **Don't commit anything personal.** This repo is going public. `gtm/` holds
  planning material worth reviewing before publication, and
  `DOCUMENTATION.md:288` uses the author's first name in an example.
- **`gtm/__pycache__/` is tracked and shouldn't be.** Remove and gitignore.
- **Push only to `claude/work-copilot-gtm-planning-1rps0q`** unless told
  otherwise. Use `git push -u origin <branch>`.
- **Don't open a PR unless asked.**

---

## 9. Open questions for the author

1. **Theme/plugin split** (§5) — blocks roughly six launch items.
2. **The name.** `work-copilot` competes for search results against Microsoft's
   marketing budget. The risk is discoverability rather than legal —
   CopilotKit and Copilot Money both exist unbothered — but a distinctive
   modifier would own its own search term. Cheapest to change now.
3. **Delete the stale `claude/optimistic-hawking-r2fupq` branch** via the
   GitHub UI.
4. **Wiretap and OpenBiografy** — extract to their own repos when convenient.
   Neither is part of the launch.
