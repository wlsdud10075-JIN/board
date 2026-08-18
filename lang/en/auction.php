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
    // Car cost entry (2026-08-10) — the only place a self-inspected purchase can enter an amount.
    'amount_section' => 'Amounts (the buyer figure is derived here)',
    'quote_currency' => 'Quote currency',
    // Self-inspected purchases (2026-08-10) — no quote scene, so nothing to derive these from.
    'selling_fee' => 'Selling fee',
    'sale_price' => 'Sale price',
    'offer_rate' => 'Exchange rate',
    'transport_fee' => 'Freight',
    'self_currency_hint' => 'Sale price, rate and freight are in :currency (chosen below). Car cost and selling fee are always KRW.',
    'self_currency_unset' => '⚠️ No quote currency picked yet — choose one below. Sale price, rate and freight will be in that currency.',
    'self_amount_hint' => 'ERP purchase price = car cost − selling fee = :purchase KRW. The fee is already inside the car cost, so it is split out (the total is unchanged).',
    'car_cost_ph' => 'e.g. 12000000',
    'car_cost_hint' => 'Car cost, discount, deduction and shipping produce the final buyer figure below. Confirming the purchase is blocked while car cost is empty.',
    'car_cost_missing' => '⚠️ Car cost is empty — the ERP transfer needs it.',
    'err_amount_required' => 'Enter the car cost before confirming the purchase. Without an amount it never reaches the ERP.',
    'err_sale_price_required' => 'Enter the sale price before confirming the purchase. Leaving it empty creates the vehicle in the ERP with no sale data.',
    // Purchase registration lock (car-erp §4-0, 2026-08-10) — not an absolute rule; an ERP admin can clear it.
    'buyer_lock_title' => 'Purchase registration is blocked for this buyer',
    'buyer_lock_unsecured' => 'Unsecured balance :current KRW / limit :limit KRW',
    'buyer_lock_ratio' => 'Unpaid ratio :current% / threshold :limit%',
    'buyer_lock_notice' => 'An ERP administrator has to approve it. Try again once they have.',
    'err_attachment_required' => 'Attach at least one vehicle photo or document before confirming — they are sent to the car-erp attachment tab on confirmation.',
    'err_buyer_required' => 'Pick a buyer before confirming the purchase.',
    'err_buyer_purchase_locked' => 'Purchase registration is blocked for this buyer — an ERP administrator has to approve it first.',
    'err_currency_required' => 'Pick a quote currency. Without one the sale price is recorded as KRW (8,590 USD becomes 8,590 KRW).',
    'err_rate_required' => 'Enter the exchange rate. Left empty it records the current rate instead of the agreed one.',
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
    'selling_fee_info' => 'Selling fee account',
    'selling_fee_info_hint' => '(different party from seller · sent to car-erp)',
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
    'flash_resent' => ':no saved — re-sent to the ERP. It flips to synced once processed.',
    'flash_only_accepted' => 'Only buyer-accepted vehicles can be executed.',
    'flash_processed' => ':no — :label processed.',

    // Buyer / consignee dropdown (car-erp list)
    'buyer' => 'Buyer / Consignee',
    'buyer_hint' => 'Select from car-erp list (your accounts)',
    'buyer_unavailable' => 'Buyer list unavailable — assign manually in car-erp.',
    'buyer_select' => 'Select buyer',
    'consignee_select' => 'Select consignee (optional)',

    'attach' => [
        'title' => 'Dealer vehicle attachments',
        'hint' => 'Photos/documents received from the dealer after purchase (max :max). Sent to the car-erp attachments tab on win.',
        'dropzone' => '＋ Add photos/documents (tap)',
        'uploading' => 'Uploading…',
        'delete_confirm' => 'Delete this attachment?',
        'help' => 'Images = photos / others = documents (auto-classified). Documents are not sent to the buyer.',
        'exec_error' => 'Executable files cannot be attached (:name).',
        'max_error' => 'Up to :max attachments (:existing existing).',
    ],
];
