<?php

return [
    // Widget
    'title' => 'Work Assistant',
    'hint' => 'Local LLM · Purchase board guide',
    'open' => 'Open work assistant',
    'greeting' => 'Hello. Ask anything about the purchase board workflow.',
    'example' => 'e.g. "How do I register a purchase candidate?", "Why is it not moving to forwarding?"',
    'placeholder' => 'Type your question…',
    'send' => 'Send',
    'loading' => 'Searching…',

    // Responses
    'empty_question' => 'Please enter a question.',
    'no_index' => 'The work guide index is not ready yet. Please contact an administrator.',
    'no_answer' => '(no response)',
    'error' => 'An error occurred while searching the work guide. Please try again shortly.',

    // Settings
    'settings_title' => 'Work Assistant (chatbot)',
    'settings_intro' => 'Shows the guide Q&A chatbot to sales, managers and system admins. Server settings (ASSISTANT_*) must be configured for it to work.',
    'settings_enabled' => 'Enable chatbot',
    'settings_env_off' => '⚠️ Server setting (ASSISTANT_ENABLED) is off — enabling this toggle will not expose the widget.',
    'settings_index_missing' => '⚠️ Index file not found (ASSISTANT_INDEX_PATH).',
    'settings_index_ok' => 'Index: :size MB · updated :when',
];
