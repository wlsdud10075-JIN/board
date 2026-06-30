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

    // Shipping & B/L bundles v2
    'ship_sub_bundles' => 'My Bundles',
    'ship_sub_plan' => 'Shipping Plan',
    'ship_status_done' => 'Shipped',
    'ship_status_cancelled' => 'Cancelled',
    'bl_status_requested' => 'B/L requested',
    'bl_status_issued' => 'B/L issued',
    'bl_original' => 'Original',
    'bl_surrender' => 'Surrender',
    'bl_undecided' => 'Undecided',
    'bl_request_label' => 'B/L request:',
    'bl_requested_already' => 'requested: :type',
    'ship_fx_missing' => ':count vehicle(s) missing FX rate — cannot confirm full payment (required before B/L)',
    'ship_fully_paid' => 'Fully paid',
    'ship_unpaid' => 'Unpaid',
    'fx_missing_short' => 'FX missing',
    'change_request_hint' => 'Bundle in progress by management — no auto-change. Request a change/cancel and management will handle it.',
    'change_request_ph' => 'Reason for change/cancel',
    'change_request_btn' => 'Request change',
    'bundles_empty' => 'No shipping bundles. Group vehicles in "Shipping Plan" and sync.',
    'plan_intro' => 'Compose <b>bundles</b> of sold export vehicles and sync at once. Bundle = 1 shipment = 1 B/L.',
    'plan_remove_bundle' => 'Remove bundle',
    'plan_bundle_empty' => 'No vehicles — add below',
    'plan_add_bundle' => 'Add bundle',
    'plan_pool_title' => 'Vehicles to bundle',
    'plan_pool_empty' => 'No vehicles to bundle.',
    'plan_assign_to' => 'Add to bundle…',
    'plan_new_bundle_opt' => 'New bundle',
    'plan_sync_btn' => 'Sync',
    'plan_sync_warn' => 'Syncing applies all bundles shown to car-erp — not-yet-started vehicles removed from a bundle are auto-cancelled.',
    'sync_done_title' => 'Sync complete!',
    'sync_created' => 'Created :count',
    'sync_updated' => 'Updated :count',
    'sync_cancelled' => 'Cancelled :count',
    'sync_locked' => 'In progress (locked) :count',
    'flash_bl_requested' => 'B/L request sent. Management has been alerted.',
    'flash_change_requested' => 'Change request sent. Management will review and handle it.',
    'flash_change_note_required' => 'Please enter a reason for the change/cancel.',
    'flash_sync_blocked_degraded' => 'Bundles could not be loaded, so sync was blocked (prevents mass cancel). Refresh and retry.',

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
