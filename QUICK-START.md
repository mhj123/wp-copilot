# Work Copilot - Quick Start Guide 🚀

## Your Project is Ready!

You now have:
- ✅ **Work Copilot Plugin** (with AI enabled)
- ✅ **Work Copilot Theme** (with sidebar navigation)

Both are ready to install in WordPress.

---

## Step 1: Install the Plugin

### Option A: Upload ZIP

```bash
# Create plugin ZIP
cd /Users/hardytati/Documents/WPCopilot
zip -r work-copilot.zip wp-copilot -x "*.DS_Store" -x "__MACOSX"
```

Then in WordPress:
1. Go to **Plugins → Add New → Upload Plugin**
2. Choose `work-copilot.zip`
3. Click **Install Now**
4. Click **Activate**

### Option B: Manual Install

```bash
# Copy to WordPress
cp -r /Users/hardytati/Documents/WPCopilot/wp-copilot /path/to/wordpress/wp-content/plugins/
```

Then in WordPress:
1. Go to **Plugins**
2. Find "Work Copilot"
3. Click **Activate**

---

## Step 2: Install the Theme

### Create theme ZIP

```bash
cd /Users/hardytati/Documents/WPCopilot
zip -r work-copilot-theme.zip work-copilot-theme -x "*.DS_Store" -x "__MACOSX"
```

### Install in WordPress

1. Go to **Appearance → Themes → Add New → Upload Theme**
2. Choose `work-copilot-theme.zip`
3. Click **Install Now**
4. Click **Activate**

---

## Step 3: Enable AI Features (Optional)

### Get Anthropic API Key

1. Go to https://console.anthropic.com/
2. Sign up (free credits available)
3. Navigate to "API Keys"
4. Click "Create Key"
5. Copy your API key (starts with `sk-ant-...`)

### Configure in WordPress

1. Go to **Work Copilot → Settings**
2. Check **"Enable AI Features"**
3. Paste your API key
4. Click **"Save Settings"**
5. Click **"Test Connection"** to verify

**Full guide:** See `wp-copilot/AI-SETUP-GUIDE.md`

---

## Step 4: Start Using Work Copilot

### Create Your First Page

1. Go to **Pages → Add New**
2. Title: "My First Project"
3. Add description (optional)
4. Publish

### Add an ItemPost

Visit your page on the frontend and use the **Create New Item** form:
- Enter title and content
- Select item type (Task, Info, or Learning)
- Set priority
- Click "Create Item"

Or use the dashboard:
1. Go to **Work Copilot → Dashboard**
2. Use the quick create form

### View Your Work

- **Sidebar:** See all Pages in hierarchical list
- **Page view:** See all items for that page
- **Filters:** Filter by type and priority

---

## Key Features

### Content Organization
- **Pages** = Contexts (Projects, Themes, People)
- **Headings** = Sub-contexts (create via Pages → Headings)
- **ItemPosts** = Atomic notes (Tasks, Info, Learnings)

### AI Features (when enabled)
- **Tag Suggestions** - Auto-suggest types, priorities, tags
- **Page Chat** - Ask questions about your pages
- **Coaching** - Generate insights and recommendations

### Transparency
- **AI Audit Log** - See all AI interactions
- **Human-in-the-Loop** - AI never writes without approval

---

## File Locations

```
/Users/hardytati/Documents/WPCopilot/
├── wp-copilot/              # Plugin (install this)
├── work-copilot-theme/      # Theme (install this)
├── AI-SETUP-GUIDE.md        # Detailed AI setup
├── ENABLE-AI-SUMMARY.md     # What changed to enable AI
└── QUICK-START.md           # This file
```

---

## Need Help?

### Documentation
- **Plugin README:** `wp-copilot/README.md`
- **AI Setup Guide:** `wp-copilot/AI-SETUP-GUIDE.md`
- **Theme README:** `work-copilot-theme/README.md`

### In WordPress
- **Settings:** Work Copilot → Settings
- **AI Audit Log:** Work Copilot → AI Audit Log
- **Dashboard:** Work Copilot → Dashboard

---

## Checklist

- [ ] Plugin installed and activated
- [ ] Theme installed and activated
- [ ] Created first Page
- [ ] Created first ItemPost
- [ ] (Optional) AI configured and tested
- [ ] Explored the sidebar navigation
- [ ] Tried filtering items

---

**You're ready to go!** Start capturing your work in atomic notes and organizing with Pages. Enable AI when you're ready for smart assistance. 🎉
