<?php
/**
 * AI Widget Template
 *
 * Floating AI assistant widget for frontend pages
 * Updated: 2 action buttons, context selector, prompt chips
 */

// Only show if user is logged in and AI is enabled
if (!is_user_logged_in() || !get_option('wcp_ai_enabled', false)) {
    return;
}

// Embedded mode: a site-wide instance (e.g. the homepage "Chat" tab) with no
// single page — passed in via get_template_part(..., array('embedded' => true)).
$embedded = !empty($args['embedded']);

if ($embedded) {
    $page_id = 0;
} else {
    // Get current page ID
    global $post;
    $page_id = ($post && $post->post_type === 'page') ? $post->ID : 0;

    if (!$page_id) {
        return; // Only show on pages (the embedded instance covers the homepage)
    }
}

// Research chips are surfaced only through Build 0's researcher-mode flag.
$researcher_mode_enabled = class_exists('WCP_Researcher_Mode')
    ? (bool) get_option(WCP_Researcher_Mode::OPTION_ACTIVE, false)
    : (bool) get_option('wcp_researcher_mode_active', false);

// Get saved prompts
$saved_prompts = get_option('wcp_saved_prompts', array());
if (empty($saved_prompts)) {
    $saved_prompts = array(
        array('label' => 'Summarise', 'prompt' => 'Summarise this page and its items'),
        array('label' => 'Important', 'prompt' => 'What are the most important items here?'),
        array('label' => 'Next Steps', 'prompt' => 'Suggest next steps based on this context'),
    );
}
?>

