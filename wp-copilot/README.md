# Work Copilot

A WordPress plugin for personal knowledge and work management with AI-assisted sensemaking.

## What is Work Copilot?

Work Copilot helps you capture atomic notes (ItemPosts), organize them with flexible structure (Pages & Headings), and make sense of accumulated knowledge through AI assistance—all while keeping you in complete control.

**Core principles:**
- Native WordPress first (uses Posts, Pages, and custom taxonomies)
- Human-in-the-loop AI (AI never writes to database without your approval)
- Atomic notes by intent (encourage single-idea notes, not enforce limits)
- Structure doubles as semantics (your organization IS your filtering system)

## Installation

### From this directory:

1. Copy the entire `WPCopilot` directory to your WordPress plugins folder:
   ```bash
   cp -r /Users/hardytati/Documents/WPCopilot /path/to/wordpress/wp-content/plugins/work-copilot
   ```

2. Navigate to your WordPress admin → Plugins

3. Find "Work Copilot" and click "Activate"

4. The plugin will automatically:
   - Register the `wcp_heading` custom post type
   - Register taxonomies (contexts, item types, priorities, pinned)
   - Create the AI audit log database table
   - Populate default taxonomy terms

## Usage

### Getting Started

After activation, you'll see a new "Work Copilot" menu item in your WordPress admin.

#### 1. Create Your Structure (Pages & Headings)

**Pages** represent big contexts:
- Projects
- Themes
- Meetings
- People

Create Pages as you normally would in WordPress (Pages → Add New). These will automatically sync to the `wcp_context` taxonomy.

**Headings** represent sub-structure under Pages:
- Project phases
- Meeting agendas
- Topic breakdowns

Create Headings via Pages → Headings → Add New. Each Heading must belong to one Page or another Heading.

#### 2. Capture Notes (ItemPosts)

ItemPosts are atomic notes—tasks, learnings, ideas, observations.

**Quick create from dashboard:**
1. Go to Work Copilot → Dashboard
2. Enter title and content
3. Select relevant contexts (Pages/Headings)
4. Choose item type (task, info, learning)
5. Set priority if needed
6. Click "Create Item"

**Create from WordPress editor:**
1. Posts → Add New
2. Write your note
3. In the sidebar, select contexts under "Contexts (Pages & Headings)"
4. Set taxonomies (Item Types, Priorities, Pinned)
5. Publish

#### 3. View Your Work

**By Page:**
- Visit any Page on your site
- You'll see all ItemPosts linked to that Page and its child Headings
- Use filters to narrow by type, priority, or tags

**By Context:**
- Use the Context Tree in the Work Copilot dashboard
- Click any context to see related items

**By Taxonomy:**
- Visit archive pages for item types, priorities, or tags
- WordPress generates these automatically

### AI Features

AI features are currently scaffolded with mock responses. To integrate with an actual AI service (like Claude API):

#### AI-Assisted Tagging
When creating a note:
1. Enter title and content
2. Click "AI Suggest Tags"
3. Review suggestions
4. Apply or dismiss

#### Page-Scoped Chat
On any Page:
1. Go to edit mode
2. In the "AI Chat & Coaching" meta box, click a quick prompt:
   - Summarise
   - Important Items
3. Review AI response

#### Coaching Prompts
On any Page, click coaching buttons:
- Coach me (based on learnings)
- Reframe as Business Owner
- Reframe as PM

The AI will generate candidate ItemPosts. You must explicitly **accept or dismiss** each one.

**CRITICAL:** AI NEVER writes to the database automatically. All AI outputs are proposals that require your approval.

### AI Audit Log

Every AI interaction is logged for transparency:
- Go to Work Copilot → AI Audit Log
- See timestamp, action type, model, context
- View what was accepted vs dismissed
- Click "View Details" to see full prompts and outputs

## Architecture

### Content Model

- **Pages** (native `page` post type) → Contexts
- **Headings** (`wcp_heading` custom post type) → Sub-contexts
- **ItemPosts** (native `post` post type) → Atomic notes

### Taxonomies

- `wcp_context` - Hierarchical, mirrors Pages/Headings structure
- `item_type` - task, info, learning
- `priority` - high, medium, low
- `pinned` - yes, no
- `post_tag` - Freeform tags

### Database

All content uses native WordPress tables except:
- `wp_wcp_ai_actions` - AI audit log (custom table)

### Key Files

```
work-copilot/
├── work-copilot.php              # Main plugin file
├── includes/
│   ├── class-post-types.php      # Register wcp_heading
│   ├── class-taxonomies.php      # Register taxonomies
│   ├── class-taxonomy-sync.php   # Sync Pages/Headings → wcp_context
│   ├── class-rest-api.php        # REST endpoints
│   └── class-ai-logger.php       # AI audit logging
├── admin/
│   └── class-admin.php           # Admin UI
├── public/
│   └── class-public.php          # Frontend views
└── assets/
    ├── css/
    │   ├── admin.css
    │   └── public.css
    └── js/
        ├── admin.js
        └── public.js
```

## Integrating Real AI

The plugin is currently using mock AI responses. To integrate with Claude API or another AI service:

1. **Add API credentials:**
   - Store API key securely (use WordPress options or constants)
   - Add settings page for API configuration

2. **Update REST API endpoints in `includes/class-rest-api.php`:**
   - Replace mock responses with actual API calls
   - Update these methods:
     - `ai_suggest_tags()`
     - `ai_page_chat()`
     - `ai_coaching()`

3. **Build context packs:**
   - The `build_page_context()` method gathers relevant data
   - Enhance it to include more context (recent items, learnings, etc.)
   - Format for your AI service

4. **Parse AI responses:**
   - Ensure responses match expected format
   - Handle errors gracefully
   - Log everything via `WCP_AI_Logger`

Example integration snippet:

```php
// In ai_page_chat() method
$api_key = get_option('wcp_claude_api_key');
$client = new ClaudeClient($api_key);

$response = $client->messages->create([
    'model' => 'claude-3-5-sonnet-20241022',
    'max_tokens' => 1024,
    'messages' => [
        [
            'role' => 'user',
            'content' => $prompt . "\n\nContext: " . json_encode($context)
        ]
    ]
]);

$ai_message = $response['content'][0]['text'];
```

## Next Steps / Roadmap

This is Release 1. Future releases may include:

- **Full AI integration** (Claude API, OpenAI, etc.)
- **Version history** for posts and AI actions
- **LLM-generated Heading structures**
- **MCP integrations** (Asana, Monday, etc.)
- **Multi-user collaboration**
- **Learning systems** (spaced repetition)

See `prd.md` for full product requirements.

## Contributing

This is a personal-use plugin. If you'd like to extend it:

1. Read `CLAUDE.md` for development principles
2. Follow the "human-in-the-loop" AI pattern strictly
3. Prefer native WordPress constructs
4. Keep it simple and transparent

## License

GPL v2 or later

## Support

For issues or questions, refer to the PRD and architecture docs included in this repository.
