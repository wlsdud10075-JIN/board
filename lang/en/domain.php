<?php

// Domain-wide labels — status / buyer verdict / source. Shared by model label methods + dropdowns/filters.

return [
    'status' => [
        'draft' => 'Awaiting inspection',
        'awaiting_buyer' => 'Awaiting reply',
        'accepted' => 'Accepted (purchase/auction)',
        'rejected' => 'Rejected',
        'won' => 'Won / Confirmed',
        'failed' => 'Failed / Cancelled',
        'synced' => 'Synced to ERP',
    ],

    'status_live' => [
        'draft' => 'Awaiting inspection',
        'awaiting_buyer' => 'Awaiting reply',
        'accepted_auction' => 'Auction pending',
        'accepted_encar' => 'Purchase pending',
        'rejected' => 'Rejected',
        'won_auction' => 'Won',
        'won_encar' => 'Purchase confirmed',
        'failed_auction' => 'Failed',
        'failed_encar' => 'Cancelled',
        'synced' => 'Synced to ERP',
    ],

    'verdict' => [
        'pending' => 'Awaiting reply',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
    ],

    'origin' => [
        'ssancar_auction' => 'SSANCAR-Auction',
        'ssancar_stock' => 'SSANCAR-Stock',
        'ssancar_checking' => 'SSANCAR-Checking',
        'encar' => 'Encar',
        'auction' => 'Auction',
    ],

    'source' => [
        'encar' => 'Encar',
        'auction' => 'Auction',
    ],
];
