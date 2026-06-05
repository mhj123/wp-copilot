<?php
/**
 * Prompt Builder
 *
 * Builds system prompts for AI requests using the following layers:
 * Layer 1: Global Soul/Mission (WHO the AI is)
 * Layer 2: System Architecture Guide (HOW this system works)
 * Layer 3: Page Context + Objectives (WHAT this page is about)
 * Layer 4: General System Instructions (HOW to behave)
 * Layer 5: Action-Specific Instructions (WHAT task to perform)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Prompt_Builder {

    private static $instance = null;

    /**
     * Static description of WPCopilot's data model and AI interaction contract.
     * Injected as Layer 2 so the AI understands the system it is operating in.
     */
    const SYSTEM_GUIDE = <<<'MD'
WPCopilot is a personal knowledge and work management system built on WordPress.

**Structure:**
- **Pages** are context areas — topics, projects, or domains of your work.
- **Headings** are sub-sections within a page, grouping related items.
- **Items** are atomic notes attached to pages and/or headings. Each item has a type:
  `task` (actionable to-do), `info` (reference/factual note), or `learning` (insight to remember).
- An item can belong to multiple pages simultaneously.

**Your role as AI assistant:**
- You receive the full content of the current page and its items as context.
- You propose additions or answers — you never write directly to the database.
- Every suggestion you make requires the user to explicitly accept it before anything is saved.
- When generating items, headings, or pages: be specific, concrete, and immediately useful.
- When chatting: answer from the provided context; be honest when something isn't in it.

**Per-page objectives:** Some pages have a mission/objective defined. When present, use it to
focus your suggestions on what matters for that area.

