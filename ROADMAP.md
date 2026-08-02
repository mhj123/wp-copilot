# Roadmap

Design notes for planned work that hasn't been built yet. Each entry captures what was decided so implementation can pick it up later without re-deriving it. Not a replacement for `prd.md` — that stays the stable source of truth for what's in scope; this is scratch/working notes for what's next.

## Sections: Phase 2 — page templates define Sections

**Status**: not started. Phase 1 (manual "duplicate section" action) shipped — see `wp-copilot/includes/class-section-manager.php`.

The existing page-template system (`_wcp_page_template` postmeta on a parent Page, applied via `WCP_Page_Template_Manager::apply_template()`) already conflates "Heading + Items" per `headings[]` entry — it just isn't labeled a Section and only stores `{title, placeholder, items: [{title}]}` (no per-item type/priority/tags, no provenance).

Planned approach:
- Enrich each `headings[]` entry's item definitions to match the fuller item shape `WCP_Section_Manager` already produces (item_type, priority, tags, etc.), not just a bare title.
- Have `apply_template()`'s `create_heading_items()` delegate to a new `WCP_Section_Manager::create_section_from_definition()` method instead of keeping its own separate, smaller item-creation code — one builder, multiple callers (manual duplicate, template-apply-on-page-create, and the Phase 3 scheduled recreate).
- Add a provenance meta on the created `wcp_heading` post (e.g. `_wcp_template_section_id`) linking it back to which section-definition in the parent's template produced it. Nothing today records this, and Phase 3 needs it to know "which section template should I re-run."

## Sections: Phase 3 — cron-recreated sections (recurring, e.g. weekly)

**Status**: not started, and the hardest of the three phases — has open product questions (below) that need answering before implementation starts.

`WCP_Page_Scheduler` already implements the right shape for this: a global 15-minute WP-Cron ticker (`wcp_quarter_hourly` custom interval) plus per-entity `_wcp_schedule_next_run` postmeta, currently used to create whole new child Pages on a schedule.

Planned approach:
- Add a parallel due-check (e.g. `_wcp_section_schedule_next_run` postmeta per page) storing a `{frequency, day_of_week, hour, minute}` config per section-definition from the Phase 2 template.
- When due, call `WCP_Section_Manager`'s section-from-definition builder against the *existing* page (not a new child page), then advance the next-run cursor.
- Reuse `WCP_Page_Scheduler::calculate_next_run()`/`advance_next_run()` date math — worth extracting into a shared stateless helper both page-level and section-level schedules call, rather than duplicating it.

**Open questions to resolve with the user before building this:**
- Does a new recurring section get added alongside all previous cycles' sections indefinitely, or does an old one get archived/collapsed automatically?
- Where in the template-editing UI does a user configure "this section recurs weekly"?
