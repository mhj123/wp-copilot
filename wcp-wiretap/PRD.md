# PRD — Work Copilot Wiretap

A Work Copilot companion plugin for tracking investment KOLs (Key Opinion Leaders) on X.
It "wiretaps" a curated set of accounts, periodically fetches their tweets, detects when
they mention crypto or equity tickers, classifies whether each mention is an actionable
**recommendation** vs. a passing mention or hindsight reflection, and produces a
newsletter-style market writeup plus reviewable trade ideas — all human-in-the-loop.

> Working name was "CT Wiretap". Renamed to **Work Copilot Wiretap** because scope is
> crypto **and** equities (treated equally), not Crypto Twitter specifically.

- **Plugin slug:** `wcp-wiretap`
- **Status:** Proof of concept (POC) — keep it lightweight
- **Sibling plugins:** `wp-copilot` (core), `wcp-graph`, `wcp-delegation`

---

## 1. Goals

1. Maintain a tracked list of 20–30 X accounts ("KOLs"), addable manually or imported from an X List.
2. On a schedule (default 2×/day), fetch each KOL's recent tweets via the official X API.
3. Detect ticker mentions (crypto + equities) and surface market/macro themes.
4. For each ticker mention, classify it as **recommendation / mention / hindsight** and, for
   recommendations, capture direction and link to any prior calls on the same ticker by the same KOL.
5. Generate a newsletter-style writeup (market situation + recommendations) as a **draft post** for human review.
6. Surface time-sensitive recommendations as **alerts** in an admin dashboard.
7. Never write analytical conclusions to a published state automatically — AI proposes, human disposes.

### Non-goals (this POC)

- No automated trading, brokerage, or wallet integration.
- No multi-user roles/permissions (single-user, mirrors Work Copilot).
- No outbound email (alerts live in admin only).
- No performance/PnL tracking of past calls — **deferred** (designed for, see §10).
- No auto-generated trade theses — **deferred** (see §10).
- No real-time/streaming ingestion — batch polling only.

---

## 2. Core principles (inherited from Work Copilot)

- **Human-in-the-loop AI.** AI output is always a *proposal*. Recommendations are created in a
  pending state; the newsletter is created as a *draft* post. The human accepts, edits, or dismisses.
- **Prefer native WordPress constructs** where the data shape fits — KOLs and Recommendations are
  custom post types so they inherit the editor, list tables, taxonomy, and review UI for free.
- **Bounded AI context + strict JSON.** Every AI call receives a bounded context pack and returns
  strict JSON. All AI calls are logged (prompt, input snapshot, output snapshot, decision).
- **Deliberate, scoped exception to the core "no cron AI" rule.** Work Copilot core forbids
  cron-based AI. This plugin's entire premise is periodic fetch + analysis, so it *does* use
  WP-Cron — but strictly to (a) ingest data and (b) generate *proposals*. It never publishes,
  never auto-accepts, and never acts on a recommendation. This boundary is the guardrail; comment it in code.

---

## 3. Data model (hybrid storage)

### 3.1 Custom table — raw tweets (high volume, high churn)

`{$wpdb->prefix}wcp_wiretap_tweets`

| column | type | notes |
|---|---|---|
| `id` | BIGINT PK auto | internal id |
| `tweet_id` | VARCHAR(32) UNIQUE | X tweet id (idempotency key) |
| `kol_id` | BIGINT | FK → KOL post id |
| `author_handle` | VARCHAR(64) | denormalised for convenience |
| `text` | TEXT | tweet body |
| `created_at` | DATETIME | tweet timestamp (UTC) |
| `conversation_id` | VARCHAR(32) | thread grouping |
| `referenced_type` | VARCHAR(16) NULL | retweet/quote/reply/original |
| `entities_json` | LONGTEXT | cashtags, urls, mentions from API |
| `metrics_json` | LONGTEXT | like/retweet/reply counts at fetch time |
| `analysis_status` | VARCHAR(16) | `pending` / `analyzed` / `skipped` / `error` |
| `fetched_at` | DATETIME | ingest time |

Indexes: `kol_id`, `created_at`, `analysis_status`, unique `tweet_id`.

Raw tweets are **not** posts — they are bulk, disposable, and queried in ranges. A custom table
keeps `wp_posts` clean and queries fast.

### 3.2 Custom post type — `wcp_kol`

The tracked accounts.

- Title: display name. Meta: `handle`, `x_user_id`, `tracking_status` (active/paused),
  `list_source` (manual / X-list-id), `last_fetched_at`, `notes`.
