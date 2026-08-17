=== Work Copilot ===
Contributors: mhj123
Tags: notes, knowledge management, productivity, ai, second brain
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A personal knowledge and work management system built on native WordPress Posts and Pages, with optional human-in-the-loop AI assistance.

== Description ==

Work Copilot turns WordPress into a personal knowledge and work management system, without inventing new data structures where native ones already fit.

**How it works**

* **Items** are atomic notes — a single task, idea, or observation — stored as native WordPress Posts.
* **Pages** represent the big contexts in your work: projects, themes, meetings, people.
* **Headings** (a lightweight custom post type) add sub-structure under a Page.
* Your Page/Heading structure is automatically mirrored into a hierarchical taxonomy, so browsing your structure and filtering your notes are the same system.

**AI assistance, on your terms**

Work Copilot can optionally connect to Claude (Anthropic) to help you draft notes, edit items, summarise a page, or turn a rough brain-dump into structured items. Every AI action follows the same rule:

* AI never writes to your database automatically.
* Every AI suggestion is a **proposal** — you explicitly accept or dismiss it.
* Every AI action (prompt, input, output, and your decision) is written to an audit log you can review at any time under Work Copilot → AI Audit Log.

AI features are entirely optional. The plugin is fully usable as a structured notes system without ever configuring an API key.

**Optional imports**

* Import bookmarks from Raindrop.io as items.
* Import events from an exported .ics calendar file (parsed locally — no external request is made for this feature).

= Who this is for =

This is built for a single user organizing their own notes and work inside their own WordPress install. It is not currently designed for multi-author or team use.

== Requirements & Supported Setup ==

Work Copilot requires a single-user WordPress install where you are the sole user and hold the Administrator role. It is intended for a dedicated, single-purpose site. Multi-user, shared, and collaborative installs are not supported.

This is a firm requirement, not a recommendation. Work Copilot's actions are gated by WordPress's standard content capabilities, which are site-wide rather than plugin-specific. The plugin is designed and tested for a single administrator with full access to their own workspace. Running it on a site with additional user accounts is unsupported and outside its security model — other accounts may be able to reach Work Copilot functions and content in ways the plugin does not attempt to isolate.

If you need Work Copilot on a site that currently has other users, set it up on a separate, dedicated single-user install instead.

== AI and Your Data ==

Work Copilot never acts autonomously. Every AI action is explicitly invoked, all AI-generated output is proposed for your review rather than saved directly, and you accept or dismiss each suggestion. AI actions are logged for auditability.

== External Services ==

Work Copilot connects to third-party APIs **only for the specific features listed below, only if you supply an API key for that feature, and only when you actively trigger the related action.** No content leaves your site if you don't configure a key. No data is sent on a schedule or in the background for these AI features — each call happens synchronously in response to something you clicked.

**1. Anthropic (Claude API)** — used for all AI assistant features (chat, drafting/editing items, page summaries, coaching prompts).

* What is sent: the text you type into the AI assistant, plus a bounded "context pack" the plugin builds from the relevant Page/Heading/Items you're working on (titles and content of your notes) so the model has enough context to respond usefully.
* When: only when you send a message to the AI assistant or trigger an AI action (e.g. "Suggest tags", "Summarise page").
* Service: https://www.anthropic.com — Terms of Service: https://www.anthropic.com/legal/consumer-terms — Privacy Policy: https://www.anthropic.com/legal/privacy

**2. OpenAI (Embeddings API)** — used only if you enable semantic search/RAG features, to generate vector embeddings of your items for similarity search.

* What is sent: the title and content of items you choose to index.
* When: when an item is created/updated while embeddings are enabled, or when you run a manual re-index.
* Service: https://openai.com — Terms of Service: https://openai.com/policies/terms-of-use — Privacy Policy: https://openai.com/policies/privacy-policy

**3. Raindrop.io API** — used only if you enable bookmark import, to fetch your saved bookmarks so they can be imported as items.

* What is sent: your Raindrop.io API token (to authenticate the request); no WordPress content is sent to Raindrop.io, data only flows inbound from Raindrop.io to your site.
* When: on a schedule you configure (e.g. daily), or when you trigger a manual import.
* Service: https://raindrop.io — Terms of Service: https://raindrop.io/terms — Privacy Policy: https://raindrop.io/privacy

You are responsible for reviewing each provider's terms and privacy policy before enabling the corresponding feature, and for ensuring your use complies with any obligations you have regarding the notes/content you store in WordPress.

== Installation ==

1. Upload the `work-copilot` folder to `/wp-content/plugins/`, or install directly from the WordPress plugin directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Work Copilot → Settings to (optionally) add your Anthropic API key to enable AI features. OpenAI and Raindrop.io keys are separate and also optional.
4. Start creating Pages and Items — no configuration is required for the core notes/structure features.

== Frequently Asked Questions ==

= Do I need an API key to use this plugin? =

No. Pages, Headings, Items, and taxonomy browsing all work with zero configuration. API keys are only needed to enable specific AI or import features — see "External Services" above.

= Does the AI ever save anything without my approval? =

No. Every AI-generated item, edit, or suggestion is stored as a proposal and only saved to a Post after you explicitly accept it.

= Is this suitable for a multi-author site? =

No. This is a firm requirement, not a limitation we're working to remove — see "Requirements & Supported Setup" above. Work Copilot's actions are gated by WordPress's standard, site-wide content capabilities, and it is designed and tested only for a single Administrator on a dedicated install.

= Where can I see what data was sent to the AI? =

Work Copilot → AI Audit Log shows the prompt, input context, output, and your accept/dismiss decision for every AI action.

== Screenshots ==

1. Page view showing structured Items under Headings.
2. AI assistant proposing edits to an Item — pending your approval.
3. AI Audit Log showing prompt/response history.

== Changelog ==

= 1.2.2 =
* Improved JSON parsing reliability for multi-item AI edit proposals.
* Fixed response truncation handling for large bulk-edit requests.

= 1.1.0 =
* Added page-level AI chat and item editing via AI assistant.
* Added Markdown rendering support for item content.

= 1.0.0 =
* Initial release: Pages/Headings/Items structure, taxonomy sync, AI proposal workflow, AI audit log.

== Upgrade Notice ==

= 1.2.2 =
Improves reliability of AI-assisted bulk item editing. No action required.
