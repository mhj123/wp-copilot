# Work Copilot Wiretap (v2)

Tracks trusted investment KOLs on X, detects **new** actionable calls, measures how early
you are to each idea, formulates conditional trade plans for approval, and helps discover
the next KOLs to tap. **All AI output is a proposal — a human accepts, edits, or dismisses.
Nothing is ever published, traded, or acted on automatically.**

See `PRD.md` for the full spec.

## Setup

1. Activate the plugin (creates tables, seeds the ticker registry, schedules cron).
2. Wiretap → Settings: add your **X API bearer token**, **Anthropic key** (or leave blank
   to inherit Work Copilot core's), and optionally **Telegram bot token + chat id**.
3. Wiretap → KOLs: add handles or import an X List.

## System cron (required)

WP-Cron is traffic-triggered and unreliable on a low-traffic site. Disable it and drive
it from a real crontab:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```cron
*/5 * * * * curl -s 'https://YOUR-SITE/wp-cron.php?doing_wp_cron' > /dev/null 2>&1
```

Jobs: fetch (2×/day), analyze (chained, chunked), rollup (nightly), digest (daily 07:00),
price watcher (15 min, armed plans only). Each run is recorded on the **Runs & Budget** tab.

## X API budget

Pay-per-use reads are metered monthly against `monthly_read_cap` (default $150-equivalent).
Fetch stops at the cap; on-demand discovery actions show their estimated cost and require
confirmation. Verify the current per-read price and set it in Settings.

**Full-archive search** (earliest-callers discovery) requires a paid X API tier; on a 403
the UI falls back to the earliest caller *within your ingested corpus*.

## Guardrails (grep `GUARDRAIL` to verify)

1. AI output only ever lands in proposal states (`wcp_pending` recs, draft digests, `wcp_proposed` plans).
2. Cron only ingests and proposes; only humans accept/arm/publish via REST.
3. **No trading** — no exchange/brokerage/wallet API, no order routing, anywhere. Triggered = notified.
4. Telegram is one-way; no inline actions.
5. Every LLM call logged with prompt + input/output snapshots; human edits logged with diffs.
6. Dismissals retained and used for suppression.
7. All generated market content carries a not-financial-advice disclaimer.
8. Secrets live in options only and are never logged.

## Price sources

- Crypto: CoinGecko free tier (respect rate limits; 15-min cache).
- Equities: Stooq free CSV (EOD/delayed — the UI notes this caveat).
