<?php

// Alimtalk guide (2026-11-23) — automatic recipients + extra recipients per notice. Rules = App\Support\AlimtalkRecipients.
return [
    'title' => 'Alimtalk guide',
    'intro' => 'Checked people receive each Alimtalk. Blue rows are the people tied to that case (who received it automatically until now); below, add or remove by role or by person.',
    'master_off' => 'The Alimtalk master switch is off — turn it on in Settings for messages to actually go out.',

    'state_on' => 'Sending',
    'state_off' => 'Off',
    'state_off_hint' => 'Needs the master switch, this notice\'s switch and its template code in Settings.',
    'show_body' => 'Show message',
    'same_template' => 'Sent with the same message as ":name".',

    'recipients_label' => 'Recipients (checked only)',
    'ctx_hint' => 'whoever it is for each case',
    'not_pickable' => 'Each recipient gets a personal action link, so recipients cannot be changed.',

    'group_all' => 'All :n (newcomers included)',
    'group_some' => ':n selected',
    'group_none' => 'None · :n people',
    'group_empty' => 'No one in this role',
    'no_phone' => 'No number',
    'no_phone_hint' => 'No mobile number in User management — will not receive even if checked.',

    'save' => 'Save',
    'saved' => 'Saved',
    'unsaved' => 'Not saved — press [Save] to apply',
    'extra_count' => ':n people checked by role or person (with a number · blue-row parties vary per case and are not counted)',
    'footnote' => 'People checked by role or person receive every instance of that notice (not filtered by their own vehicles). One person on several rows still gets one message. Unchecking does not stop in-app notifications (bell, web push). Inactive accounts drop out automatically, and changes are written to the audit log.',

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

    'fixed' => [
        'claim_approval_request' => 'The one approver whose turn it is',
        'transport_request' => 'Company numbers + typed number + requesting salesperson + their manager',
    ],

    // People tied to that case — the ones who received it automatically until now (checked by default)
    'ctx' => [
        'inspector' => 'Inspectors assigned to that region',
        'creator' => 'The salesperson who registered the listing',
        'handler' => 'That claim\'s handler',
        'claim_sales' => 'The salesperson who filed that claim',
        'approval_line' => 'Everyone on that claim\'s approval line',
        'transport_sales' => 'The salesperson who requested the transport',
        'transport_manager' => 'That salesperson\'s manager',
    ],
    'ctx_short' => [
        'ctx_inspector' => 'Assigned inspectors',
        'ctx_creator' => 'Registering salesperson',
        'ctx_handler' => 'Handler',
        'ctx_sales' => 'Salesperson (party)',
        'ctx_manager' => 'Their manager',
        'approval_line' => 'Approval line',
    ],

    'group' => [
        'super' => 'System admin',
        'admin' => 'Top manager',
        'manager' => 'Work manager',
        'role_manager' => 'Manager',
        'role_sales' => 'Sales',
        'role_inspection' => 'Inspection',
        'role_auction' => 'Auction',
    ],
];
