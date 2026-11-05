<?php

return [
    'title' => 'Alimtalk log',
    'subtitle' => 'KakaoTalk Alimtalk (BizM) send records. "Sent" means accepted by BizM, not confirmed delivery. Acknowledge failures after checking.',
    'search_ph' => 'Phone · recipient · template · region',
    'status_all' => 'All statuses',
    'status' => ['sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped'],
    'attention_only' => 'Needs attention (failed, not acknowledged)',
    'count' => ':count',
    'per_page' => ':count per page',
    'col_time' => 'Time',
    'col_template' => 'Template',
    'col_to' => 'Recipient',
    'col_status' => 'Status',
    'col_detail' => 'Detail',
    'ack' => 'Acknowledge',
    'acked' => 'Acknowledged',
    'ack_all' => 'Acknowledge all :n failures',
    'ack_all_confirm' => 'Acknowledge all :n unacknowledged failures? Records are kept.',
    'empty' => 'No Alimtalk records.',
    'reason' => [
        'unknown_template' => 'Unknown template — check the code',
        'no_phone' => 'Recipient has no mobile number',
        'disabled_or_unconfigured' => 'Alimtalk is off or settings (template code, sender profile) are empty',
        'unconfigured' => 'Alimtalk account settings are empty',
        'no_test_tmplid' => 'Test template code is empty',
    ],
];
