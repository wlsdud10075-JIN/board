<?php

// Sales portal — finance, receivables, purchases, sales, settlement, shipping request, documents.

return [
    // Header
    'title' => 'My Settlement / Receivables / Shipping (Portal)',
    'viewing_other' => "Viewing :name's information — car-erp ledger (read-only). Edits and shipping operations are in car-erp.",
    'viewing_self' => 'Your information only (:name) — car-erp ledger (read-only). Edits and shipping operations are in car-erp.',
    'footer_note' => 'Read-only (car-erp ledger). For edits to amounts, settlement, or shipping operations, contact the car-erp owner. Shipping requests are sent to car-erp managers as an alert.',

    // View by user (super)
    'view_by_user' => 'View by user',
    'view_by_user_hint' => "Click a name to display that user's settlement, receivables, and shipping (system admins only)",
    'view_self_btn' => 'Me',

    // Tabs
    'tab' => [
        'finance' => 'Summary',
        'receivables' => 'Receivables',
        'purchases' => 'Purchases',
        'sales' => 'Sales',
        'settlements' => 'Settlement',
        'shipping' => 'Shipping request',
    ],
    'reload' => 'Refresh',
    'reload_title' => 'Refresh',

    // Shipping request success banner
    'ship_done_title' => 'Shipping request received!',
    'ship_done_body' => ':count vehicle(s) shipping request sent to car-erp.',
    'ship_done_skipped' => '(:count vehicle(s) already requested / not eligible — skipped)',
    'ship_done_alarm' => 'An alert was sent to car-erp managers (export clearance) and shipping has started.',

    // degrade / unavailable
    'unavailable' => 'Unavailable',
    'degrade_403' => 'Your sales account is not linked to car-erp. (Ask an admin to map your car-erp sales email)',
    'degrade_not_configured' => 'The car-erp integration is not set up yet. (Contact an admin)',
    'degrade_default' => 'car-erp information cannot be loaded right now. Please try again shortly.',

    // flash (shipping / documents)
    'flash_view_only_ship' => 'View-only. Submit shipping requests from your own account.',
    'flash_select_vehicle' => 'Select a vehicle.',
    'flash_ship_failed' => 'Failed to send shipping request — please try again shortly.',
    'flash_view_only_docs' => 'View-only. Download documents from your own account.',
    'flash_select_vehicle_docs' => 'Select the vehicle(s) to get documents for.',
    'flash_docs_failed' => 'Could not load documents. (Check the car-erp integration)',

    // Summary (finance) KPIs
    'kpi_unpaid_total' => 'Total receivables',
    'kpi_purchase_unpaid_total' => 'Total purchase unpaid',
    'kpi_settlement_pending' => 'Settlement pending',
    'kpi_fx_missing' => 'FX rate missing',

    // Monthly performance
    'monthly_perf' => 'Monthly performance',
    'monthly_empty' => 'No monthly performance.',
    'monthly_note' => 'Sales amounts are shown as counts (not summed) because currencies are mixed. Settlement and purchases are summed in KRW.',
    'col_month' => 'Month',
    'col_sales_cnt' => 'Sales (count)',
    'col_settle_sum' => 'Settlement payout (KRW)',
    'col_purch_cnt' => 'Purchases (count)',
    'col_purch_sum' => 'Purchase price (KRW)',
    'm_sales' => 'Sales',
    'm_purchase' => 'Purchases',
    'm_settle' => 'Settlement',
    'm_purch_price' => 'Purchase price',

    // Shipping request tab
    'ship_inprogress_title' => 'Shipping requests in progress',
    'ship_status_requested' => 'Requested',
    'ship_status_in_progress' => 'In progress',
    'ship_method_undefined' => 'Method undecided',
    'ship_inprogress_note' => '<b>Requested</b> = received by car-erp managers (export clearance) / <b>In progress</b> = being processed. Removed from the list once shipping and clearance are done.',
    'ship_intro' => 'Group your sold export vehicles <b>by buyer</b> to request RORO/Container shipping. Submitting sends an immediate alert to car-erp managers (export clearance).',
    'buyer_unassigned' => 'Buyer unassigned',
    'buyer_unassigned_paren' => '(Buyer unassigned)',
    'ship_available_count' => ':count vehicle(s) shippable',
    'ship_view_only_note' => 'View-only — submit shipping requests and documents from your own account (:name).',
    'consignee_select' => 'Select consignee',
    'ship_request_btn' => 'Shipping request',
    'docs_label' => 'Documents for selected vehicles (:method):',
    'docs_contract' => 'Contract',
    'docs_invoice_packing' => 'Invoice / Packing',
    'ship_empty' => 'No shippable vehicles. (Only sold, export, not-yet-requested vehicles are shown)',

    // Receivables tab
    'hide_paid' => 'Hide fully paid (0)',
    'recv_empty' => 'No receivables.',
    'recv_empty_hidden' => ' (fully-paid hidden)',
    'fx_missing' => 'FX rate missing',
    'fx_rate_label' => 'FX rate',
    'col_vehicle' => 'Vehicle',
    'col_currency' => 'Currency',
    'col_exchange_rate' => 'FX rate',
    'col_unpaid_krw' => 'Outstanding (KRW)',

    // Sales tab
    'sales_empty' => 'No sales.',
    'sales_detail_empty' => 'No vehicle details',
    'col_sale_price' => 'Sale price',
    'col_sale_date' => 'Sale date',

    // Settlement tab
    'settle_empty' => 'No settlements.',
    'col_buyer' => 'Buyer',
    'col_vehicle_count' => 'Vehicles',
    'col_payout_total' => 'Settlement payout (KRW)',
    'col_payout_paid' => 'Paid (KRW)',
    'lbl_payout_total' => 'Settlement payout',
    'lbl_payout_paid' => 'Paid',

    // Purchases tab
    'purch_empty' => 'No purchases.',
    'col_purchase_price' => 'Purchase price',
    'col_cost_total' => 'Total cost',
    'col_purchase_unpaid' => 'Unpaid',
    'col_purchase_date' => 'Purchase date',

    // Units
    'unit_vehicles' => ':count vehicle(s)',
    'unit_count' => ':count',
    'count_suffix' => '',   // unit suffix after bold number (mobile monthly)

    // Korean abbreviated amount (abbrevKrw) — eok = 10^8, man = 10^4.
    // English has no clean 10^4 suffix, so the man-group keeps a trailing "0K"
    // to stay order-of-magnitude correct (e.g. 436만 -> "436" + "0K" = 4,360K).
    // KPI cards also carry a full-value title= tooltip as a backstop.
    'abbr_eok' => '00M',
    'abbr_man' => '0K',
    'abbr_won' => '',
];
