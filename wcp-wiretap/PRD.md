# PRD — Work Copilot Wiretap (v2)

A Work Copilot companion plugin that tracks trusted investment KOLs on X, detects **new** actionable calls, measures **how early you are** to each idea, formulates conditional **trade plans** for approval, and helps you **discover** the next KOLs to tap. All AI output is a proposal; a human accepts, edits, or dismisses. Nothing is ever published, traded, or acted on automatically.

- Plugin slug: `wcp-wiretap`
- Status: POC — lightweight, but built on the seams described in §12
- Siblings: `wp-copilot` (core), `wcp-graph`, `wcp-delegation`
- Supersedes PRD v1. This document is self-contained; do not consult v1.

---

## 1. Core principle

**When a trusted KOL makes a NEW call — surface it to be actioned.**

Everything else in the plugin serves that loop:

1. **Ingest** what tracked KOLs post (batch, 2×/day default).
2. **Detect & classify** ticker mentions → recommendation / mention / hindsight.
3. **Determine newness** deterministically (not by LLM): is this a new call by this KOL on this ticker, or a repeat/reinforcement?
4. **Score earliness**: how early is the *user* to this asset right now (§5).
5. **Propose**: pending recommendations, trade plans, daily digest, emerging-theme flags — all reviewable in the dashboard, time-sensitive ones pushed to Telegram.
6. **Human decides.** Accept/dismiss/edit in the dashboard. The plugin never executes trades and never publishes content.

### Inherited Work Copilot principles

- **Human-in-the-loop.** AI creates only proposals: recommendations in `wcp_pending` status, digests/newsletters as WordPress drafts, trade plans in `proposed` status.
- **Native WP constructs** where the data shape fits (CPTs, taxonomies, list tables).
- **Bounded context + strict JSON** for every LLM call; every call logged (prompt, input snapshot, output snapshot, model, decision).
- **Scoped exception to core's "no cron AI" rule**: cron may *ingest* and *propose*, never accept/publish/act. Comment this boundary in code.

### Non-goals (POC)

No automated trading or brokerage/wallet integration. No multi-user roles. No PnL/hit-rate scoring of KOLs (designed for, §13). No real-time streaming (batch polling + a 15-min price watcher only). Telegram is **notify-only** — no inline approve/reject actions.

---

## 2. Features (F1–F8)

### F1. New-call detection & surfacing (core loop)

- Two-stage analysis per tweet: **cheap pre-filter** (cashtag/ticker-pattern/lexicon scan, no LLM; zero signals → `skipped`) then **LLM classification** (strict JSON, §7.1) over a context pack that includes **the full thread** (siblings by `conversation_id`) and **quoted-tweet text** — single tweets out of thread context routinely misclassify.
- **Newness is computed in code, not by the LLM.** A classified recommendation is a NEW call iff any of:
  - first recommendation ever by this KOL on this ticker; or
  - direction differs from this KOL's most recent call on the ticker; or
  - the most recent same-direction call is older than `new_call_window` (default 30 days).
  Otherwise it is a **reinforcement**: increment `reinforced_count` on the existing recommendation, append the tweet reference, bump `last_reinforced_at`. Do not create a duplicate pending item. (Reinforcement is itself signal — it feeds the digest and earliness score.)
