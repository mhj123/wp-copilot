# WP Copilot — Quick Start Guide

## What you have

- **WP Copilot** — the plugin. Data model, REST API, AI actions, admin
  screens.
- **WP Copilot Theme** — the workspace UI (pages, items, sidebar
  navigation, the AI assistant panel).

**Both are required today.** The plugin alone activates but has no front-end
templates of its own — the theme is where pages, items, and the AI widget
actually render. Install and activate both before you judge whether the
product works.

---

## Step 1: Install the plugin

### Option A: Upload ZIP

```bash
cd /path/to/work-copilot
zip -r work-copilot.zip wp-copilot -x "*.DS_Store" -x "__MACOSX"
```

In WordPress:
1. **Plugins → Add New → Upload Plugin**
2. Choose `work-copilot.zip`
3. **Install Now**, then **Activate**

### Option B: Manual install

```bash
cp -r /path/to/work-copilot/wp-copilot /path/to/wordpress/wp-content/plugins/
```

In WordPress: **Plugins**, find "WP Copilot", **Activate**.

---

## Step 2: Install the theme

```bash
cd /path/to/work-copilot
zip -r work-copilot-theme.zip work-copilot-theme -x "*.DS_Store" -x "__MACOSX"
```

In WordPress:
1. **Appearance → Themes → Add New → Upload Theme**
2. Choose `work-copilot-theme.zip`
3. **Install Now**, then **Activate**

Activating the theme replaces your site's front end with the WP Copilot
workspace UI. This is a dedicated, single-purpose install — see
**Requirements & Supported Setup** in `wp-copilot/readme.txt` before pointing
it at a site you use for anything else.

---

## Step 3: Enable AI features (optional)

The plugin is fully usable — pages, items, structure, tagging — without an
API key. AI features are opt-in.

### Get an Anthropic API key

1. Go to https://console.anthropic.com/
2. Sign up, then **API Keys → Create Key**
3. Copy the key (starts with `sk-ant-...`)

### Configure it

1. **WP Copilot → Settings**
2. Check **"Enable AI Features"**
3. Paste your API key, **Save Settings**
4. **Test Connection** to verify

Full detail: `wp-copilot/AI-SETUP-GUIDE.md`.

---

## Step 4: Start using it

### Create your first page

1. **Pages → Add New**
2. Title it (e.g. "My First Project"), publish

### Add a note

On the page's front end, use the inline create form: title, content, item
type (Task / Info / Learning), priority. Or go to **WP Copilot → Dashboard**
and use the quick-create form there.

### Find your way around

- **Sidebar** — every page, hierarchically
- **Page view** — every item filed under that page
- **Filters** — by type and priority

---

## What's here

- **Structure** — Pages are contexts (projects, themes, people). Headings add
  sub-structure under a page. Items are atomic notes — a single task, idea, or
  observation — reusable across as many pages as you like, as the same note,
  not a copy.
- **AI, on request** — tag suggestions, page-scoped chat, generation prompts
  that produce proposed items. AI never writes to your data on its own: every
  output is a candidate you accept or dismiss, and every AI action is logged
  under **WP Copilot → AI Audit Log**.

---

## File locations

```
work-copilot/
├── wp-copilot/              # Plugin — install this
├── work-copilot-theme/      # Theme — install this
├── README.md                # Project overview
├── ENABLE-AI-SUMMARY.md     # What AI-enabling changed
└── QUICK-START.md           # This file
```

---

## Need help?

- **Project overview:** `README.md`
- **Plugin details, external services, requirements:** `wp-copilot/readme.txt`
- **AI setup:** `wp-copilot/AI-SETUP-GUIDE.md`
- **In WordPress:** WP Copilot → Settings / Dashboard / AI Audit Log

---

## Checklist

- [ ] Plugin installed and activated
- [ ] Theme installed and activated
- [ ] Created a first Page
- [ ] Added a first Item
- [ ] (Optional) AI configured and connection tested
- [ ] Explored the sidebar and filters
