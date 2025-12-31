<?php
/**
 * Prompt Builder
 *
 * Builds 3-layer system prompts for AI requests
 * Layer 1: Global instructions
 * Layer 2: Page context instructions (hierarchical)
 * Layer 3: Action-specific instructions
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCP_Prompt_Builder {

    private static $instance = null;

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
     * Build complete system prompt (2 layers)
     *
     * @param string $action_type The action type (chat, generate-single, expand_draft, etc.)
     * @return string Complete system prompt
     */
    public function build_system_prompt($action_type) {
        $layers = array();

        // Layer 1: Global Instructions
        $global = $this->get_global_instructions();
        if (!empty($global)) {
            $layers[] = $global;
        }

        // Layer 2: Action-Specific Instructions
        $action_instructions = $this->get_action_instructions($action_type);
        if (!empty($action_instructions)) {
            $layers[] = "## Current Task:\n\n" . $action_instructions;
        }

        return implode("\n\n", $layers);
    }

    /**
     * Layer 1: Get global AI instructions
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
     * Layer 2: Get action-specific instructions
     *
     * @param string $action_type The action type
     * @return string Action instructions
     */
    private function get_action_instructions($action_type) {
        $instructions = array(
            // Chat/Q&A action for frontend widget
            'chat' => "You are a helpful assistant answering questions about the user's work and notes. "
                . "Be conversational, supportive, and direct. Use the provided context to give informed answers. "
                . "If asked about something not in the context, say so honestly.",

            // Generate items action for frontend widget
            'generate-single' => "Generate ONE actionable item based on the user's request and the context provided. "
                . "Be specific and concrete. Format your response as valid JSON:\n"
                . '{"title": "Item title", "content": "Detailed content", "item_type": "task|info|learning"}',

            'generate-multiple' => "Generate 3-5 actionable items based on the user's request and context. "
                . "Each item should be specific, concrete, and immediately useful. Format your response as valid JSON:\n"
                . '[{"title": "Item 1 title", "content": "Detailed content", "item_type": "task|info|learning"}, ...]',

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
