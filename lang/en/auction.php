<?php

// Auction / purchase screen — user-facing strings.

return [
    'title' => 'Auction / Purchase',
    'subtitle' => '🏁 Only vehicles the buyer has <b>accepted</b> enter here — Auction = won/failed · Encar = purchase confirmed/cancelled · executed at the local total amount',

    'panel_title' => 'Auction / Purchase control panel',
    'accepted_count' => 'Accepted: :count',

    // Table headers
    'col_vehicle' => 'Vehicle',
    'col_source' => 'Source',
    'col_salesman' => 'Salesman',
    'col_final_price' => 'Local total amount',
    'col_process' => 'Process',

    'pending_click' => 'Pending execution · click',
    'pending_tap' => 'Pending execution · tap',
    'empty' => 'No accepted vehicles.',
    'row_click_hint' => '💡 Click a row to view vehicle details.',

    // Mobile card
    'salesman_label' => 'Salesman',
    'region_label' => 'Region',

    // Drawer
    'vin_pending' => '— (NICE lookup pending)',
    'listing_no' => '· Listing :no',

    'car_cost' => 'Car cost',
    'discount_rate' => 'Discount rate',
    'shipping' => 'Shipping',
    'buyer' => 'Buyer',
    'final_price' => 'Local total amount',

    'inspection_memo' => 'Inspection memo',
    'vehicle_photos' => 'Vehicle photos',

    'owner' => 'Owner',
    'owner_hint' => '(owner name · for car-erp VIN lookup)',
    'owner_placeholder' => 'Registered owner name',

    'payment_info' => 'Payment info',
    'payment_info_hint' => '(settlement account · sent to car-erp)',
    'bank_placeholder' => 'Bank',
    'payee_placeholder' => 'Account holder',
    'account_placeholder' => 'Account no. (stored encrypted)',

    'execute' => 'Execute',
    'execute_hint' => 'The payment info above is saved together when marking won / purchase confirmed.',
    'won_auction' => 'Won',
    'won_encar' => 'Purchase confirmed',
    'failed_auction' => 'Failed',
    'failed_encar' => 'Cancelled',
    'save_payment_info' => 'Save payment info',

    // flash
    'flash_payee_saved' => 'Payment info saved.',
    'flash_only_accepted' => 'Only buyer-accepted vehicles can be executed.',
    'flash_processed' => ':no — :label processed.',
];