- Native list table = the KOL management screen (no custom UI needed).

### 3.3 Custom post type — `wcp_recommendation`

One per detected actionable call. Created in `pending` (draft) status.

- Title: e.g. `$SOL — long (calculated) — @handle — 2026-06-11`.
- Meta: `ticker`, `asset_class` (crypto|equity), `direction` (long/short/accumulate/exit/watch),
  `classification` (recommendation|mention|hindsight), `conviction` (low/med/high — best-effort),
  `source_tweet_id`, `kol_id`, `rationale_excerpt`, `prior_calls_json` (links to earlier recs on
  same ticker+KOL), `ai_log_id`, `review_status` (pending/accepted/dismissed).
- Body: the AI's structured summary + quoted tweet.

### 3.4 Taxonomies (shared, hierarchical where useful)

- `wcp_ticker` — non-hierarchical; one term per resolved ticker (`$BTC`, `$AAPL`). Applied to
  recommendations (and optionally analyzed tweets) for fast "all calls on $X" rollups.
- `wcp_theme` — market/macro themes (e.g. "rate cuts", "AI capex", "memecoin rotation").
- `wcp_asset_class` — crypto / equity.

### 3.5 AI log

Reuse Work Copilot core's AI logging if exposed; otherwise a custom table
`{$wpdb->prefix}wcp_wiretap_ai_log` with: `id`, `kind` (classification|newsletter),
`prompt`, `input_snapshot`, `output_snapshot`, `model`, `created_at`, `decision`, `related_object_id`.

---

## 4. Functional requirements

### 4.1 KOL management

- Add a KOL by handle (resolve to `x_user_id` via X API on save).
- Import KOLs from an X List by List ID (bulk-create `wcp_kol` posts; dedupe on `x_user_id`).
- Pause/resume tracking per KOL.
- Cap enforced softly at ~30 (warn beyond, to keep within API budget — see §6).

### 4.2 Ingestion (scheduled)

- WP-Cron event `wcp_wiretap_fetch`, default twice daily (configurable interval).
- For each active KOL, fetch tweets since `last_fetched_at` (X API `users/:id/tweets`,
  `start_time` window, exclude pure retweets unless configured).
- Upsert into `wcp_wiretap_tweets` keyed on `tweet_id` (idempotent; safe re-runs).
- Update `last_fetched_at`. Record per-run fetch stats (counts, rate-limit headers, errors).
- Respect rate limits: backoff + resume; never exceed the configured monthly pull budget.

### 4.3 Analysis (scheduled, follows ingestion)

Two-stage to control AI cost:

1. **Cheap pre-filter (no LLM):** scan tweet text/entities for cashtags (`$XXX`), known
   ticker patterns, and a configurable keyword/theme lexicon. Tweets with zero candidate
   signals → `analysis_status = skipped`.
2. **LLM classification (per candidate tweet):** send a bounded context pack →
   strict JSON. The model:
   - resolves each candidate ticker + `asset_class` (disambiguates `$X` collisions using context);
   - classifies the *mention* as `recommendation` / `mention` / `hindsight`;
   - for recommendations: `direction`, best-effort `conviction`, one-line `rationale_excerpt`;
   - extracts up to N market/macro `themes`.
   - Output also flags low-confidence cases for human attention rather than silently dropping.

For each `recommendation`, create a `wcp_recommendation` post (pending), attach `wcp_ticker`/
`wcp_theme`/`wcp_asset_class` terms, and populate `prior_calls_json` by querying existing
recommendations with the same ticker + KOL.

**Strict JSON contract (classification):**

```json
{
  "tweet_id": "string",
  "signals": [
    {
      "ticker": "$SOL",
      "asset_class": "crypto",
      "classification": "recommendation",
      "direction": "long",
      "conviction": "medium",
      "rationale_excerpt": "string",
      "confidence": 0.0
    }
  ],
  "themes": ["string"],
  "notes": "string"
}
```

### 4.4 Newsletter generation (scheduled or on-demand)

- Compile the latest analysis window into a single bounded context pack (top recommendations,
  recurring themes, notable KOL activity).
- One LLM call → markdown writeup with sections: **Market situation**, **New recommendations**,
  **Repeat / reinforced calls**, **Themes to watch**.
- Saved as a **draft** post (post type `post`, tagged `wcp-wiretap-newsletter`). Human reviews,
  edits, publishes manually. AI never publishes.

### 4.5 Alerts & review dashboard