<div id="wcp-ai-widget" class="wcp-ai-widget<?php echo $embedded ? ' wcp-ai-widget--embedded' : ' minimized'; ?>">
    <!-- Floating toggle button (when minimized) — not used in embedded mode -->
    <button type="button" class="wcp-ai-toggle" aria-label="Open AI Assistant">
        <span class="dashicons dashicons-format-chat"></span>
    </button>

    <!-- Widget container -->
    <div class="wcp-ai-container">
        <!-- Header -->
        <div class="wcp-ai-header">
            <div class="wcp-ai-header-left">
                <button type="button" class="wcp-ai-expand" aria-label="Expand to full screen">
                    <span class="dashicons dashicons-fullscreen-alt"></span>
                </button>
                <h3><?php _e('AI Assistant', 'work-copilot'); ?></h3>
            </div>
            <div class="wcp-ai-header-actions">
                <button type="button" class="wcp-ai-minimize" aria-label="Minimize">
                    <span class="dashicons dashicons-minus"></span>
                </button>
                <button type="button" class="wcp-ai-close" aria-label="Close">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
        </div>

        <!-- Context selector -->
        <div class="wcp-ai-context-selector">
            <label><?php _e('Context:', 'work-copilot'); ?></label>
            <select id="wcp-ai-context-mode" class="wcp-ai-context-dropdown">
                <?php if (!$embedded) : ?>
                <option value="page"><?php echo esc_html(sprintf(__('This Page: %s', 'work-copilot'), get_the_title($page_id))); ?></option>
                <?php endif; ?>
                <option value="corpus"<?php selected($embedded); ?>><?php _e('Entire Corpus (RAG)', 'work-copilot'); ?></option>
                <option value="select"><?php _e('Select Pages...', 'work-copilot'); ?></option>
            </select>
        </div>

        <!-- Model + thinking selector -->
        <div class="wcp-ai-model-selector">
            <select id="wcp-ai-model">
                <option value="claude-haiku-4-5-20251001">Haiku 4.5 &mdash; Fast</option>
                <option value="claude-sonnet-4-6" selected>Sonnet 4.6 &mdash; Balanced</option>
                <option value="claude-opus-4-8">Opus 4.8 &mdash; Powerful</option>
            </select>
            <select id="wcp-ai-thinking" disabled title="<?php _e('Extended thinking is only available with Opus 4.8', 'work-copilot'); ?>">
                <option value="0"><?php _e('No thinking', 'work-copilot'); ?></option>
                <option value="1000"><?php _e('Thinking: low', 'work-copilot'); ?></option>
                <option value="5000"><?php _e('Thinking: medium', 'work-copilot'); ?></option>
                <option value="10000"><?php _e('Thinking: high', 'work-copilot'); ?></option>
            </select>
        </div>

        <!-- Page picker (shown when context mode is 'select') -->
        <div class="wcp-ai-page-picker" style="display: none;">
            <div class="wcp-ai-page-picker-header">
                <input type="text" id="wcp-ai-page-search" placeholder="<?php _e('Search pages...', 'work-copilot'); ?>">
            </div>
            <div class="wcp-ai-page-list">
                <!-- Pages will be loaded by JavaScript -->
            </div>
            <div class="wcp-ai-selected-pages">
                <span class="wcp-ai-selected-label"><?php _e('Selected:', 'work-copilot'); ?></span>
                <span class="wcp-ai-pagepicker-count">0</span>
            </div>
        </div>

        <!-- Mission indicator -->
        <div class="wcp-ai-mission-indicator">
            <button type="button" class="wcp-mission-toggle">
                <span class="wcp-mission-label"><?php _e('Mission:', 'work-copilot'); ?></span>
                <span class="wcp-mission-source"><?php _e('Loading...', 'work-copilot'); ?></span>
                <span class="dashicons dashicons-arrow-down"></span>
            </button>
            <div class="wcp-mission-content" style="display: none;">
                <div class="wcp-mission-text"></div>
            </div>
        </div>

        <!-- Action toggles — select one to set the intent for the next send -->
        <div class="wcp-ai-action-chips">
            <?php if ($embedded) : ?>
            <button type="button" class="wcp-ai-action-chip" data-action="chat_qa"><?php _e('Ask anything', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="web_search"><?php _e('Web search', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip wcp-ai-action-chip--canned" data-action="taxonomy_outline" data-prompt="Give me a taxonomy outline of the corpus."><?php _e('Taxonomy outline', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip wcp-ai-action-chip--canned" data-action="mission_priorities" data-prompt="Give me the 5 most important things I can work on to move the needle against my mission."><?php _e('5 things for my mission', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip wcp-ai-action-chip--canned" data-action="weekly_summary" data-prompt="Summarise what happened in the last week."><?php _e('What happened this week', 'work-copilot'); ?></button>
            <?php else : ?>
            <button type="button" class="wcp-ai-action-chip wcp-ai-action-chip--onboard" data-action="onboard"><?php _e('Onboard', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="web_search"><?php _e('Web search', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="generate_structure"><?php _e('Generate structure', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="import_document"><?php _e('Import document', 'work-copilot'); ?></button>
            <input type="file" id="wcp-ai-document-upload" accept=".md,text/markdown" style="display:none;">
            <?php $wcp_pdf_summary_enabled = function_exists('wcp_feature') && wcp_feature('pdf_summary'); ?>
            <?php if ($researcher_mode_enabled && $wcp_pdf_summary_enabled) : ?>
            <button type="button" class="wcp-ai-action-chip" data-action="import_pdf_reference"><?php _e('Import PDF reference', 'work-copilot'); ?></button>
            <input type="file" id="wcp-ai-pdf-reference-upload" accept=".pdf,application/pdf" style="display:none;">
            <?php endif; ?>
            <button type="button" class="wcp-ai-action-chip" data-action="generate_pages"><?php _e('Create sub-pages', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="create_goal"><?php _e('Create goal', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="rewrite_content"><?php _e('Edit page', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="append_content"><?php _e('Append to page', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="edit_items"><?php _e('Edit items', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="fetch_posts"><?php _e('Fetch posts', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="fetch_structure"><?php _e('Fetch structure', 'work-copilot'); ?></button>
            <?php if ($researcher_mode_enabled) : ?>
            <button type="button" class="wcp-ai-action-chip" data-action="research_list_references"><?php _e('List refs/claims', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip wcp-ai-action-chip--canned" data-action="research_suggest_topics" data-prompt="Suggest useful sub-topics or sub-questions for this research space."><?php _e('Suggest topics', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip wcp-ai-action-chip--canned" data-action="research_identify_gaps" data-prompt="Identify gaps in the research headings and items on this page, and propose additional topics"><?php _e('Identify gaps', 'work-copilot'); ?></button>
            <button type="button" class="wcp-ai-action-chip" data-action="research_find_references"><?php _e('Find references', 'work-copilot'); ?></button>
            <?php endif; ?>
            <?php if (class_exists('WCPD_Delegation_Manager') && get_option('wcpd_enabled') === '1') : ?>
            <button type="button" class="wcp-ai-action-chip" data-action="agent_review"><?php _e('Agent review', 'work-copilot'); ?></button>
            <?php endif; ?>
            <?php endif; ?>
        </div>


        <!-- Conversation view -->
        <div class="wcp-ai-conversation">
            <div class="wcp-ai-messages">
                <!-- Messages will be inserted here by JavaScript -->
            </div>
        </div>

        <!-- Input area -->
        <div class="wcp-ai-input-area">
            <div class="wcp-ai-input-wrapper">
                <textarea
                    id="wcp-ai-prompt"
                    class="wcp-ai-prompt"
                    rows="3"
                    placeholder="<?php _e('Ask a question or describe what you need...', 'work-copilot'); ?>"
                ></textarea>
                <div class="wcp-ai-input-actions">
                    <button type="button" id="wcp-ai-save-prompt" class="wcp-ai-save-btn" title="<?php _e('Save as chip', 'work-copilot'); ?>">
                        <span class="dashicons dashicons-star-empty"></span>
                    </button>
                    <button type="button" id="wcp-ai-send" class="wcp-ai-send-btn">
                        <span class="dashicons dashicons-arrow-up-alt2"></span>
                        <?php _e('Send', 'work-copilot'); ?>
                    </button>
                </div>
            </div>

            <div class="wcp-ai-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <span class="wcp-ai-loading-text"><?php _e('AI is thinking...', 'work-copilot'); ?></span>
            </div>
        </div>

        <!-- Content proposal panel (rewrite / append) -->
        <div class="wcp-ai-content-proposal-panel" style="display:none;">
            <div class="wcp-ai-approval-header">
                <h4 class="wcp-ai-content-proposal-title"></h4>
                <p class="description"><?php _e('Review the proposed content before accepting.', 'work-copilot'); ?></p>
            </div>
            <div class="wcp-ai-content-proposal-preview"></div>
            <div class="wcp-ai-approval-actions">
                <button type="button" class="wcp-ai-content-accept-btn button button-primary"><?php _e('Accept', 'work-copilot'); ?></button>
                <button type="button" class="wcp-ai-content-dismiss-btn button"><?php _e('Dismiss', 'work-copilot'); ?></button>
            </div>
        </div>

        <!-- Approval panel (shown when proposals need approval) -->
        <div class="wcp-ai-approval-panel" style="display: none;">
            <div class="wcp-ai-approval-header">
                <h4 class="wcp-ai-approval-title"><?php _e('Review AI Suggestions', 'work-copilot'); ?></h4>
                <p class="description wcp-ai-approval-description"><?php _e('Select the items you want to create, then click Create Selected.', 'work-copilot'); ?></p>
            </div>
            <div class="wcp-ai-proposals">
                <!-- Proposals will be inserted here by JavaScript -->
                <!-- Each proposal card will have this structure:
                <div class="wcp-ai-proposal-card selected" data-proposal-id="...">
                    <label class="wcp-proposal-checkbox">
                        <input type="checkbox" checked>
                    </label>
                    <h5>Item Title</h5>
                    <div class="wcp-ai-proposal-content">Content...</div>
                    <div class="wcp-ai-proposal-meta">Type: task</div>
                </div>
                -->
            </div>
            <div class="wcp-ai-approval-actions">
                <button type="button" class="wcp-ai-accept-btn button button-primary">
                    <span class="wcp-ai-accept-label"><?php _e('Create Selected', 'work-copilot'); ?></span> (<span class="wcp-ai-selected-count">0</span>)
                </button>
                <button type="button" class="wcp-ai-dismiss-btn button">
                    <?php _e('Dismiss All', 'work-copilot'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Save prompt modal -->
<div id="wcp-ai-save-modal" class="wcp-ai-modal" style="display: none;">
    <div class="wcp-ai-modal-content">
        <h4><?php _e('Save Prompt', 'work-copilot'); ?></h4>
        <input type="text" id="wcp-ai-save-label" placeholder="<?php _e('Chip label (short)', 'work-copilot'); ?>" maxlength="20">
        <div class="wcp-ai-modal-actions">
            <button type="button" id="wcp-ai-save-confirm" class="button button-primary"><?php _e('Save', 'work-copilot'); ?></button>
            <button type="button" id="wcp-ai-save-cancel" class="button"><?php _e('Cancel', 'work-copilot'); ?></button>
        </div>
    </div>
</div>

<script type="text/javascript">
    // Pass data to JavaScript
    var wcpAiWidgetData = {
        pageId: <?php echo absint($page_id); ?>,
        pageName: <?php echo wp_json_encode($embedded ? '' : get_the_title($page_id)); ?>,
        embedded: <?php echo $embedded ? 'true' : 'false'; ?>,
        restUrl: <?php echo wp_json_encode(rest_url('work-copilot/v1')); ?>,
        delegationRestUrl: <?php echo wp_json_encode(rest_url('wcp-delegation/v1')); ?>,
        delegationEnabled: <?php echo (class_exists('WCPD_Delegation_Manager') && get_option('wcpd_enabled') === '1') ? 'true' : 'false'; ?>,
        pdfSummaryEnabled: <?php echo (function_exists('wcp_feature') && wcp_feature('pdf_summary')) ? 'true' : 'false'; ?>,
        nonce: <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>
    };
</script>
