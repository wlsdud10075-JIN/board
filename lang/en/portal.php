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
        'inventory' => 'Inventory',
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

    // §11 request/ack signals. Never add amount wording here (§11-2).
    'req_purchase_btn' => 'Request payment',
    'req_deposit_btn' => 'Deposit',
    'req_balance_btn' => 'Balance',
    'req_amount_ph' => 'Amount (KRW)',
    'req_amount_required' => 'Enter the amount to send — a request cannot be sent without it.',
    'req_sale_btn' => 'Confirm sale payment',
    'req_sale_btn_n' => 'Confirm sale payment (:count)',
    'req_note_ph' => 'Note (optional) — e.g. transfer of 5/12',
    'req_blocked_viewing' => 'Viewing another user\'s portal — cannot send (read-only).',
    'req_blocked_unconfigured' => 'car-erp connection is not configured — cannot send.',
    'req_blocked_no_vehicle_id' => 'Cannot send — ERP did not provide a vehicle id for this row.',
    'req_blocked_short' => 'Cannot send',
    'req_pick_required' => 'Select at least one vehicle.',
    'req_send_failed' => 'Send failed (HTTP :status) — nothing was sent.',
    'req_result_created' => 'Sent',
    'req_result_skipped' => 'Skipped',
    'req_reason_already_open' => 'already requested',
    'req_reason_forbidden' => 'not your vehicle',
    'req_dismiss' => 'Dismiss',
    'req_chip_open' => 'Requested',
    'req_chip_done' => 'Confirmed',
    'req_chip_cancelled' => 'Cancelled',
    'req_chip_progress' => ':done/:total confirmed',
    'req_chip_unavailable' => 'Could not load request status — an empty status below does not mean "not requested".',
    'flash_view_only_docs' => 'View-only. Download documents from your own account.',
    'flash_select_vehicle_docs' => 'Select the vehicle(s) to get documents for.',
    'flash_docs_failed' => 'Could not load documents. (Check the car-erp integration)',
    'flash_docs_sales_contract_failed' => 'Could not issue the sales contract. Only vehicles with the same buyer and single currency can be issued together. (Check bundle composition / integration)',
    // car-erp 403 = this document type is not opened to board yet (BOARD_ALLOWED_TYPES). Not a bundle problem.
    'flash_docs_not_allowed' => 'This document cannot be downloaded from board yet — car-erp must allow this type first. (Not a bundle composition issue)',
    // 422 = car-erp homogeneity guard (HOMOGENEOUS_TYPES). Shared by sales contract and proforma invoice.
    'flash_docs_homogeneous_required' => 'This document can only be issued for vehicles with the same buyer and a single currency. Check whether the bundle mixes buyers or currencies.',
    'flash_sign_failed' => 'Could not issue the e-signature session. Please try again shortly. (Check the car-erp integration)',

    // §10 E-signature request (ERP issues → board only relays the signing URL)
    'sign_request_btn' => 'Request E-Signature',
    'sign_request_confirm' => 'Issue an e-signature session for this bundle\'s sales contract. Send the generated signing link to the buyer.',
    'sign_ready_title' => 'Signing link issued',
    'sign_ready_hint' => 'Copy the link below and send it to the buyer yourself (KakaoTalk / SNS / email). The buyer completes signing on this link.',
    'sign_copy_btn' => 'Copy',
    'sign_copied' => 'Copied',
    'sign_expires' => 'Expires: :at',
    // Signing status chip (§10-2 polling) — none=request / pending·viewed=waiting / signed=done
    'sign_st_pending' => 'Awaiting signature',
    'sign_st_signed' => 'Signed',
    'sign_reissue_btn' => 'Re-request',
    'sign_reissue_confirm' => 'Re-issue the signing link? The existing unsigned link is revoked and a new one is created. Continue?',

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
    // ⚠️ Document names are copied verbatim from car-erp `vehicle.shipdoc.*` (Jin, 2026-08-01).
    //    Do not invent or reword them here — divergent names make the doc look absent in ERP.
    'docs_roro_contract' => 'RORO Contract',
    'docs_container_contract' => 'Container Contract',
    'docs_roro_invoice_packing' => 'RORO Invoice & Packing',
    'docs_container_invoice_packing' => 'Container Invoice & Packing',
    'docs_sales_contract' => 'Sales Contract',
    'docs_proforma_invoice' => 'Proforma Invoice',   // car-erp type 'invoice' — distinct from shipping Invoice & Packing
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
    'bl_confirm' => 'Request :type B/L? Management will be alerted; to undo you must ask management to cancel.',
    'bl_requested_already' => 'requested: :type',
    'bl_cancel_btn' => 'Undo B/L request',
    'bl_cancel_confirm' => 'Undo the B/L request? (only possible before management issues it)',
    'flash_bl_cancelled' => 'B/L request has been undone.',
    'flash_bl_already_issued' => 'Management already issued the B/L; cannot undo.',
    'ship_fx_missing' => ':count vehicle(s) missing FX rate — cannot confirm full payment (required before B/L)',
    'ship_fully_paid' => 'Fully paid',
    'ship_unpaid' => 'Unpaid',
    'fx_missing_short' => 'FX missing',
    'change_request_hint' => 'Bundle in progress by management — no auto-change. Request a change/cancel and management will handle it.',
    'change_request_ph' => 'Reason for change/cancel',
    'change_request_btn' => 'Request change/cancel',
    'cancel_bundle_btn' => 'Cancel shipment',
    'cancel_bundle_confirm' => 'Cancel this shipment (request)? It will be auto-cancelled in car-erp.',
    'bundles_empty' => 'No shipping bundles. Group vehicles in "Shipping Plan" and sync.',
    'plan_intro' => 'Compose <b>bundles</b> of sold export vehicles and sync at once. Bundle = 1 shipment = 1 B/L.<br><b>Vehicles with an outstanding balance can be bundled in advance</b> — this is for preparing documents; actual shipping starts once payment arrives.',
    'plan_remove_bundle' => 'Remove bundle',
    'plan_bundle_empty' => 'No vehicles — add below',
    'plan_add_bundle' => 'Add bundle',
    'plan_shipment' => 'Shipment',
    'plan_shipment_n' => 'Shipment #:n',
    'plan_add_shipment' => 'Add shipment',
    'plan_no_cars' => 'No cars to add',
    'plan_no_buyers' => 'No vehicles to plan. (sold, export)',
    'plan_pool_title' => 'Vehicles to bundle',
    'forwarder_select' => 'Forwarder',
    'plan_no_buyer_cars' => ':count vehicle(s) without an assigned buyer are not shown here — bundles are per buyer, so assign the buyer in car-erp first.',
    'plan_freight_ph' => 'Freight $',
    'plan_freight_note' => 'USD · split per vehicle',
    'plan_unpaid' => 'Unpaid',
    'plan_fx_missing' => 'No FX rate',
    'plan_fx_missing_hint' => 'Payment status cannot be determined without an exchange rate — this does not mean fully paid.',
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
    'flash_sync_incomplete_buyer' => 'An existing bundle is missing buyer_id in the response, so sync was blocked (prevents mass cancel). Check car-erp integration.',

    // Receivables tab
    'hide_paid' => 'Hide fully paid (0)',
    'recv_empty' => 'No receivables.',
    'recv_empty_hidden' => ' (fully-paid hidden)',
    'fx_missing' => 'FX rate missing',
    'fx_rate_label' => 'FX rate',
    'recv_unpaid_pct' => ':pct% unpaid',
    'recv_fx_excluded' => ':count no FX',
    'col_vehicle' => 'Vehicle',
    'col_currency' => 'Currency',
    'col_exchange_rate' => 'FX rate',
    'col_unpaid_krw' => 'Outstanding',

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

    // Inventory 4 categories + sales status filter (2026-08-09)
    'inv_cat_awaiting_payment' => 'Awaiting payment',
    'inv_cat_general' => 'General stock',
    'inv_cat_pre_ship' => 'Pre-shipment stock',
    'inv_cat_shipped_out' => 'Shipped out',
    'inv_hint_awaiting_payment' => 'Vehicles with an outstanding purchase balance — these are the payment-request targets. They move into stock once paid.',
    'inv_hint_general' => 'Speculative purchases with no buyer yet (fully paid, not yet shipped out)',
    'inv_hint_pre_ship' => 'Sold vehicles, not yet shipped out',
    'inv_hint_shipped_out' => 'Vehicles with a warehouse-out date, newest first. This set keeps growing, so only the latest 30 load first. Vessel names are searchable too, so you can pull up everything loaded on one ship.',
    'inv_search_ph' => 'Search vehicle no. / VIN / vessel / buyer',
    'inv_empty' => 'No matching vehicles.',
    'inv_more' => 'Load more (:shown/:total)',
    'col_progress' => 'Progress',
    'col_location' => 'Location',
    'hide_done_sales' => 'Hide completed deals',
    'buyer_search_ph' => 'Search buyer',
    'buyer_search_empty' => 'No buyer matches your search.',

    // §12 sailing (2026-08-09) — an axis orthogonal to progress status. Chip labels come straight from the
    // ERP (`sailing_status`), so only board's own UI strings live here.
    // ⚠️ Never shorten to "Arrived" — it only means the ETA has passed, not that arrival was confirmed.
    'sailing_filter_label' => 'Sailing',
    'sailing_all' => 'All',
    'sailing_in_transit' => '🚢 In transit',
    'sailing_arrived' => '⚓ ETA passed',
    'sailing_totals_unfiltered' => 'The sailing filter applies to the vehicle rows only — buyer totals still cover everything.',
];
