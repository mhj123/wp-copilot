# Work Copilot Theme

A custom WordPress theme designed specifically for the Work Copilot plugin.

## Features

### Persistent Left Sidebar Navigation
- Displays all Pages in a hierarchical, nested list
- Always visible across all page views
- Highlights the current page
- Quick access to create new Pages

### Page Template with ItemPost Management
When viewing any Page, the theme displays:
1. **Page content** - Your page title and description
2. **Create Item form** - Quick creation of ItemPosts with:
   - Title and content fields
   - Automatic categorization to current Page
   - Item type selection (Task, Info, Learning)
   - Priority selection (High, Medium, Low)
   - Tags (comma-separated)
3. **ItemPosts list** - All items belonging to the Page:
   - Displays items with badges showing type, priority, and pinned status
   - Filtering by item type and priority
   - Date and excerpt preview

## Installation

### Method 1: ZIP Upload

1. Create a ZIP file:
   ```bash
   cd /Users/hardytati/Documents/WPCopilot
   zip -r work-copilot-theme.zip work-copilot-theme -x "*.DS_Store"
   ```

2. In WordPress Admin:
   - Go to Appearance → Themes
   - Click "Add New" → "Upload Theme"
   - Choose `work-copilot-theme.zip`
   - Click "Install Now"
   - Click "Activate"

### Method 2: Manual Installation

1. Copy the theme folder to your WordPress installation:
   ```bash
   cp -r work-copilot-theme /path/to/wordpress/wp-content/themes/
   ```

2. In WordPress Admin:
   - Go to Appearance → Themes
   - Find "Work Copilot Theme"
   - Click "Activate"

## Requirements

- WordPress 5.0 or higher
- **Work Copilot Plugin** (must be installed and activated)

## Usage

### Setting Up Your Workspace

1. **Create Pages** - These represent your contexts:
   - Projects
   - Themes
   - People
   - Meetings

   Navigate to Pages → Add New and create your first page.

2. **Create Sub-Pages** - Organize pages hierarchically:
   - Set the "Parent Page" in the Page Attributes box
   - Sub-pages will appear nested in the sidebar

3. **Add ItemPosts** - On any Page:
   - Scroll to the "Create New Item" section
   - Fill in the title (required)
   - Add content, select type and priority
   - Click "Create Item"
   - The item is automatically linked to the current Page

### Navigating Your Workspace

- **Sidebar** - Click any page to view its items
- **Filters** - Use dropdowns to filter items by type or priority
- **Quick Actions** - Click the "+ New Page" button in the sidebar to create pages

## File Structure

```
work-copilot-theme/
├── style.css              # Theme header and basic styles
├── functions.php          # Theme setup and helper functions
├── header.php             # Site header and opening tags
├── footer.php             # Site footer and closing tags
├── sidebar.php            # Left navigation sidebar
├── page.php               # Page template (with ItemPost form and list)
├── index.php              # Fallback template / home page
├── README.md              # This file
└── assets/
    ├── css/
    │   └── theme.css      # Custom theme styles
    └── js/
        └── theme.js       # Form submission and filtering
```

## Customization

### Colors

Edit `assets/css/theme.css` to change colors:

- **Sidebar background**: `.wcp-sidebar { background: #1e1e1e; }`
- **Primary color**: `.wcp-btn-primary { background: #2271b1; }`
- **Item type badges**: `.wcp-type-task`, `.wcp-type-info`, `.wcp-type-learning`

### Layout

- **Sidebar width**: Change `.wcp-sidebar { width: 280px; }` and `.wcp-main-content { margin-left: 280px; }`
- **Content width**: Adjust `.wcp-content-wrapper { max-width: 1200px; }`

### Form Fields

To add more fields to the ItemPost creation form, edit `page.php` and add corresponding handling in `assets/js/theme.js`.

## Theme Functions

### Helper Functions (functions.php)

- `wcp_theme_get_page_tree($parent_id)` - Get child pages
- `wcp_theme_build_page_nav($parent_id, $current_page_id)` - Build nested page navigation
- `wcp_theme_get_page_context_term($page_id)` - Get wcp_context term for a page
- `wcp_theme_get_page_items($page_id, $filters)` - Get all items for a page

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Responsive design for mobile and tablet

## Troubleshooting

### Items not appearing on Page
- Ensure the Work Copilot plugin is activated
- Check that the Page has been published (not draft)
- Verify ItemPosts are assigned to the Page's context

### Sidebar not showing pages
- Create at least one Page first
- Ensure pages are published
- Check that the theme is properly activated

### Form submission not working
- Check browser console for errors
- Verify WordPress REST API is enabled
- Ensure Work Copilot plugin is active

## Credits

Designed specifically for use with the Work Copilot plugin.

## License

GPL v2 or later
