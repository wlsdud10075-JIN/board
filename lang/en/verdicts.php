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

    'confirm_accept' => ':vehicle — Mark as buyer accepted? (moves to purchase/auction queue)',
    'confirm_reject' => ':vehicle — Mark as buyer rejected?',

    'flash_accepted_note' => 'Accepted (moved to purchase/auction queue)',
    'flash_rejected_note' => 'Rejected',
    'flash_processed' => ':vehicle → :verdict processed',
    'flash_already' => ':vehicle has already been processed.',

    'empty' => 'No vehicles awaiting reply. (They appear here once forwarded to the buyer during inspection.)',
];
