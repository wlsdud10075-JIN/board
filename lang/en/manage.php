<?php

// Admin (manage) screen — visible text.

return [
    // Header
    'title' => 'Admin',
    'subtitle_html' => '✏️ Edit regardless of time lock (expected/total amount, source, status) — but <b>vehicle no. and VIN cannot be changed</b>. All changes are logged to the audit log.',

    // KPI cards
    'kpi_today' => 'Purchases today',
    'kpi_encar' => 'Encar',
    'kpi_auction' => 'Auction',
    'kpi_accepted' => 'Buyer accepted',
    'kpi_won' => 'Awaiting ERP sync',

    // Overview
    'overview' => 'Overview',
    'count_suffix' => '',
    'clear_filters' => 'Clear filters ✕',
    'search_placeholder' => '🔍 Vehicle no. / listing no. / owner',
    'status_all' => 'All statuses',
    'source_all' => 'All sources',
    'verdict_all' => 'All buyer verdicts',
    'verdict_pending' => 'Awaiting reply',
    'verdict_accepted' => 'Accepted',
    'verdict_rejected' => 'Rejected',

    // Source
    'source_encar' => 'Encar',
    'source_auction' => 'Auction',

    // Table headers
    'th_vehicle' => 'Vehicle',
    'th_source' => 'Source',
    'th_sales' => 'Sales',
    'th_expected' => 'Expected',
    'th_total' => 'Total amount',
    'th_buyer' => 'Buyer',
    'th_status' => 'Status',
    'empty' => 'No data matches the filters.',

    // Mobile cards
    'card_sales' => 'Sales',
    'card_expected' => 'Expected',
    'card_total' => 'Total',

    // Edit drawer
    'edit_suffix' => 'Edit',
    'vehicle_number' => 'Vehicle no.',
    'vehicle_number_hint' => '· typo correction allowed',
    'vin' => 'VIN',
    'identity_locked_html' => '🔗 Already synced to car-erp — identity fields cannot be edited',
    'identity_vehicle' => 'Vehicle no.',
    'identity_vin' => 'VIN',
    'owner_name' => 'Owner (name)',
    'c_no' => 'Listing no. (c_no)',
    'source' => 'Source',
    'region' => 'Region',
    'region_placeholder' => 'Inspection region',
    'car_cost' => 'Car cost',
    'discount_rate' => 'Discount %',
    'shipping_usd' => 'Shipping $',
    'expected_price' => 'Expected price',
    'final_price' => 'Local total amount',
    'status' => 'Status',
    'buyer_verdict' => 'Buyer verdict',
    'verdict_none' => 'None',
    'buyer_name' => 'Buyer name',
    'inspection_memo' => 'Memo (vehicle condition)',
    'inspection_note' => 'Inspection note',
    'encar_url' => 'Encar listing URL',
    'encar_dealer' => 'Encar dealer',
    'auction_venue' => 'Auction house',
    'lot_number' => 'Lot number',

    // Payment info
    'payment_info' => 'Payment info',
    'payment_info_sub' => '(settlement account)',
    'payee_bank' => 'Bank',
    'payee_name' => 'Account holder',
    'payee_account' => 'Account no. (encrypted)',

    // Actions
    'save' => 'Save (logged to audit)',

    // flash
    'saved' => ':vehicle updated — changes recorded in the audit log.',
];