- Admin page "Wiretap" with:
  - **Inbox:** pending recommendations (native-ish list) with Accept / Dismiss / Edit.
  - **Alerts widget:** new high-confidence recommendations since last viewed.
  - **Latest newsletter:** link to the current draft.
  - **Run status:** last fetch/analysis time, counts, errors, rate-limit budget remaining.
- Accept → `review_status = accepted` (recommendation "confirmed"); Dismiss → `dismissed`
  (retained for audit + to suppress re-proposing the same call). Both decisions logged.

---

## 5. Architecture & files

Follows the sibling-plugin layout (`wcp-graph`, `wcp-delegation`).

```
wcp-wiretap/
  wcp-wiretap.php                      # bootstrap, activation (create table + cron), CPT/taxonomy registration
  includes/
    class-tweet-source.php             # X API v2 client (auth, fetch tweets, resolve handles, import list)
    class-ingest.php                   # cron fetch → upsert tweets
    class-prefilter.php                # cheap non-LLM candidate detection
    class-analyzer.php                 # LLM classification → recommendations (strict JSON)
    class-newsletter.php               # LLM writeup → draft post
    class-recommendation-repo.php      # CRUD + prior-call lookup
    class-tweet-repo.php               # custom table access
    class-ai-log.php                   # logging (or shim to core)
    class-rest-api.php                 # admin actions: add/import KOL, accept/dismiss, run-now
  admin/
    class-dashboard.php                # Wiretap admin page (inbox, alerts, status)
    class-settings.php                 # API keys, schedule, budget, lexicon
```

- **REST endpoints** for all non-trivial actions (add/import KOL, run-now, accept/dismiss rec),
  nonce-protected, capability-gated.
- **AI provider:** Claude via the Anthropic API (latest Claude model), consistent with Work Copilot
  core. Classification can use a smaller/faster model; newsletter a stronger one. Strict JSON via
  tool/response-format; validate before persisting.

---

## 6. X API & budget (sizing the POC)

- **Tier:** official X API. At ~30 KOLs × 2 fetches/day, pulling recent tweets per account,
  estimate worst-case monthly tweet reads and confirm it fits the chosen tier's post-cap.
  Spec the polling math against the chosen tier at build time; expose `max_kols`, `fetch_interval`,
  and a hard `monthly_pull_cap` in settings with a visible budget meter.
- Store API credentials in settings (option, not in code); never log secrets.
- Provider access is wrapped in `class-tweet-source.php` so a future adapter swap is contained.

---

## 7. Configuration (settings page)

- X API bearer token / keys.
- Anthropic API key (or inherit from Work Copilot core if shared).
- Fetch schedule (default 2×/day) and per-fetch lookback window.
- `max_kols`, `monthly_pull_cap`, retweet inclusion toggle.
- Ticker/keyword/theme lexicon (seed list + editable).
- Confidence threshold for "alert-worthy" recommendations.
- Models for classification vs. newsletter.

---

## 8. Human-in-the-loop guardrails (must comment in code)

- AI never creates published content: recommendations = pending, newsletter = draft.
- Every LLM call logged with prompt + input/output snapshots + decision.
- Dismissed recommendations are retained and used to suppress duplicate re-proposals.
- Cron only ingests + proposes; it does not accept, publish, or act.

---

## 9. Acceptance criteria (POC done = all true)

1. Can add KOLs manually and import from an X List; KOLs appear in the list table.
2. Scheduled fetch ingests tweets into the custom table idempotently (re-run = no dupes).
3. Pre-filter + LLM classification produces `wcp_recommendation` (pending) only for actionable calls,
   with ticker, asset class, direction, and links to prior calls on the same ticker+KOL.
4. A newsletter draft post is generated covering market situation + recommendations.
5. Admin dashboard lists pending recommendations with working Accept / Dismiss, plus run status
   and a rate-limit/budget indicator.
6. All AI calls are logged; nothing is auto-published.

---

## 10. Deferred (designed-for, not built in POC)

- **Performance tracking:** ingest price data per ticker at call time + horizons (e.g. 7/30/90d)
  to score each KOL's hit rate. The `wcp_recommendation` schema already captures ticker, direction,
  and timestamp to support this later.
- **Trade theses:** synthesize across multiple KOLs/signals into combined, reviewable theses
  (decide per-recommendation vs. cross-signal synthesis when picked up). Recommendations are the
  building blocks.
- **Outbound email** alerts, **RAG** over historical tweets/calls, **MCP** exposure.
- **Provider-agnostic tweet source** beyond the X API adapter.
```
