# Product Requirements Document
## WP Copilot — WordPress Knowledge & Work System

---

## 1. Purpose & Vision

WP Copilot is a **single-user, self-hosted WordPress application** for managing daily work and thinking.

It combines:
- atomic notes (native WP posts),
- structured context (native WP pages),
- hierarchical semantic taxonomy,
- and AI-assisted sensemaking and coaching.

AI augments human thinking but never acts autonomously.

---

## 2. Design Principles

1. Native WordPress first
2. Atomic-by-intent notes
3. Explicit user control over AI
4. Structure doubles as semantics
5. Clear separation of navigation vs internal structure
6. Future extensibility without premature complexity

---

## 3. Core Content Model

### 3.1 Pages (native `page`)
Pages represent **contextual entities**, such as:
- Projects
- Themes
- Meetings
- People

Properties:
- Pages may be nested arbitrarily
- Each Page automatically maps to a hierarchical taxonomy term
- Pages may have freeform tags

Pages define:
- navigation
- semantic scope
- AI context boundaries

---

### 3.2 Headings (custom post type: `wcp_heading`)

Headings represent **structural sub-contexts** under Pages.

Rules:
- Must belong to exactly one Page or Heading
- May be nested
- Cannot exist standalone
- Do not appear in primary navigation by default

Each Heading maps to a taxonomy term.

Rationale:
- Allows deep structure without polluting Page tree
- Enables safe LLM-generated scaffolding

---

### 3.3 ItemPosts (native `post`)

ItemPosts are **atomic notes** intended to capture:
- a single idea
- a task
- a learning
- a decision
- an observation

Intentional, not enforced, single-idea granularity.

Properties:
- Can belong to multiple Pages and/or Headings
- Inherit taxonomy from creation context
- Fully reusable across contexts

---

## 4. Taxonomy Model

### 4.1 Structural Taxonomy (`wcp_context`)
- Hierarchical
- Mirrors Page + Heading structure
- One term per Page / Heading
- Used for:
  - filtering
  - aggregation
  - AI scoping
  - future RAG

Each term stores:
- reference type (page | heading)
- reference ID
- optional cached path

---

### 4.2 Classification Taxonomies

| Taxonomy | Values |
|--------|--------|
| `item_type` | task, info, learning |
| `priority` | high, medium, low |
| `pinned` | yes / no |
| `post_tag` | freeform |

All taxonomy terms have archive pages.

---

## 5. Core UX (Release 1)

### 5.1 Homepage
Displays:
- Page tree
- Recent ItemPosts
- Tag listings

Supports:
- Quick creation of ItemPosts

---

### 5.2 Creating ItemPosts

From homepage:
- User writes note
- Selects Pages/Headings
- Selects classification

From Page or Heading:
- Context auto-applied
- Additional contexts optional

---

### 5.3 Viewing Pages

Page view shows:
- ItemPosts linked to the Page
- ItemPosts linked to descendant Headings
- Pinned items first

Filtering:
- item type
- priority
- tags

---

## 6. AI Features (Release 2)

### 6.1 Guardrails

- AI is always explicitly invoked
- AI never mutates data directly
- User must accept or dismiss all AI outputs
- All AI actions are logged

---

### 6.2 AI-Assisted Tagging

When editing an ItemPost, AI may suggest:
- relevant Pages / Headings
- tags
- item type
- priority

Suggestions are opt-in only.

---

### 6.3 Page-Scoped Chat

User can “chat to” a Page.

Default prompts:
- Summarise this page and its items
- Summarise what I added recently
- What are the most important items here?

Context provided:
- Page content
- Heading outline
- Recent + pinned ItemPosts
- Learnings where relevant

---

### 6.4 Preset Coaching & Generation Prompts

Available on Pages and ItemPosts:
- Coach me based on learnings in this context
- Reframe as business owner
- Reframe as product manager
- Generate plan / checklist / document

Outputs are proposed ItemPosts.

---

### 6.5 Acceptance Flow

AI-generated outputs are displayed as candidate ItemPosts.

User may:
- Accept selected
- Dismiss selected
- Dismiss all

Accepted items:
- Are saved as ItemPosts
- Inherit invoking context
- Are marked as AI-generated

---

## 7. AI Auditability

Every AI action records:
- action ID
- timestamp
- model
- prompt
- input context snapshot
- output snapshot
- acceptance/dismissal decisions

Audit data is retained even if outputs are dismissed.

---

## 8. Later Releases (Out of Scope)

- Full version history for posts and AI actions
- LLM-generated Heading structures
- MCP integrations (Asana, Monday, etc.)
- Multi-user collaboration
- Scheduled or autonomous AI
- Learning systems (spaced repetition)
- External corpus diffing
- Page templates defining Sections, and recurring cron-based Section creation — design notes in `ROADMAP.md`

---

## 9. Non-Goals

- No autonomous agents
- No hard note-length enforcement
- No enterprise permission model
- No real-time collaboration in MVP

---

## 10. Success Criteria

- User can model work as a navigable semantic graph
- Notes are reusable and non-duplicative
- AI enhances clarity without loss of control
- System remains explainable and extensible