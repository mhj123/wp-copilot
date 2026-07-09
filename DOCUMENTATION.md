# Work Copilot — Documentation

## What It Is

Work Copilot is a personal knowledge and work management system built as a WordPress plugin. It treats WordPress as a structured knowledge base: native Pages provide hierarchy, native Posts become atomic notes ("items"), and a custom heading type creates sub-structure within pages. An AI layer sits on top, offering assistance at every level — but never writing to the database without explicit human approval.

It is designed for a single technical user who wants to capture, organise, and act on knowledge and tasks without leaving their own infrastructure.

**Version:** 1.2.2  
**Plugin slug:** `work-copilot`

---

## Why It Exists

Most productivity tools force you into their data model and their AI behaviour. Work Copilot is different:

- **Your data, your WordPress** — everything lives in standard WordPress tables. No proprietary database, no cloud lock-in.
- **Structure you define** — pages and headings create the hierarchy; items attach to that hierarchy via taxonomy.
- **AI as assistant, not agent** — AI proposes, you decide. Nothing is written automatically.
- **Single-user first** — no collaboration overhead, no permission complexity in the initial version.

---

## Core Concepts

### Items
An **item** is a native WordPress post (`post_type = post`). Items are atomic — one idea, task, observation, or learning per item. Each item has:

- A title and optional content
- An **item type**: `task`, `info`, `learning`, or `spec`
- A **priority**: `critical`, `high`, `medium`, or `low`
- A **task status** (tasks only): `to-do`, `in-progress`, `done`
- A **spec status** (specs only): `draft`, `review`, `final`
- **Tags**: freeform via native WordPress `post_tag`
- **Contexts**: one or more pages/headings the item belongs to
- **Subtasks**: a JSON list stored in post meta, each with a title and done flag
- **Nested sub-items**: child posts via WordPress `post_parent`, displayed as an indented outline
- A **due date** and optional **source URL**
- A **pinned** flag to float it to the top of its page
- A **creator mark**: `manual`, `copilot` (AI-generated), or `hermes` (agent-generated)

### Pages
A **page** is a native WordPress page. Pages represent contexts — projects, themes, people, meetings, areas of focus. They can be nested (parent/child) to create a tree. Each page automatically gets a corresponding `wcp_context` taxonomy term, which is how items are attached to it.

### Headings
A **heading** is a custom post type (`wcp_heading`). Headings are structural sub-sections within a page or another heading. They appear as sections in the page view, grouping the items beneath them. Like pages, headings auto-sync to `wcp_context`.

### Contexts
The `wcp_context` taxonomy mirrors the page/heading tree. Items are assigned contexts (taxonomy terms) rather than being children of a post, which allows an item to belong to multiple pages simultaneously. The taxonomy sync runs automatically on save.

---

## Data Model

### WordPress Tables Used

| Construct | Used For |
|-----------|----------|
| `wp_posts` (post) | Items |
| `wp_posts` (page) | Pages |
| `wp_posts` (wcp_heading) | Headings |
| `wp_postmeta` | Subtasks, due dates, creator marks, page missions, source URLs, parent references |
| `wp_terms` / `wp_term_taxonomy` | wcp_context, item_type, priority, task_status, spec_status, pinned, post_tag |

### Custom Tables

| Table | Purpose |
|-------|---------|
| `wp_wcp_ai_actions` | Audit log of every AI call — prompt, input, output, accept/dismiss decisions |
| `wp_wcp_embeddings` | Vector embeddings for semantic search (OpenAI, 1536 dimensions) |
| `wp_wcp_ai_conversations` | Persistent per-page AI conversation sessions |
| `wp_wcp_ai_messages` | Individual messages within conversations |
| `wp_wcp_edges` | Semantic graph triples (subject → predicate → object) for the graph plugin |

---

## Features

### Item Management

Items are created via:
- The **quick-add form** at the top of every page
- **Voice recording** (hold the backtick key, speak, release)
- **CSV import**
- **Raindrop.io import** (bookmarks become items)
- **AI generation** (proposed, not auto-saved)

On every item row you can:
- Edit the title inline
- Set type, priority, status, and due date via inline dropdowns
- Pin the item to the top of its page
- Add or remove tags
- Add or remove context associations
- Add subtasks (checked list, stored in meta)
- View the full item (single post view)
- Delete the item
- Access AI actions via the **[ai]** button
- Drag to reorder within its context
- Drag horizontally to indent (make a child) or outdent (promote to parent)

### Nested Sub-items (Outliner)