- New calls create a `wcp_recommendation` post in `wcp_pending` status with: ticker, asset class, direction (long/short/accumulate/exit/watch), confidence (0–1 float; no low/med/high conviction field — it's noise), rationale excerpt, source tweet + thread link, prior calls by same KOL (queried live from the repo, **not** stored as a JSON snapshot), earliness snapshot (§5), and price-at-call (§F7 price source — captured immediately because it cannot be backfilled).
- New calls above `alert_confidence_threshold` from KOLs with `trust_score ≥ trust_alert_min` (default 4) → dashboard alert + Telegram push with a deep link to the recommendation.
- Dismissed recommendations are retained (audit + suppression). Accepting/dismissing/editing are all logged; edits store a before/after diff in the AI log table.

### F2. KOL management (add your own)

- CPT `wcp_kol`. Add by handle (resolve `x_user_id` via X API on save); bulk-import from an X List ID (dedupe on `x_user_id`); pause/resume per KOL.
- **Trust score 1–5, set manually by the user.** Trust ≥ 4 = "originator tier" — weights alerting, digest ranking, discovery triangulation, and the earliness score. Default for new KOLs: 3.
- Soft cap `max_kols` (default 30) with a warning tied to the live budget meter (§9).
- Handle edge cases: renamed handles (track by `x_user_id`, refresh handle on fetch), suspended/protected/deleted accounts → auto-pause + dashboard flag.

### F3. KOL discovery — find who to tap next

Three mechanisms, cheapest first:

1. **Corpus signals (free, automatic).** Nightly job scans ingested tweets for accounts your tracked KOLs repeatedly retweet, quote, or reply to. Accounts crossing thresholds (≥ `discovery_min_interactions` interactions from ≥ 2 distinct tracked KOLs in 30d) enter the **suggestion queue**.
2. **Graph triangulation (on-demand, budgeted).** Button per tier-1 KOL: fetch their following list (one page, capped). Score candidates by how many of your tier-1 KOLs follow them (triangulation count), plus an LLM topical-fit pass over bio + pinned/sample tweets. High scorers enter the suggestion queue with a reason string ("Followed by 4 of your 6 tier-1 KOLs; posts on L1 infra").
3. **Earliest-callers search (on-demand, budgeted).** Given a ticker + date range, run a capped full-archive search on the cashtag (`earliest_search_max_results`, default 500; show estimated read cost and require confirmation before running). Rank authors by earliest mention date, cross-referenced with price at that date (§F7 price source) so "early" means *early relative to the move*, not just chronologically first. Output: ranked list with handle, first-mention date, price then vs now, follower count → one-click add to suggestion queue.
   - A free fallback always exists: earliest caller **within the ingested corpus**.

Suggestion queue = `wcp_kol` posts in a `suggested` tracking status with `discovery_source` + `discovery_reason` meta. One-click promote to `active` (this is the only path that spends ongoing polling budget) or dismiss (suppressed from re-suggestion).

### F4. Daily digest — what did the list post in the last day?

- Cron, default 07:00 site time, per tracked group (all KOLs, or per imported X List).
- Deterministic pre-aggregation first (code, not LLM): tickers mentioned in last 24h with mention counts, distinct KOLs, stance mix, new-vs-reinforced calls, earliness band per ticker. Then **one LLM call** over that bounded pack → markdown with sections:
  1. **Market pulse** — the day's dominant narratives.
  2. **New calls** — table: ticker, KOL, direction, confidence, earliness band.
  3. **Repeat / reinforced calls.**
  4. **Tickers & theses** — per ticker: who said what, thesis one-liners, links to notable threads.
  5. **Themes to watch** (feeds F5).
- Saved as a **draft** post (tagged `wcp-wiretap-digest`) + rendered in the dashboard. Every digest templates a "not financial advice" disclaimer. An on-demand "generate digest now" button covers arbitrary windows (e.g. last 3 days).

### F5. Emerging themes / sectors / tickers

- Classification already extracts themes (taxonomy `wcp_theme`); tickers land in `wcp_ticker`. A nightly rollup writes per-day counts to the aggregates table (§4.4).
- **Emerging** = default rule, all thresholds configurable:
  - mentioned by ≥ 3 distinct tracked KOLs in trailing 7d, AND
  - trailing-7d mentions ≥ 2× the prior 7d, AND
  - first-ever mention within the last `emerging_max_age` (default 45d) — otherwise it's "resurgent", labeled separately.
- Dashboard "Emerging" panel: themes and tickers ranked by (velocity × distinct-KOL count × trust weighting), each with sparkline of daily mentions and earliness band. Crossing the emerging threshold can optionally Telegram-notify (off by default).

### F6. Earliness meter — "how early am I?"

Per-ticker score + band answering *am I too early, on time, or late?* Full spec in §5. Shown on every recommendation, digest ticker row, and emerging-panel entry.

### F7. Trade plans — conditional, watched, approved

The "Ansem says HYPE bottoms in the 40s → watch for a buy around there → surface for approval" flow.

- **Creation.** From any recommendation, an LLM extraction call (strict JSON, §7.3) proposes a `wcp_trade_plan`: entry zone (e.g. "bottom in the 40s" → low 40, high 49), invalidation level/condition, targets if stated, timeframe if stated, thesis summary. If the KOL gave no levels, the plan is created with entry `unspecified` and flagged for the human to set levels manually. Auto-proposed for new calls above the alert threshold; also creatable manually from any rec.
- **Lifecycle** (post statuses): `proposed` → (human approves, may edit levels) → `armed` → (price watcher fires) → `triggered` → human closes → `closed`; or `expired` (default TTL 30d, configurable per plan) / `cancelled`. Only a human can move proposed→armed. **Triggered means "notify", never "execute".**
- **Price watcher.** Cron every 15 min, *armed plans only*. Price source adapter `class-price-source.php` with two drivers: CoinGecko (crypto, free tier — respect its rate limits) and Stooq (equities, free EOD/delayed; document the delay caveat in the UI). Entry-zone hit → status `triggered`, dashboard alert + Telegram push ("HYPE $47.80 — inside your 40–49 buy zone. Plan: [link]"). Invalidation hit while armed → `invalidated` alert. All price observations for armed plans and for price-at-call snapshots go to the prices table (§4.5).
- **Guardrail (comment in code):** no order routing, no exchange/wallet/brokerage API anywhere in this plugin, ever. Telegram messages contain no action buttons.

### F8. Decision check-in

"Check in" button on any recommendation or trade plan:

- Context pack (bounded): original tweet + thread; all subsequent tweets by the same KOL mentioning the ticker (has the KOL gone quiet, doubled down, or flipped?); other tracked KOLs' recent mentions of the ticker; price series since the call vs entry/invalidation levels; current earliness snapshot.
- One LLM call → strict JSON memo (§7.4): `thesis_status` (intact / strengthened / weakened / invalidated), key developments, KOL stance change, suggested next look (hold / revisit / tighten invalidation / exit-watch), rationale.
- Memo is stored as a timestamped note on the object and rendered in the dashboard. It is advisory text only — it changes no statuses.

---

## 3. Earliness heuristic — design rationale

*(§5 is the implementable spec; this section explains the thinking so future changes don't break the idea.)*

"How early am I?" decomposes into two independent axes:

- **Social diffusion** — how far the idea has spread through the attention graph. Proxy: your tracked-KOL corpus (an imperfect but honest sample of "smart attention").
- **Market confirmation** — how far price/volume has already traveled since the idea surfaced.

The four quadrants define the bands:

| | Price hasn't moved | Price has moved |
|---|---|---|
| **Few KOLs on it** | **Too early** — attention without confirmation; risk = dead money or wrong thesis | **Missed the quiet move** — something else drove it; treat as late |
| **Many KOLs on it** | **On time** — attention accelerating, confirmation starting | **Crowded/late** — consensus and extension |

Key judgments baked into the spec:

- **Diffusion is measured against *your* tracked set**, so "early" means "early relative to the people you trust", which is the actionable question — not early vs the whole market, which you can't observe cheaply.
- **Velocity matters more than level.** 3 KOLs mentioning a ticker means something different if it was 0 last week vs 3 every week for a month. The score uses week-over-week acceleration.
- **Who is talking matters.** Tier-1 (trust ≥ 4) originators mentioning first = genuinely early; only echo-tier accounts = the originators may already be exiting.
- **Price extension is anchored to the first tracked call**, because that's the reference point of the opportunity you're evaluating.
- **Output honest facts, not fake precision.** The band label is a heuristic; the facts sentence ("3rd of 28 tracked KOLs; first call 12d ago at $32; now $41, +28%; 9 mentions this week vs 3 prior") is what the user should actually reason from. Always render both.
- **Known failure modes (document in UI tooltip):** reflexive assets (memecoins) where attention *is* the fundamental, so "crowded" can keep working longer than the band implies; survivorship bias if thresholds get tuned only on winners; small-sample noise for tickers with < 5 total mentions (render band as "insufficient data"); corpus blindness before tracking began (the F3 archive search partially repairs this by backfilling `first_call_at`).

---

## 4. Data model (hybrid storage)

### 4.1 Custom table — raw tweets: `{$wpdb->prefix}wcp_wiretap_tweets`

| column | type | notes |
|---|---|---|
| id | BIGINT PK auto | |
| tweet_id | VARCHAR(32) UNIQUE | idempotency key |
| kol_id | BIGINT | FK → KOL post |
| author_handle | VARCHAR(64) | denormalised |
| text | TEXT | includes expanded quoted-tweet text appended with a marker |
| created_at | DATETIME | tweet ts, UTC |
| conversation_id | VARCHAR(32) | thread grouping — indexed; analysis fetches siblings |
| referenced_type | VARCHAR(16) NULL | retweet/quote/reply/original |
| entities_json | LONGTEXT | cashtags, urls, mentions |
| metrics_json | LONGTEXT | engagement at fetch |
| analysis_status | VARCHAR(16) | pending/analyzed/skipped/error |
| fetched_at | DATETIME | |

Indexes: `kol_id`, `created_at`, `analysis_status`, `conversation_id`, unique `tweet_id`.
**Retention:** prune raw tweets older than `tweet_retention_days` (default 90) *except* tweets referenced by a recommendation or trade plan. Note in settings UI: X API ToS constrains long-term storage and redistribution of content; digests should quote sparingly and link to the original post.

### 4.2 CPTs

- **`wcp_kol`** — meta: `handle`, `x_user_id`, `trust_score` (1–5), `list_source`, `last_fetched_at`, `discovery_source`, `discovery_reason`, `notes`. Tracking status via post meta `tracking_status`: `suggested` / `active` / `paused` / `dismissed`.
- **`wcp_recommendation`** — **single source of truth for review state = custom post statuses**: `wcp_pending`, `wcp_accepted`, `wcp_dismissed` (no duplicate `review_status` meta). Meta: `ticker`, `asset_class`, `direction`, `confidence`, `source_tweet_id`, `kol_id`, `rationale_excerpt`, `is_new_call`, `reinforced_count`, `last_reinforced_at`, `reinforcing_tweet_ids`, `price_at_call`, `earliness_at_call` (band + inputs snapshot), `ai_log_id`. Title: `$SOL — long — @handle — 2026-06-11`.
- **`wcp_trade_plan`** — statuses: `wcp_proposed`, `wcp_armed`, `wcp_triggered`, `wcp_invalidated`, `wcp_closed`, `wcp_expired`, `wcp_cancelled`. Meta: `source_rec_id`, `ticker`, `asset_class`, `direction`, `entry_low`, `entry_high`, `invalidation`, `targets_json`, `timeframe`, `thesis`, `expires_at`, `price_at_creation`, `triggered_at`, `ai_log_id`.

### 4.3 Taxonomies

`wcp_ticker` (canonical resolved symbols), `wcp_theme`, `wcp_asset_class` (crypto/equity). Applied to recommendations, trade plans, and (ticker only) analyzed tweets.

**Ticker registry:** `wcp_ticker` terms carry meta `canonical_symbol`, `asset_class`, `coingecko_id` / `stooq_symbol`, `aliases`. Seeded at activation from bundled lists (top ~500 coins by mcap via CoinGecko dump + S&P 500 / Nasdaq-100 symbols file). Unknown cashtags resolve via LLM against context; unresolved or ambiguous → recommendation still created but flagged `ticker_unverified` for human confirmation, which adds the term to the registry.

### 4.4 Custom table — daily aggregates: `{$wpdb->prefix}wcp_wiretap_daily_stats`

`(id, stat_date, object_type [ticker|theme], object_key, mention_count, distinct_kols, trust_weighted_mentions, new_calls, reinforcements)` — unique on `(stat_date, object_type, object_key)`. Written by the nightly rollup; read by earliness, emerging detection, digest pre-aggregation. Makes all velocity queries O(days), not O(tweets).

### 4.5 Custom table — prices: `{$wpdb->prefix}wcp_wiretap_prices`

`(id, ticker, asset_class, price, source, observed_at)` — indexed `(ticker, observed_at)`. Written at call time, by the 15-min watcher for armed plans, and on-demand for earliness/check-in computations (cache ≥ 15 min to respect free-tier limits).

### 4.6 AI log

Reuse Work Copilot core's AI logging if exposed; else `{$wpdb->prefix}wcp_wiretap_ai_log`: `(id, kind [classification|digest|plan_extraction|checkin|discovery_fit], prompt, input_snapshot, output_snapshot, model, tokens_in, tokens_out, created_at, decision, related_object_id)`. Also stores human edit diffs on recommendations/plans.

---

## 5. Earliness heuristic — spec

Computed per ticker `T` at evaluation time `t`. All defaults configurable in settings.

**Inputs**

- `N` = active tracked KOLs; `D` = distinct tracked KOLs with ≥ 1 mention of `T` in trailing 30d → **diffusion** `d = D/N`.
- **velocity** `v = mentions(last 7d) / max(mentions(prior 7d), 1)` (trust-weighted counts from the aggregates table).
- `first_call_at`, `price_first` = timestamp/price of the earliest tracked recommendation on `T` (backfillable via F3 archive search). **price extension** `x = price_now / price_first`.
- **originator share** `o` = fraction of mentioning KOLs with trust ≥ 4.
- `total_mentions_30d` — if < 5, output band `insufficient data` and stop.

**Band rules (evaluate top-down, first match wins)**

| band | rule (defaults) | meaning |
|---|---|---|
| `too_early` | d < 0.10 AND v ≤ 1.0 AND 0.85 ≤ x ≤ 1.15 | attention without confirmation |
| `on_time` | 0.10 ≤ d ≤ 0.40 AND v > 1.5 AND x < 1.5 | accelerating attention, early confirmation |
| `crowded` | d > 0.40 OR 1.5 ≤ x ≤ 3.0 | consensus forming / price extended |
| `late` | d > 0.70 OR x > 3.0 OR (v < 0.7 after a 30d mention peak) | saturated or exhausted |
| `quiet_mover` | d < 0.10 AND x > 1.5 | price moved without your KOLs — treat as late, investigate |
| `mixed` | anything else | render facts, no band claim |

Modifier: if `o ≥ 0.5` (mostly originators talking), shift one band earlier (late→crowded, crowded→on_time); if `o ≤ 0.2`, shift one band later. Never shift out of `insufficient data`.

**Output object** (stored on recs as `earliness_at_call`, computed live elsewhere):

```json
{
  "ticker": "$HYPE",
  "band": "on_time",
  "facts": "3rd of 28 tracked KOLs to mention; first call 12d ago at $32.10; now $41.05 (+27.9%); 9 trust-weighted mentions this week vs 3 prior; 2 of 3 mentioners are tier-1.",
  "inputs": {"d": 0.11, "v": 3.0, "x": 1.28, "o": 0.67, "total_mentions_30d": 12},
  "computed_at": "ISO8601"
}
```

**Render band + facts together, always.** The band is the headline; the facts are the argument. UI tooltip lists the failure modes from §3.

---

## 6. Pipelines (cron & queue)

WP-Cron is traffic-triggered and unreliable on a low-traffic admin site. **Require**: `DISABLE_WP_CRON = true` + a system crontab hitting `wp-cron.php` every 5 min (document in README; activation notice if WP-Cron appears un-punctual). Every job takes a transient lock (no overlapping runs) and records a run row (started/finished, counts, errors, API reads used, tokens used) shown in the dashboard.

| job | schedule | work |
|---|---|---|
| `wcp_wiretap_fetch` | 2×/day default | per active KOL: fetch since `last_fetched_at` (users/:id/tweets, exclude pure RTs unless configured), expand quoted tweets, upsert idempotently on `tweet_id`, respect rate-limit headers with backoff+resume, stop at the monthly budget cap |
| `wcp_wiretap_analyze` | chained after fetch | pre-filter, then LLM classification **in chunks** (default 10 tweets/batch via Action Scheduler or a self-rescheduling queue — a 50-call loop in one cron tick will time out); newness computation; rec creation; alerting |
| `wcp_wiretap_rollup` | nightly | daily_stats aggregation; emerging detection; corpus discovery scan (F3.1); tweet pruning |
| `wcp_wiretap_digest` | daily 07:00 | pre-aggregate + one LLM call → draft digest post |
| `wcp_wiretap_price_watch` | every 15 min | armed plans only: fetch prices, evaluate entry/invalidation, trigger alerts, expire stale plans |

---

## 7. LLM calls (Claude via Anthropic API; strict JSON via tool/response-format; validate schema before persisting; log everything)

Models configurable: classification/extraction/fit → small fast model; digest/check-in → stronger model.

### 7.1 Classification (per candidate tweet, thread-aware)

Input pack: tweet text, thread siblings, quoted text, author handle + trust tier, candidate cashtags, ticker-registry matches, lexicon hits. Output:

```json
{
  "tweet_id": "string",
  "signals": [{
    "ticker": "$SOL", "ticker_resolved": true, "asset_class": "crypto",
    "classification": "recommendation|mention|hindsight",
    "direction": "long|short|accumulate|exit|watch",
    "rationale_excerpt": "string", "confidence": 0.0
  }],
  "themes": ["string"], "notes": "string"
}
```

Low-confidence (< `review_confidence_floor`) signals are flagged for human attention, never silently dropped. Newness (§F1) is computed after this call, in code.

### 7.2 Digest — one call over the deterministic pre-aggregation pack (§F4). Output: markdown sections + a JSON header listing tickers/themes covered (for tagging).

### 7.3 Trade-plan extraction

```json
{
  "has_plan": true, "entry": {"type": "zone|level|market|unspecified", "low": 40.0, "high": 49.0},
  "invalidation": "string|null", "targets": [], "timeframe": "string|null",
  "thesis": "string", "confidence": 0.0
}
```

### 7.4 Check-in memo

```json
{
  "thesis_status": "intact|strengthened|weakened|invalidated",
  "key_developments": ["string"], "kol_stance_change": "string",
  "suggested_next_look": "hold|revisit|tighten_invalidation|exit_watch",
  "rationale": "string"
}
```

### 7.5 Discovery topical fit — bio + sample tweets → `{fit: 0..1, focus_areas: [], reason: "string"}`.

---

## 8. Admin UI ("Wiretap" menu)

- **Inbox** — pending recommendations: ticker, KOL (+trust), direction, confidence, earliness band + facts, thread link; Accept / Dismiss / Edit / Create-plan / Check-in. Alerts strip: high-confidence new calls and triggered plans since last view.
- **Trade plans** — kanban-ish by status; approve (→armed) with editable levels; triggered plans highlighted.
- **KOLs** — native list table + suggestion queue tab (score, reason, promote/dismiss) + "find earliest callers of $X" and "expand graph from KOL" actions (each shows estimated API cost, requires confirmation).
- **Digest** — latest draft render + generate-now.
- **Emerging** — themes/tickers panel (§F5).
- **Runs & budget** — last runs, errors, X-reads meter vs `monthly_read_cap`, Anthropic token/cost meter, per-run cost line.
- **Settings** — §10.

All actions via nonce-protected, capability-gated REST endpoints: `add-kol`, `import-list`, `promote-kol`, `dismiss-kol`, `accept-rec`, `dismiss-rec`, `edit-rec`, `create-plan`, `arm-plan`, `cancel-plan`, `close-plan`, `checkin`, `run-now`, `discover-graph`, `discover-earliest`, `generate-digest`.

---

## 9. X API budget (pay-per-use)

X API default pricing is pay-per-use (~$0.005 per post read; verify current rates at build time and store as a setting). Sizing at defaults:

- Polling: 30 KOLs × 2 fetches/day × ~10 tweets avg = ~18,000 reads/mo ≈ **$90/mo**
- Thread/quote expansion overhead: ~+20% ≈ **$18/mo**
- On-demand: graph page ≈ up-to-1,000 reads ≈ $5/action; earliest-caller search ≈ up to `earliest_search_max_results` (500) reads ≈ $2.50/query — both gated behind cost-confirmations.
- Hard `monthly_read_cap` (default $150 equivalent) — fetch stops, dashboard warns, nothing silently drops except deferred polling.

Credentials in options, never in code, never logged. All provider access wrapped in `class-tweet-source.php` (future adapter swap contained).

---

## 10. Settings

X API keys + per-read price; Anthropic key (or inherit from core) + model choices; Telegram bot token + chat id + toggles (new-call alerts / plan triggers / emerging); fetch schedule + lookback + retweet toggle; `max_kols`, `monthly_read_cap`, `tweet_retention_days`; lexicon editor; `new_call_window`, `alert_confidence_threshold`, `review_confidence_floor`, `trust_alert_min`; earliness thresholds (§5 table); emerging thresholds; discovery caps; plan TTL default; price-source config.

---

## 11. Files

```
wcp-wiretap/
  wcp-wiretap.php                    # bootstrap, activation (tables, cron, statuses), CPT/tax registration
  includes/
    class-tweet-source.php           # X API v2 client (fetch, resolve, list import, graph, archive search)
    class-ingest.php                 # fetch cron → upsert
    class-prefilter.php              # non-LLM candidate detection
    class-analyzer.php               # LLM classification + newness logic → recs
    class-earliness.php              # §5 heuristic
    class-trade-plan.php             # extraction, lifecycle, price watcher
    class-price-source.php           # CoinGecko + Stooq drivers
    class-digest.php                 # pre-aggregation + LLM digest
    class-themes.php                 # rollup + emerging detection
    class-discovery.php              # corpus scan, graph triangulation, earliest-caller search
    class-checkin.php                # decision check-in memos
    class-telegram.php               # notify-only pushes
    class-recommendation-repo.php    # CRUD, prior-call lookup, reinforcement
    class-tweet-repo.php             # tweets table
    class-ticker-registry.php        # canonical symbol resolution, seeds
    class-ai-log.php                 # logging (or core shim)
    class-rest-api.php               # endpoints (§8)
  admin/
    class-dashboard.php
    class-settings.php
  data/
    tickers-crypto-seed.json
    tickers-equity-seed.json
```

---

## 12. Guardrails (comment each in code)

1. AI never creates published content or non-proposal states: recs = `wcp_pending`, digests = draft, plans = `wcp_proposed`.
2. Cron only ingests and proposes; only humans accept, arm, publish.
3. **No trading:** no exchange/brokerage/wallet API, no order routing, anywhere. Triggered = notified.
4. Telegram is one-way; no inline actions.
5. Every LLM call logged with snapshots; human edits logged with diffs.
6. Dismissals retained and used for suppression.
7. All generated market content carries a not-financial-advice disclaimer.
8. Secrets in options only; consider `WCP_WIRETAP_ENCRYPT_KEY` env-based encryption at rest for tokens; never logged.

---

## 13. Milestones & acceptance criteria

**M1 — Core loop.** KOL CRUD + list import + trust scores; fetch/ingest idempotent (re-run = zero dupes); pre-filter + chunked classification with thread context; newness logic (repeat within window reinforces, doesn't duplicate); pending recs with price-at-call + earliness snapshot; inbox Accept/Dismiss/Edit working; AI log complete; budget meter live.
*Quality bar:* on a hand-labeled set of ≥ 100 real tweets: recommendation-class precision ≥ 80%, ticker resolution accuracy ≥ 95%; misses reviewed before M2.

**M2 — Awareness.** Daily digest draft generating with all five sections; nightly rollup + emerging panel with sparklines; earliness bands live on all surfaces with facts strings; retention pruning working.

**M3 — Action.** Trade-plan extraction ("bottom in 40s" → 40–49 zone verified as a test case); full lifecycle; 15-min price watcher triggers correctly against both price drivers; Telegram pushes for new calls + triggers with deep links; check-in memos render.

**M4 — Discovery.** Corpus suggestions populate; graph triangulation with cost-confirm; earliest-caller archive search with cost-confirm + corpus fallback; suggestion queue promote/dismiss.

**POC done** = all four milestones' criteria true, plus: nothing auto-published/auto-armed anywhere (verified by code search for the guardrail comments), and a fresh-install activation completes with seeded ticker registry and a working system-cron setup notice.

---

## 14. Deferred (designed-for)

- **KOL scorecards / PnL:** price-at-call is already captured; add 7/30/90d horizon returns → hit rate and average lead time per KOL → auto-suggest trust scores, feed earliness originator weighting.
- **Cross-KOL trade theses:** synthesize multiple recommendations into combined reviewable theses.
- **RAG over historical corpus; MCP exposure; provider-agnostic tweet source; equities intraday price upgrade; Telegram two-way with signed action tokens.**