**Memories:** A special Memories page stores facts extracted from past conversations —
user background, preferences, project context. When shown in context, treat them as reliable
background knowledge about the user.
MD;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Constructor
    }

    /**
     * Build complete system prompt (4 layers)
     *
     * @param string $action_type The action type (chat, generate-single, expand_draft, etc.)
     * @param int $page_id Optional page ID for page-specific context
     * @param int $item_count Optional item count for generate-multiple (0 = let AI decide)
     * @return string Complete system prompt
     */
    public function build_system_prompt($action_type, $page_id = 0, $item_count = 0) {
        $layers = array();
        $mission_loader = WCP_Mission_Loader::instance();

        // Layer 1: Global Soul/Mission (WHO the AI is)
        $global_mission = $mission_loader->get_global_mission();
        if (!empty($global_mission)) {
            $layers[] = "# Your Soul/Mission\n\n" . $global_mission;
        }

        // Layer 2: System Architecture Guide (HOW this system works)
        $layers[] = "# How This System Works\n\n" . self::SYSTEM_GUIDE;

        // Layer 3: Page Context + Objectives (WHAT this page is about)
        if ($page_id) {
            $page_objectives = $mission_loader->get_page_objectives($page_id);
            if (!empty($page_objectives)) {
                $page = get_post($page_id);
                $page_title = $page ? $page->post_title : '';
                $layers[] = "# Current Page Context\n\n**Page:** {$page_title}\n\n**Objectives:**\n\n{$page_objectives}";
            }
        }

        // Layer 4: General System Instructions (HOW to behave)
        $global = $this->get_global_instructions();
        if (!empty($global)) {
            $layers[] = "# System Instructions\n\n" . $global;
        }

        // Layer 5: Action-Specific Instructions (WHAT task to perform)
        $action_instructions = $this->get_action_instructions($action_type, $item_count);
        if (!empty($action_instructions)) {
            $layers[] = "# Current Task\n\n" . $action_instructions;
        }

        return implode("\n\n---\n\n", $layers);
    }

    /**
     * Layer 3: Get global AI instructions
     *
     * @return string Global instructions
     */
    private function get_global_instructions() {
        $default = "You are a work copilot helping a professional manage their knowledge and work. ";
        $default .= "Be clear, actionable, and concise. ";
        $default .= "When generating items, provide specific and practical suggestions. ";
        $default .= "Remember that all your suggestions require user approval before being saved.";

        return get_option('wcp_ai_global_instructions', $default);
    }

    /**
     * Layer 4: Get action-specific instructions
     *
     * @param string $action_type The action type
     * @param int $item_count Optional item count for generate-multiple (0 = let AI decide)
     * @return string Action instructions
     */
    private function get_action_instructions($action_type, $item_count = 0) {
        // Dynamic instruction for generate-multiple based on item count
        $item_count_instruction = $item_count > 0
            ? "Generate exactly {$item_count} actionable items"
            : "Generate 3-5 actionable items";

        $instructions = array(
            // Chat/Q&A action for frontend widget
            'chat' => "You are a helpful assistant answering questions about the user's work and notes. "
                . "Be conversational, supportive, and direct. Use the provided context to give informed answers. "
                . "If asked about something not in the context, say so honestly.",

            // Generate items action for frontend widget
            'generate-single' => "Generate ONE actionable item based on the user's request and the context provided. "
                . "Be specific and concrete. Format your response as valid JSON:\n"
                . '{"title": "Item title", "content": "Detailed content", "item_type": "task|info|learning"}',

            'generate-multiple' => "{$item_count_instruction} based on the user's request and context. "
                . "Each item should be specific, concrete, and immediately useful.\n\n"
                . "IMPORTANT: Respond with ONLY a valid JSON array. No text before or after. Example format:\n"
                . '[{"title": "Item title", "content": "Detailed content", "item_type": "task"}]\n\n'
                . "Valid item_type values: task, info, learning. Start your response with [ and end with ].",

            'generate-headings' => "Generate headings to structure the current page based on the user's request and context. "
                . "Headings are section titles that organise items within a page — keep them short, clear, and noun-phrase style.\n\n"
                . "IMPORTANT: Respond with ONLY a valid JSON array of heading objects. No text before or after. Example:\n"
                . '[{"title": "Key Outcomes"}, {"title": "Open Questions"}, {"title": "Next Steps"}]\n\n'
                . "Start your response with [ and end with ].",

            'generate-pages' => "Generate sub-page proposals to be created as child pages under the current page. "
                . "Each page should represent a distinct area of work or topic that warrants its own dedicated space. "
                . "Page titles should be clear and descriptive. Content is optional — a brief description of the page's purpose is ideal.\n\n"
                . "IMPORTANT: Respond with ONLY a valid JSON array of page objects. No text before or after. Example:\n"
                . '[{"title": "Q3 Goals", "content": "Tracking goals and progress for Q3."}, {"title": "Team Retros", "content": ""}]\n\n'
                . "Start your response with [ and end with ].",

            // Page content rewrite
            'rewrite_content' => "Rewrite the page content below based on the user's instruction. "
                . "Return ONLY the rewritten content — no commentary, no explanation, no preamble. "
                . "Preserve the user's voice. You may use simple HTML (p, ul, li, strong, em) but keep it minimal.",

            // Page content append
            'append_content' => "Write new content to append to the existing page content based on the user's instruction. "
                . "Return ONLY the new content to be appended — no commentary, no explanation, no preamble. "
                . "It should flow naturally after the existing content. You may use simple HTML (p, ul, li, strong, em).",

            // Editor sidebar action - expand/modify draft content
            'expand_draft' => "You are helping the user expand and improve their draft content. "
                . "Based on their instructions, modify or expand the draft they provide. "
                . "Return ONLY the improved content without any explanations or commentary. "
                . "Maintain the user's voice and style while enhancing the content.",

            // Legacy coaching (kept for backwards compatibility)
            'coaching' => "You are a thoughtful work coach providing guidance and asking clarifying questions when helpful. "
                . "Be supportive but direct. Focus on helping the user think through their challenges and opportunities.",

            // Legacy reframe
            'reframe' => "Rewrite the provided item draft from the requested perspective. "
                . "Keep it concise and actionable. Maintain the core information while shifting the viewpoint.",

            // Goal planning: understand the goal and propose action items
            'plan-goal' => "You are helping the user define a goal and create a concrete action plan.\n\n"
                . "The user has described what they want to achieve. Your task:\n"
                . "1. Write a clear, concise statement of what you understand they want to achieve — "
                . "informed by this page's mission and context where relevant.\n"
                . "2. Propose 3-7 concrete, actionable tasks that would move them toward that goal.\n\n"
                . "IMPORTANT: Respond with ONLY a valid JSON object. No text before or after. Format:\n"
                . "{\"understanding\": \"A clear 1-3 sentence statement of what the user wants to achieve and why it matters here.\","
                . " \"action_items\": [{\"title\": \"Short action title\", \"content\": \"What needs to be done\"}, ...]}\n\n"
                . "Start your response with { and end with }.",
        );

        return isset($instructions[$action_type]) ? $instructions[$action_type] : '';
    }

    /**
     * Build user message with context
     *
     * @param string $user_prompt The user's prompt/question
     * @param array $context_data Context data from Context_Builder
     * @param array $conversation_history Conversation history (not included in user message, handled separately)
     * @return string Formatted user message
     */
    public function build_user_message($user_prompt, $context_data) {
        $message = "User Request:\n{$user_prompt}\n\n";

        // Add formatted context
        $context_builder = WCP_Context_Builder::instance();
        $formatted_context = $context_builder->format_for_prompt($context_data);

        if (!empty($formatted_context)) {
            $message .= $formatted_context;
        }

        return $message;
    }
}
