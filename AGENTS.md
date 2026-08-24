# Codex Instructions — WP Copilot (WordPress Plugin)

You are building a WordPress.org plugin named **work-copilot**.

This plugin implements a personal knowledge + work management system using:
- native WordPress Posts (atomic notes)
- native WordPress Pages (structure / context)
- a custom Heading post type (structural sub-context)
- hierarchical taxonomy derived from Pages + Headings
- human-in-the-loop AI features

## Core Principles (Non-Negotiable)

1. **Prefer native WordPress constructs**
   - Use native `post` for ItemPosts
   - Use native `page` for structure
   - Minimise custom post types and tables
2. **Human-in-the-loop AI**
   - AI NEVER writes directly to the database
   - All AI outputs are proposals
   - User must explicitly accept or dismiss
3. **Atomic notes by intent**
   - Encourage small, single-idea ItemPosts
   - Do NOT enforce hard limits on length
4. **Structure = taxonomy**
   - Page and Heading structure MUST be mirrored into a hierarchical taxonomy
5. **Single-user first**
   - No multi-user permissions in MVP
   - Architect for later extension, but do not pre-empt it

## What NOT to do

- Do NOT invent new abstractions unless strictly required
- Do NOT introduce background agents, cron-based AI, or autonomous behavior
- Do NOT auto-save AI-generated content
- Do NOT collapse Pages and Headings into a single ambiguous concept
- Do NOT optimise prematurely for enterprise scale

## Implementation Expectations

- Implement this as a WordPress plugin
- Use REST endpoints for all non-trivial interactions
- Keep logic modular and readable
- Prefer clarity over cleverness
- Comment code where AI-related guardrails exist

## AI-Specific Expectations

- AI calls must:
  - receive a bounded context pack
  - return strict JSON when generating structured output
- AI actions must be logged with:
  - prompt
  - input snapshot
  - output snapshot
  - acceptance/dismissal decisions

You should assume:
- The site is a personal WordPress install
- The user is technical and values transparency
- The product will evolve toward RAG and MCP later

Follow the PRD exactly.
Do not extend scope unless explicitly instructed.