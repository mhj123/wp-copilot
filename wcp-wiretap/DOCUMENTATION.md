# Work Copilot Wiretap — User Documentation

*As-built documentation (v2.0.0). This describes what actually shipped, including
extensions beyond the PRD — anything beyond spec is marked **[extra]**.*

---

## 1. What it is

Wiretap watches a curated set of trusted investment accounts ("KOLs") on X, and turns
their posting into a reviewable work queue:

- **Detects new actionable calls** — not every ticker mention, but genuinely *new*
  recommendations by a KOL on an asset, distinguished from repeats, passing mentions,
  and hindsight ("told you so") posts.
- **Tells you how early you are** to each idea, relative to your own tracked corpus and
  to the price move.
- **Proposes conditional trade plans** ("watch for a buy in the 40s") and watches prices
  so you get pinged when a plan's zone is hit.
- **Writes a daily digest** of what your list talked about.
- **Suggests the next KOLs to track**, from interaction patterns, follow-graph overlap,
  and who called a ticker earliest.

**The contract: AI proposes, you dispose.** Every AI output lands in a proposal state
(pending recommendation, draft digest, proposed plan). Nothing is ever published,
traded, or acted on automatically. There is no brokerage/exchange/wallet code anywhere
— "triggered" always means *notified*, never *executed*. Telegram is one-way. Every
LLM call is logged with its full input/output; your edits are logged as diffs.

## 2. How the pipeline works

```
        2×/day                     chunks of 10                nightly
KOLs ──[fetch]──▶ tweets table ──[prefilter+LLM]──▶ signals ──[rollup]──▶ daily stats
                                        │                                    │
                                        ▼                                    ▼
                            newness check (code, not LLM)              emerging panel
                                        │                              daily digest
                          ┌─────────────┴─────────────┐
                          ▼                           ▼
                    NEW call → pending rec      repeat → reinforcement
                          │                     (counter on existing rec,
                          ▼                      no new inbox item)
                 alert if conf ≥ 0.7 and
                 KOL trust ≥ 4 → Telegram
```

Key mechanics worth understanding:

- **Prefilter first, LLM second.** Tweets with no cashtag, no known ticker name, and no
  trading-lexicon word are skipped without an AI call. Everything else goes to the
  classifier *with its thread and quoted-tweet context* (single tweets out of context
  misclassify).
- **Newness is deterministic.** A call is NEW iff it's the KOL's first on that ticker,
  OR the direction flipped vs their last call, OR their last same-direction call is
  older than 30 days (setting: *new-call window*). Anything else increments a
  "reinforced ×N" counter on the existing recommendation — reinforcement is signal
  (it feeds the digest and earliness) but it never re-clutters your inbox.
