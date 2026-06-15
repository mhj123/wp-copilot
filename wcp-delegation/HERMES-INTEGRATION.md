# Hermes Agent Integration Guide

How to connect a Hermes agent (or any external agent) to Work Copilot Delegation.

## How the loop works

```
You (item row) ──"Delegate" + brief + files──▶ WordPress
WordPress ──Telegram message──▶ Hermes
Hermes ──GET packet (brief + mission/page context + attachments)──▶ works on task
   │  (optional) POST status=needs_input + question ──▶ you answer in the item row
   │             ◀── Telegram re-notification with your answer; resume
Hermes ──POST artifacts (files) + POST status=completed + report──▶ WordPress
You review report + artifact links on the item. Nothing is auto-applied.
```

## 1. One-time WordPress setup

1. **Create a dedicated agent user**: Users → Add New, role **Author**
   (limits blast radius — don't reuse your admin account).
2. **Application Password**: edit that user's profile → Application Passwords →
   name it `hermes` → copy the generated password. Requires HTTPS.
3. **Telegram bot**: talk to **@BotFather** → `/newbot` → copy the bot token.
   Add the bot to the chat/group Hermes monitors (or DM it), then get the chat
   ID (send a message, then read
   `https://api.telegram.org/bot<TOKEN>/getUpdates` → `chat.id`).
4. In **WP Admin → Work Copilot → Settings → Delegation (Hermes Agent)**:
   enable the toggle, paste bot token + chat ID. Use the
   "Send Test Telegram Message" button to verify.

All examples below use Basic auth:

```
-u hermes:APP_PASSWORD
```

Base URL: `https://your-site.com/wp-json/wcp-delegation/v1`

## 2. Receiving work

**Trigger**: watch the Telegram chat. Each delegation message contains the item
title, the instruction (truncated), a delegation ID like `dlg_xxxxxxxx-…`, and
the packet URL. The Telegram message is only a doorbell — the packet is the
source of truth.

**Polling fallback / recovery** (e.g. after downtime):

```bash
curl -u hermes:APP_PASSWORD \
  "https://your-site.com/wp-json/wcp-delegation/v1/delegations?status=pending"
```

## 3. The work packet

```bash
curl -u hermes:APP_PASSWORD \
  "https://your-site.com/wp-json/wcp-delegation/v1/delegations/dlg_…"
```

```jsonc
{
  "delegation": {
    "id": "dlg_…",
    "status": "pending",
    "instruction": "Compare suppliers for catalog A and produce a recommendation report",
    "created_at": "2026-06-10 09:14:00",
    "updated_at": "2026-06-10 09:14:00",
    "status_message": "",
    "report": "",
    "clarifications": [            // full Q&A thread, oldest first
      { "id": "q_…", "question": "…", "asked_at": "…", "answer": "…", "answered_at": "…" }
    ]
  },
  "item": {
    "id": 841,
    "title": "Supplier comparison for Catalog A",
    "content": "…",                // plain text
    "item_type": "task",
    "priority": "high",
    "due_date": "2026-06-20",
    "subtasks": [ { "id": "st_…", "title": "…", "done": false } ],
    "tags": ["procurement"],
    "contexts": ["Catalog A"]
  },
  "context_pack": {
    "global_mission": "…",         // treat as ground truth about the user's goals
    "page_mission": "…",
    "page_id": 122,
    "formatted_context": "…"       // ready-to-use prompt text: page summary, headings, sibling items
  },
  "attachments": [                  // input files from the user — download with the same Basic auth
    { "id": 9001, "filename": "requirements.pdf", "url": "https://…", "mime": "application/pdf" }
  ],
  "artifacts": [],                  // what you've uploaded so far
  "endpoints": {                    // absolute URLs — never construct your own
    "self": "…/delegations/dlg_…",
    "status": "…/delegations/dlg_…/status",
    "artifacts": "…/delegations/dlg_…/artifacts"
  }
}
```

## 4. Reporting progress and results

**Acknowledge before starting:**

```bash
curl -u hermes:APP_PASSWORD -X POST "$STATUS_URL" \
  -H 'Content-Type: application/json' \
  -d '{"status":"in_progress","message":"Started — drafting the report"}'
```

**Ask, don't guess.** If the brief is under-specified, ambiguous, or conflicts
with the context pack:

```bash
curl -u hermes:APP_PASSWORD -X POST "$STATUS_URL" \
  -H 'Content-Type: application/json' \
  -d '{"status":"needs_input","question":"Should the comparison cover EU suppliers only, or global?"}'
```

Then stop work on this delegation. When the user answers in the item row,
status flips back to `pending` and a Telegram notification arrives containing
the answer — re-fetch the packet (the full Q&A thread is in
`delegation.clarifications`) and resume. Ask as many rounds as needed.

**Deliver artifacts** (files become media attachments on the item; max 5 files
per request, 10 MB each):

```bash
curl -u hermes:APP_PASSWORD -X POST "$ARTIFACTS_URL" \
  -F 'files[]=@supplier-comparison.pdf' \
  -F 'files[]=@data.xlsx'
```

**Finish with a report** — this is what the user reads first; summarise what
you did, decisions made, and what's in each artifact:

```bash
curl -u hermes:APP_PASSWORD -X POST "$STATUS_URL" \
  -H 'Content-Type: application/json' \
  -d '{"status":"completed","report":"Compared 6 suppliers across cost/SLA/region…\nArtifacts: PDF = full comparison, XLSX = raw data."}'
```

Use `"status":"failed"` with an explanatory `message` if the task can't be done.

## 5. Rules and constraints

- **State machine**: `pending → in_progress ↔ needs_input → completed | failed`.
  Don't skip `in_progress` for non-trivial work.
- **Review-only results**: only call the delegation endpoints. Never edit
  posts/pages via other WP routes — results land as attachments + report for
  human review.
- **All communication through the delegation record**, not Telegram replies —
  Telegram is a one-way doorbell; the question/answer endpoints are the channel.
- Report capped at ~20,000 characters; artifact uploads pass WordPress's
  standard MIME validation.
- `404` on a packet fetch means the delegation was removed — drop it.
  `403` means delegation was disabled in settings.

## 6. Suggested Hermes system-prompt snippet

> When you receive a Work Copilot delegation: fetch the packet, treat
> `context_pack` as ground truth about the user's mission and project, and
> `instruction` as the brief. If the brief is ambiguous, post `needs_input`
> with one precise question rather than guessing. Produce complete,
> self-contained artifacts. Upload them, then post `completed` with a report
> covering: what you did, key decisions, and what each artifact contains.

## 7. Context reviews (from the AI assistant)

A second, lighter entry point: from the **AI Assistant** widget on a page, the
user selects context (this page / entire corpus / specific pages), picks the
**Agent review** chip, and sends an instruction. This creates a *review*
delegation (`rev_…`) whose subject is the selected context, not a single item.

The loop is the same doorbell + packet pattern, but **results are returned as
text only** — there are no artifacts and no item. Whatever you post as the
`report` (or `message`) is appended to the user's AI conversation as a message
labelled **Hermes**, which they see next time they open the assistant. This is
review-only: do not call any other WP routes; nothing is written to posts/pages.

```bash
# Poll for pending reviews (or use the Telegram doorbell)
curl -u hermes:APP_PASSWORD \
  "https://your-site.com/wp-json/wcp-delegation/v1/reviews?status=pending"

# Fetch the packet — context_pack.formatted_context is the packed selection
curl -u hermes:APP_PASSWORD \
  "https://your-site.com/wp-json/wcp-delegation/v1/reviews/rev_…"

# Acknowledge, then return your feedback as the report
curl -u hermes:APP_PASSWORD -X POST \
  "https://your-site.com/wp-json/wcp-delegation/v1/reviews/rev_…/status" \
  -H 'Content-Type: application/json' \
  -d '{"status":"in_progress","message":"Reviewing…"}'

curl -u hermes:APP_PASSWORD -X POST \
  "https://your-site.com/wp-json/wcp-delegation/v1/reviews/rev_…/status" \
  -H 'Content-Type: application/json' \
  -d '{"status":"completed","report":"Feedback: …"}'
```

Review packet shape:

```jsonc
{
  "review": { "id": "rev_…", "status": "pending", "instruction": "…",
              "created_at": "…", "updated_at": "…", "status_message": "", "report": "" },
  "context_pack": {
    "global_mission": "…",
    "page_mission": "…",
    "page_id": 122,
    "context_mode": "page",          // page | corpus | select
    "selected_pages": [],            // populated for context_mode = select
    "formatted_context": "…"         // ready-to-use prompt text for the selection
  },
  "endpoints": { "self": "…/reviews/rev_…", "status": "…/reviews/rev_…/status" }
}
```

Review state machine: `pending → in_progress → completed | failed`
(no `needs_input` / artifacts / answer loop — that's item delegations only).

## Endpoint reference

| Method | Path | Caller | Purpose |
| --- | --- | --- | --- |
| `POST` | `/items/<item_id>/delegate` | user (theme UI) | Create delegation (multipart: `instruction`, `files[]`) |
| `GET` | `/delegations?status=<s>` | agent | List delegations (polling fallback) |
| `GET` | `/delegations/<id>` | agent | Full work packet |
| `POST` | `/delegations/<id>/status` | agent | `status` (+ `message`, `report`; `question` required for `needs_input`) |
| `POST` | `/delegations/<id>/artifacts` | agent | Upload result files (multipart `files[]`) |
| `POST` | `/delegations/<id>/answer` | user (theme UI) | Answer a clarification question (`question_id`, `answer`) |
| `POST` | `/reviews` | user (theme UI) | Create a context review (`conversation_id`, `page_id`, `context_mode`, `selected_pages`, `instruction`) |
| `GET` | `/reviews?status=<s>` | agent | List reviews (polling fallback) |
| `GET` | `/reviews/<id>` | agent | Full review packet |
| `POST` | `/reviews/<id>/status` | agent | `status` (+ `message`, `report`); report is appended to the user's AI chat |