Items can be nested to arbitrary depth using WordPress `post_parent`. Child items appear indented beneath their parent, with a visual left-border indicator. Keyboard shortcuts while editing a title:
- **Ctrl+]** — indent item under the previous sibling
- **Ctrl+[** — outdent item back to parent level

### Pages & Structure

Each page shows:
- Breadcrumb trail (for nested pages)
- Page description / content
- Pinned items at the top
- Quick-add form
- Items scoped to the page (excluding those scoped only to a heading)
- Heading sections, each with their own items and quick-add form
- Dynamic listing sections (saved filter queries)
- The floating AI assistant widget

### Search

- **Keyword search**: `?s=` is intercepted by `search.php` and shows matching items only (not pages or admin content).
- **Semantic search**: powered by OpenAI embeddings. Used internally by the AI to find relevant context; also available via the REST API.

---

## AI Features

### Guardrail Model

The AI never writes to the database. Every output is a proposal. The user must explicitly click "Accept" or dismiss. All AI interactions are logged to `wp_wcp_ai_actions` with the full prompt, input snapshot, output snapshot, and the user's decision.

### Configuration

In **Settings → AI**:
- Enable/disable AI globally
- Anthropic API key
- Default model (Haiku, Sonnet, Opus)
- Global mission (a brief describing what the user is working on overall)
- Global instructions (behavioural constraints applied to every prompt)

### Model & Thinking Selection

The AI widget includes per-session overrides:
- **Model**: Haiku 4.5 (fast), Sonnet 4.6 (balanced, default), Opus 4.8 (most capable)
- **Thinking budget** (Opus only): off / low (1k tokens) / medium (5k) / high (10k)

These override the site default for that session without changing settings.

### Page-Level AI Widget

The floating assistant on every page offers:

| Action | What It Does |
|--------|-------------|
| **Onboard** | Greets you, summarises the page, suggests what to do |
| **Generate structure** | Proposes headings + placed items for the page |
| **Create sub-pages** | Suggests child page titles to create |
| **Create goal** | Turns your description into a goal with a heading and action items |
| **Edit page** | Rewrites the page's content block |
| **Append to page** | Adds new content to the end of the page |
| **Fetch posts** | Generates new items from a prompt |
| **Fetch structure** | Queries and returns existing structure information |
| **Agent review** | Sends the current context to the Hermes delegation agent |
| **Chat** (default) | Free-form Q&A about the page and its items |

Context can be scoped to the current page, the entire corpus (RAG), or manually selected pages.

Conversations are persistent — the last 10 turns are included in each request, enabling multi-turn planning and coaching dialogues.

### Per-Item AI Actions

Click **[ai]** on any item row to access:

| Action | What It Does |
|--------|-------------|
| **Action plan** | Breaks the item into 4–7 ordered steps |
| **Action plan from context** | Same, but first searches the RAG index for relevant pages (e.g. SOPs, reference docs) and uses them to ground the plan |
| **Improve phrasing** | Rewrites the title and content to be clearer and more concise |
| **Freeform** | Enter any instruction; AI applies it to the item |
| **Add subtasks** | Proposes 3–6 concrete subtasks |
| **Auto-associate** | Suggests which pages/headings the item should belong to |
| **Convert to goal** | Pre-fills the goal creation modal with this item's text |
| **Delegate** | Hands the item to the Hermes agent with an instruction |

For **action plan** and **action plan from context**, steps can be accepted as:
- **Subtasks** — added to the item's checklist
- **Nested items** — created as full child items under the parent, inheriting its contexts and tags

#### Action Plan from Context — How It Works

1. Click "Action plan from context" on an item
2. The system builds a query from the item's title and tags, then searches the RAG index for relevant pages
3. Matching pages are shown as a checklist (similarity % shown, pre-checked)
4. Deselect any that aren't relevant, then click "Generate plan"
5. The AI generates steps grounded in those pages' content
6. Accept as subtasks or nested items

This works best when you have pages containing process documentation, SOPs, checklists, or reference material that's been saved and embedded.

### Memories

After a coaching conversation, you can extract key learnings. The AI identifies 1–3 memorable insights from the conversation and proposes them as items to save to a designated "Memories" page.

---

## Voice Recording

**Plugin:** `wp-copilot-voice`

Hold the **backtick/grave key (`)** to record. Release to stop. The browser's Web Speech API transcribes the speech and the item is created in the current page's context. A floating indicator shows recording / processing / done status.

Works on HTTPS only (browser requirement for microphone access). Requires the voice plugin to be active and the current page to have a `wcp_context` term.

---

## CSV Import & Export

### Export

From **WP Admin → Work Copilot → CSV Import/Export**, export creates a structured CSV with columns:

`row_type | id | page | subpage | heading | context_path | title | item_type | status | priority | pinned | content | tags | due_date | source_url | subtasks | menu_order`

Pages and headings are exported in hierarchy order (parents before children).

### Import

**Mandatory columns:** `row_type` and `title`.  
**Optional:** everything else.

Two-phase process:
1. **Preview** — dry-run that shows what will be created, updated, or skipped, plus warnings (missing parents, missing fields). No writes.
2. **Commit** — creates/updates only after you confirm the preview.

Page and heading content is never overwritten on update. Items are created fresh. The importer reconstructs hierarchy from `context_path` (e.g. `Projects > Website Redesign > Tasks`).

---

## Raindrop.io Import

Configure in **Settings → Raindrop**:
- Paste your Raindrop API key
- Choose which collections to import
- Set import frequency (disabled / hourly / twice daily / daily)

Bookmarks become items. Each Raindrop collection becomes a child page under a "Bookmarks" parent page. Import is incremental — only new bookmarks since the last run are fetched. Use "Import Now" to trigger immediately, or "Reset Cursor" to reimport everything.

---

## Semantic Search & RAG

Requires an OpenAI API key. Enable in **Settings → Semantic Search**.

When enabled:
- Embeddings are generated automatically when items, pages, and headings are saved
- The AI widget's "Entire Corpus (RAG)" context mode retrieves the most semantically relevant content to include in the AI's context window
- "Action plan from context" uses the same index to find relevant reference pages
- The `/search/semantic` REST endpoint is available for direct queries

Embeddings use OpenAI's `text-embedding-3-small` model (1536 dimensions). The batch generation tool in the admin dashboard lets you generate embeddings for existing content.

---

## Delegation (Hermes Agent)

**Plugin:** `wcp-delegation`

Items can be delegated to an external agent (Hermes) for autonomous execution. The agent polls the REST API for work packets, processes them, and posts results back.

From the item **[ai]** panel, click **Delegate**, write an instruction, optionally attach files, and submit. The item is marked "delegated". The agent can:
- Post status updates
- Ask clarification questions (you answer in the item's panel)
- Upload artifact files
- Post a completion report

The agent **cannot** modify the item's title or content directly. All delegation activity is confined to meta and attachments. Items created by the agent are marked with a "hermes" creator badge.

---

## Context Graph

**Plugin:** `wcp-graph`

Creates semantic relationships between entities using a subject → predicate → object triple store. Examples:

- *Website Redesign* **depends-on** *Design System*
- *Michael* **fulfils** *Engineering Lead*
- *Q3 Sprint* **contains** *Landing Page Redesign*

Connections appear as chips on page/item views. Clicking a chip navigates to the connected entity. "Semantic tables" let you view and edit relationships in a column/row layout where columns are predicates and rows are entities.

---

## Settings Reference

| Setting | Key | Default |
|---------|-----|---------|
| Enable AI | `wcp_ai_enabled` | false |
| Anthropic API key | `wcp_anthropic_api_key` | — |
| Default Claude model | `wcp_ai_model` | claude-sonnet-4-6 |
| Global AI mission | `wcp_ai_global_mission` | — |
| Global AI instructions | `wcp_ai_global_instructions` | — |
| Enable embeddings | `wcp_embeddings_enabled` | false |
| OpenAI API key | `wcp_openai_api_key` | — |
| Raindrop API key | `wcp_raindrop_api_key` | — |
| Raindrop import frequency | `wcp_raindrop_import_frequency` | disabled |
| Raindrop collections | `wcp_raindrop_selected_collections` | [] |
| Saved prompt chips | `wcp_saved_prompts` | [] |

---

## REST API

Base namespace: `work-copilot/v1`

All endpoints require WordPress authentication (nonce via `X-WP-Nonce` header).

### Items

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/items/create` | POST | Create an item (accepts `post_parent` for nesting) |
| `/items/{id}/update` | POST | Update title, content, taxonomies, due date, etc. |
| `/items/{id}/delete` | POST | Trash an item |
| `/items/reorder` | POST | Reorder items within a context |
| `/items/{id}/ai` | POST | Run an AI action on a specific item |
| `/items/{id}/subtasks` | POST | Add a subtask |
| `/items/{id}/subtasks/{sid}/toggle` | POST | Toggle subtask completion |
| `/items/{id}/subtasks/{sid}` | DELETE | Remove a subtask |

### Structure

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/contexts/tree` | GET | Full hierarchical context tree |
| `/contexts/{id}/items` | GET | Items in a context |
| `/headings/create` | POST | Create a heading |
| `/headings/{id}/update` | POST | Rename a heading |
| `/headings/{id}/delete` | POST | Delete a heading |
| `/headings/reorder` | POST | Reorder headings on a page |
| `/pages/list` | GET | Flat list of pages |
| `/pages/create` | POST | Create a child page |
| `/pages/{id}/notes` | POST | Save page notes |
| `/pages/{id}/mission/append` | POST | Append text to page mission |

### AI

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/ai/actions/execute` | POST | Execute a page-level AI action |
| `/ai/proposals/decide` | POST | Accept or dismiss AI proposals |
| `/ai/conversations/init` | POST | Start a new conversation |
| `/ai/goals/plan` | POST | Plan a goal (step 1: understand) |
| `/goals/create` | POST | Finalise goal creation (step 2) |
| `/ai/memories/extract` | POST | Extract learnings from a conversation |
| `/ai/editor/expand` | POST | Expand a draft in the editor |
| `/dashboard/activity-summary` | POST | Weekly activity summary |
| `/mission/active` | GET | Active mission for a page |

### Search & Embeddings

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/search/semantic` | POST | Semantic similarity search |
| `/embeddings/batch` | POST | Batch-generate embeddings |
| `/embeddings/stats` | GET | Coverage statistics |
| `/embeddings/generate/{id}` | POST | Generate embedding for one post |

### Misc

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/version` | GET | Plugin version |
| `/prompts` | GET / POST | Get or save custom prompt chips |
| `/prompts/{index}` | DELETE | Delete a saved prompt |
| `/taxonomy/sync-all` | POST | Bulk sync page/heading → context taxonomy |

Additional namespaces: `wcp-delegation/v1` (agent work packets), `wcp-graph/v1` (graph edges).

---

## Plugin Architecture

```
/wp-copilot/                    Main plugin
  work-copilot.php              Entry point, activation, table creation
  includes/
    class-rest-api.php          All ~50 REST endpoints
    class-ai-client.php         Claude API communication
    class-ai-actions.php        High-level AI action orchestration
    class-ai-logger.php         AI audit logging
    class-context-builder.php   Build hierarchical AI context packs
    class-prompt-builder.php    Multi-layer system/user prompt assembly
    class-conversations-manager.php  Conversation persistence
    class-embeddings-client.php OpenAI embedding generation
    class-embeddings-manager.php     Auto-trigger embeddings on save
    class-memory-manager.php    Extract and store learnings
    class-mission-loader.php    Load mission context per page
    class-taxonomy-sync.php     Sync pages/headings → wcp_context
    class-post-types.php        Register wcp_heading post type
    class-taxonomies.php        Register all taxonomies
    class-raindrop-importer.php Raindrop.io polling and import
    class-csv-importer.php      Two-phase CSV import
    class-csv-exporter.php      CSV export
    class-page-scheduler.php    Cron scheduling
  admin/
    class-admin.php             Admin menus, dashboards, metaboxes
    class-settings.php          Settings page UI and option registration
    class-page-mission-metabox.php   Mission editor on page edit screen

/work-copilot-theme/            Frontend theme
  page.php                      Page template (main view)
  single.php                    Single item view
  search.php                    Search results
  sidebar.php                   Navigation tree
  template-parts/
    item-row.php                Item card (used everywhere)
    ai-widget.php               Floating AI assistant
    quick-add-item.php          Item creation form
    heading-section.php         Heading + its items
    dynamic-listing.php         Saved filter view
  assets/js/
    theme.js                    All frontend interaction and AJAX
    ai-widget.js                AI widget logic
  assets/css/
    theme.css                   Main styles
    ai-widget.css               AI widget styles

/wp-copilot-voice/              Voice plugin
  wp-copilot-voice.php          Script enqueue
  assets/js/voice-record.js     Web Speech API + item creation

/wcp-delegation/                Delegation plugin
  class-delegation-manager.php  Work packet CRUD, notifications
  class-rest-api.php            Delegation endpoints

/wcp-graph/                     Graph plugin
  class-graph-repository.php    Triple store
  class-connections-panel.php   Entity connection UI
  class-semantic-tables.php     Table block rendering
```

---

## Tech Stack

- **WordPress** 5.0+ (native posts, pages, taxonomies, REST API, nonces)
- **PHP** 7.2+
- **MySQL** 5.7+ / MariaDB 10.3+
- **jQuery** (frontend AJAX and DOM)
- **SortableJS** v1.15.2 (drag-and-drop reordering)
- **Web Speech API** (browser-native, voice recording)
- **Anthropic Claude API** (AI generation and chat)
- **OpenAI API** (text embeddings for semantic search)
- **Raindrop.io REST API** (bookmark import)

---

## Human-in-the-Loop Guarantee

This is the single most important architectural constraint:

1. **AI never writes to the database.** Every AI call returns a proposal.
2. **The user explicitly accepts or dismisses.** No auto-save, no background writes.
3. **Everything is logged.** The `wp_wcp_ai_actions` table records every AI call: the model used, the full prompt, the input context snapshot, the output, and what the user decided to do with it.
4. **The delegation agent is bounded.** It can write to meta and attachments, but not to post title or content.

These constraints are enforced at the code level and are non-negotiable by design.
