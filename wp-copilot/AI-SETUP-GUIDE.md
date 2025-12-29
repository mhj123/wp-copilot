# AI Features Setup Guide

Your Work Copilot plugin is now ready to use real AI! Follow these steps to enable AI-powered features.

## What You'll Get

Once configured, you can use:

- **AI-Assisted Tagging** - Get smart suggestions for item types, priorities, and tags
- **Page-Scoped Chat** - Ask questions about your page content and get insights
- **Coaching Prompts** - Generate actionable recommendations based on your work

**IMPORTANT:** All AI features follow human-in-the-loop principles:
- AI never writes to your database automatically
- You must explicitly accept all AI suggestions
- All AI interactions are logged for transparency

## Step 1: Get an Anthropic API Key

1. Go to [console.anthropic.com](https://console.anthropic.com/)
2. Sign up or log in
3. Navigate to "API Keys"
4. Click "Create Key"
5. Copy your API key (starts with `sk-ant-...`)

**Pricing:** Claude API uses pay-as-you-go pricing. For typical Work Copilot usage:
- Tag suggestions: ~$0.01 per 10 requests
- Chat responses: ~$0.05 per conversation
- Coaching: ~$0.10 per session

See [anthropic.com/pricing](https://www.anthropic.com/pricing) for current rates.

## Step 2: Configure Work Copilot

1. In WordPress Admin, go to **Work Copilot → Settings**
2. Check **"Enable AI Features"**
3. Paste your API key in the **"Anthropic API Key"** field
4. Choose your preferred model (Claude 3.5 Sonnet recommended)
5. Click **"Save Settings"**
6. Click **"Test Connection"** to verify it works

## Step 3: Start Using AI

### AI-Assisted Tagging

**In the admin dashboard:**
1. Go to Work Copilot → Dashboard
2. Enter a note title and content
3. Click **"AI Suggest Tags"**
4. Review suggestions and apply them

**When editing a post:**
1. In the post editor, find the "AI Assistant" meta box
2. Click **"Suggest Tags"**
3. Review and apply suggestions

### Page-Scoped Chat

**On any Page:**
1. Edit a Page
2. Find the "AI Chat & Coaching" meta box in the sidebar
3. Click a quick prompt:
   - **"Summarise"** - Get a summary of the page and its items
   - **"Important Items"** - Identify the most important items
4. View the AI's response

### Coaching Prompts

**Generate insights:**
1. Edit a Page
2. In the "AI Chat & Coaching" meta box, click:
   - **"Coach me"** - Get coaching based on your learnings
   - **"Reframe as Business Owner"** - See your work from a business perspective
   - **"Reframe as PM"** - Get product management insights
3. Review the candidate ItemPosts
4. **Accept** the ones you want to keep
5. **Dismiss** the rest

The accepted items will be created as new ItemPosts linked to your Page.

## Troubleshooting

### "AI is not configured"
- Make sure you've added your API key in Settings
- Click "Save Settings" after pasting the key
- Try the "Test Connection" button

### "Connection Failed"
- Verify your API key is correct
- Check that you have API credits in your Anthropic account
- Ensure your server can make outbound HTTPS requests

### "Could not parse AI response"
- This usually means the AI returned an unexpected format
- Check the AI Audit Log to see the raw response
- Try again with clearer input

### AI responses are slow
- Claude API typically responds in 2-5 seconds
- For faster responses, switch to Claude 3 Haiku in Settings
- For better quality, use Claude 3 Opus (slower but most capable)

## Privacy & Data

When you use AI features:
- Your content is sent to Anthropic's Claude API
- Anthropic processes the data according to their [privacy policy](https://www.anthropic.com/legal/privacy)
- No data is stored by Anthropic for training purposes
- All AI interactions are logged locally in your WordPress database

You can view all AI actions in **Work Copilot → AI Audit Log**.

## API Models Explained

| Model | Best For | Speed | Quality | Cost |
|-------|----------|-------|---------|------|
| Claude 3.5 Sonnet | General use (recommended) | Fast | Excellent | Medium |
| Claude 3 Opus | Highest quality insights | Slower | Best | Highest |
| Claude 3 Sonnet | Standard use | Fast | Good | Low |
| Claude 3 Haiku | Quick suggestions | Fastest | Good | Lowest |

Change models anytime in Settings.

## Next Steps

1. ✅ Configure your API key
2. ✅ Test the connection
3. Try AI-assisted tagging on a few notes
4. Experiment with page-scoped chat
5. Generate coaching insights
6. Review the AI Audit Log to understand what data was sent/received

## Need Help?

- Check the main README.md for general plugin documentation
- Review the AI Audit Log for transparency
- Anthropic API docs: [docs.anthropic.com](https://docs.anthropic.com/)

---

**Remember:** AI is here to assist, not replace your thinking. Always review and validate AI suggestions before accepting them!