- **Price-at-call is captured the moment a rec is created** (it can't be backfilled),
  which is what powers the since-call % chips and, later, KOL scorecards.
- **Dismissed recs are kept.** They suppress re-surfacing (repeats reinforce the
  dismissed rec quietly) and preserve the audit trail.

## 3. The earliness meter

Every recommendation, digest ticker row and emerging entry carries a band + a facts
sentence. **The band is a heuristic headline; the facts are the argument — read the facts.**

| Band | Meaning |
|---|---|
| Too early | Little tracked attention, price flat — risk is dead money or wrong thesis |
| On time | Attention accelerating, price confirmation just starting |
| Crowded | Consensus forming or price already extended 1.5–3× |
| Late | Saturated (most of your list on it), >3× extended, or attention fading after a peak |
| Quiet mover | Price moved without your KOLs — treat as late, investigate what you're missing |
| Mixed | No clear read — facts only |
| Insufficient data | Fewer than 5 mentions in 30d — any band would be noise |

Inputs: diffusion (share of your active KOLs mentioning it in 30d), velocity
(trust-weighted mentions this week vs prior week), price extension (now vs price at the
*first tracked call*), and originator share (if mostly tier-1 KOLs are talking, the band
shifts one notch earlier; if mostly echo accounts, one notch later). All thresholds are
editable in Settings. Known failure modes are in the hover tooltip: memecoins can stay
"crowded" and keep working; the corpus is blind to anything before you started tracking
(the earliest-caller search partially repairs this by backfilling the first-call anchor).

## 4. Screen-by-screen guide

### Inbox (Wiretap → Inbox)
Pending recommendations, newest first. Each card shows: ticker, direction, KOL (+trust
stars), model confidence, **since-call %** [extra], earliness band, the rationale
excerpt with a link to the source thread, and a **"why am I seeing this"** line [extra]
— the newness reason, reinforcement count, and any flags (low confidence, unverified
ticker). Prior calls by the same KOL on the ticker are listed inline (queried live).

Actions per card:
- **Accept / Dismiss** — dismissing offers one-click reason tags (*noise / too late /
  don't trust*) [extra]; these are stored for future trust-score suggestions.
- **Edit** — fix direction/ticker/confidence. Edits are diff-logged. Confirming an
  unverified ticker via Edit adds it to the registry permanently.
- **Create plan** — runs trade-plan extraction on the rec (see below).
- **Check in** — see §5.
- **🔇 Mute** [extra] — silences alerts for that ticker for 7 days. Ingestion and
  analysis continue; only pushes stop.

An alerts strip at the top shows high-confidence calls and plan triggers since your
last visit.

### Trade Plans
Three columns — Proposed / Armed / Triggered — plus a "recently finished" table.

Lifecycle: the extractor proposes entry zone, invalidation, targets, timeframe and
thesis from the KOL's own words ("bottom in the 40s" → zone 40–49). If the KOL gave no
levels, the plan says so and you must type levels before arming. **Only you can arm a
plan.** Armed plans are price-checked every 15 minutes; a hit inside the entry zone
flips it to Triggered and pings Telegram; a numeric invalidation breach flips it to
Invalidated; 30 days without action (configurable TTL) expires it. You close plans
manually — the plugin never knows or cares whether you actually traded.

Prices: CoinGecko for crypto (near-real-time, free tier), Stooq for equities
(**end-of-day/delayed** — don't arm tight intraday equity zones expecting precision).

### KOLs
- **Add by @handle** (resolves the X user id on save) or **import an X List ID**.
- **Trust 1–5, set by you.** Trust ≥ 4 = tier-1 "originator": gates Telegram alerts,
  weights the digest and earliness, and unlocks the *Expand graph* button.
- **Suggestion queue** — populated three ways: the free nightly corpus scan (accounts
  your KOLs repeatedly quote/reply to/RT), *Expand graph* (fetches a tier-1 KOL's
  following list and triangulates against the other tier-1s' cached lists, with an LLM
  topical-fit score), and *Find earliest callers* (full-archive cashtag search ranked
  by first-mention date vs price then/now). The two on-demand ones show their estimated
  X-API cost and ask for confirmation first; archive search needs a paid X tier and
  otherwise falls back to the earliest caller within your own corpus.
- Promoting a suggestion to Tracked is the only thing that starts spending polling
  budget on it. Dismissed suggestions won't be re-suggested. Suspended/renamed/protected
  accounts auto-pause with a reason shown.

### Digest
Daily at 07:00 (site time) and on-demand for any trailing window (1–168h). Built in two
stages: code pre-aggregates the window (mention counts, stance mix, new vs reinforced,
earliness per ticker), then one LLM call writes the markdown: *Market pulse / New calls
/ Repeat calls / Tickers & theses / Themes to watch*. Saved as a **draft post** tagged
`wcp-wiretap-digest` with a not-financial-advice disclaimer — you publish or not.
Optional Telegram morning link [extra].

### Emerging
Tickers and themes crossing the emerging rule (≥3 distinct KOLs in 7d, ≥2× the prior
week's mentions, first seen within 45d — older ones are labelled *resurgent*), ranked
by velocity × distinct KOLs × trust, with 14-day sparklines and live earliness bands.
Populated by the nightly rollup. Optional Telegram notify (off by default).

### Runs & Budget
- **X reads meter**: every tweet object fetched counts as one pay-per-use read; the bar
  shows this month's spend vs your cap. **At the cap, polling stops** (nothing else
  breaks) until the month rolls over or you raise it.
- Anthropic token totals for the month, rate-limit backoff status, run history for
  every job (counts, errors, reads), and **Run now** buttons for fetch / analyze /
  rollup / price-check.

### Settings
Credentials (X bearer token, Anthropic key — blank inherits Work Copilot core's,
Telegram bot + chat id, each with a Test button), notification toggles, ingestion knobs
(retweets, lookback, retention, chunk size), signal thresholds (new-call window, alert
confidence, review floor, trust gate), the prefilter **lexicon** (one term per line),
all earliness thresholds, emerging/discovery/plan/price/digest knobs, and a **ticker
registry JSON import** [extra] for bulk-extending the ~250 seeded symbols.

## 5. Check-ins

On any rec or plan, **Check in** builds a bounded pack — the original thread, everything
the same KOL has said about the ticker since (gone quiet? doubled down? flipped?), other
tracked KOLs' takes, the price series vs your levels, and the current earliness — and
returns a memo: thesis intact/strengthened/weakened/invalidated, key developments, the
KOL's stance change, and a suggested next look (hold / revisit / tighten invalidation /
exit-watch). Memos are stored on the object and shown on the card. **They are advisory
text only — a check-in never changes any status.**

## 6. Extensions beyond the PRD (summary)

| Extension | Why |
|---|---|
| Strict-JSON LLM gateway (`class-llm.php`) | One choke point enforcing schema validation + audit logging + token metering for every AI call |
| Since-call % chips | Price-at-call was already captured; this is the seed of future KOL scorecards |
| "Why am I seeing this" line | No black-box alerts — newness reason + flags always visible |
| Dismissal reason tags | Future fuel for auto-suggested trust scores |
| Per-ticker alert mute (7d) | Relief valve for noisy days; ingestion unaffected |
| Telegram digest ping (opt-in) | Morning link to the draft |
| Ticker JSON import + `class-kols.php` + `signals_json` column | Practical plumbing the spec implied but didn't enumerate |

Deliberately **not** built (still on the ideas list): cross-KOL cluster cards, weekly
retro digest, KOL PnL scorecards, paper portfolio, two-way Telegram.

## 7. Operational notes

- **Cron**: set `define('DISABLE_WP_CRON', true);` and a 5-minute system crontab hitting
  `wp-cron.php` (see README). Running locally (MAMP): jobs only fire while the Mac is
  awake — missed runs catch up on wake, but the price watcher can miss a zone touch
  that happens while asleep.
- **Ticker symbol collisions** (e.g. DASH the coin vs DoorDash): the registry holds one
  entry per symbol, crypto-first for the seeds. The classifier resolves from context,
  and anything ambiguous arrives flagged *unverified* for you to confirm via Edit.
- **Data**: four custom tables (`wcp_wiretap_tweets`, `_daily_stats`, `_prices`,
  `_ai_log`), three CPTs (`wcp_kol`, `wcp_recommendation`, `wcp_trade_plan`) with custom
  post statuses as the single source of state. Raw tweets are pruned after 90 days
  except any referenced by a rec or plan.
- **Verifying the guardrails**: `grep -rn "GUARDRAIL" wcp-wiretap/` — every §12 rule is
  tagged at the exact line that enforces it.
