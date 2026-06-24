<?php

// Buyer replies (Integration A) screen — awaiting-reply vehicles grouped by buyer, accept/reject each.

return [
    'title' => 'Buyer replies',
    'subtitle' => 'Awaiting-reply vehicles grouped by buyer · :accept/:reject each one (a buyer may review several vehicles)',

    'buyer_unassigned' => 'Buyer not assigned',
    'count_awaiting' => ':count awaiting reply',
    'contact' => 'Contact',

    'th_vehicle' => 'Vehicle',
    'th_origin' => 'Origin',
    'th_final_price' => 'Final amount',
    'th_inspection_note' => 'Extra inspection notes',
    'th_process' => 'Process reply',

    'owner_empty' => 'Owner —',

    'accept' => 'Accept',
    'reject' => 'Reject',
    'reject_final' => 'Reject (end deal)',
    'requote' => 'Re-quote (back to forward)',
    'requote_hint' => 'Re-quote is not a rejection. It sends the deal back to the forward queue so you can adjust currency/price and resend. Reject (end deal) cannot be undone.',

    'confirm_accept' => ':vehicle — Mark as buyer accepted? (moves to purchase/auction queue)',
    'confirm_reject' => ':vehicle — End the deal as rejected? (cannot be undone)',
    'confirm_requote' => ':vehicle — Send back to the forward queue to re-quote?',

    'flash_accepted_note' => 'Accepted (moved to purchase/auction queue)',
    'flash_rejected_note' => 'Rejected',
    'flash_processed' => ':vehicle → :verdict processed',
    'flash_requoted' => ':vehicle → sent back to the forward queue. (ready to re-quote)',
    'flash_already' => ':vehicle has already been processed.',

    'empty' => 'No vehicles awaiting reply. (They appear here once forwarded to the buyer during inspection.)',
];
