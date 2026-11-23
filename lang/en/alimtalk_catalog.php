<?php

// Alimtalk guide (2026-11-23) — automatic recipients + extra recipients per notice. Rules = App\Support\AlimtalkRecipients.
return [
    'title' => 'Alimtalk guide',
    'intro' => 'See who receives each Alimtalk and check extra recipients by role or by person. With nothing checked, only the automatic recipients get it, as before.',
    'master_off' => 'The Alimtalk master switch is off — turn it on in Settings for messages to actually go out.',

    'state_on' => 'Sending',
    'state_off' => 'Off',
    'state_off_hint' => 'Needs the master switch, this notice\'s switch and its template code in Settings.',
    'show_body' => 'Show message',
    'same_template' => 'Sent with the same message as ":name".',

    'auto_label' => 'Automatic recipients (always)',
    'extra_label' => 'Extra recipients (checked only)',
    'not_pickable' => 'Each recipient gets a personal action link, so extra recipients cannot be added.',

    'group_all' => 'All :n (newcomers included)',
    'group_some' => ':n selected',
    'group_none' => 'None · :n people',
    'group_empty' => 'No one in this role',
    'no_phone' => 'No number',
    'no_phone_hint' => 'No mobile number in User management — will not receive even if checked.',
    'line_hint' => 'Everyone on that claim\'s approval line',

    'save' => 'Save',
    'saved' => 'Saved',
    'unsaved' => 'Not saved — press [Save] to apply',
    'extra_count' => ':n extra recipients now (with a number · approval line not counted)',
    'footnote' => 'Checked people receive every instance of that notice (not filtered by their own vehicles). Inactive accounts drop out automatically. Changes are written to the audit log.',

    'notice' => [
        'board_region_inspection' => 'Region inspection notice',
        'board_forward_ready' => 'Ready-to-forward notice',
        'board_claim_approval_request' => 'Claim approval request',
        'board_claim_decision' => 'Claim approval result',
        'board_claim_erp_sent' => 'Claim sent to ERP',
        'board_transport_request' => 'Transport dispatch request',
        'board_transport_result' => 'Transport dispatch result',
    ],

    'when' => [
        'board_region_inspection' => 'Sends the list of vehicles to inspect per region at the scheduled time.',
        'board_forward_ready' => 'Sent when inspection photos/videos arrive and the listing moves to ready-to-forward.',
        'board_claim_approval_request' => 'Sent to the approver whose turn it is, with an approve/reject button.',
        'board_claim_decision' => 'Sent when a claim is finally approved, rejected or self-approved.',
        'board_claim_erp_sent' => 'Sent once more with the approval result when the handler presses [Send to ERP] and it succeeds. Nothing is sent on failure.',
        'board_transport_request' => 'Sent with a dispatch button when a transport dispatch is requested.',
        'board_transport_result' => 'Sent when the transport company accepts or rejects the dispatch.',
    ],

    'auto' => [
        'none' => 'None — only checked people receive it',
        'region_inspection' => 'Inspectors assigned to that region',
        'forward_ready' => 'The salesperson who registered the listing',
        'claim_approval_request' => 'The one approver whose turn it is',
        'claim_decision' => 'Claim handler + the salesperson who filed it',
        'transport_request' => 'Company numbers + typed number + requesting salesperson + their manager',
        'transport_result' => 'Requesting salesperson + their manager',
    ],

    'group' => [
        'approval_line' => 'Whole approval line',
        'super' => 'System admin',
        'admin' => 'Top manager',
        'manager' => 'Work manager',
        'role_manager' => 'Manager',
        'role_sales' => 'Sales',
        'role_inspection' => 'Inspection',
        'role_auction' => 'Auction',
    ],
];
