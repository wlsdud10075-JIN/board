<?php

return [
    // Header
    'heading' => 'Purchases (Sales)',
    'own_only_note' => '🔒 Showing only your (:name) list — server/DB-level isolation',

    // Exchange rate
    'rate' => [
        'label' => '💱 Applied rate',
        'live' => 'LIVE',
        'temp' => 'Fallback',
        'refresh_title' => 'Refresh rate',
        'usd_line' => 'USD 1 = :amount KRW',
        'eur_line' => 'EUR 1 = :amount KRW',
        'as_of' => 'as of :time',
        'refreshed' => 'Exchange rate refreshed.',
    ],

    // TimeGate notice
    'timegate' => [
        'auction_closed' => '🔨 Auction registration closed (after :time · admin unlock required)',
        'auction_open' => '🔨 Auction registration open (closes :time)',
        'encar_always' => '· 🛒 Encar can be registered anytime',
    ],

    // Promotion queue
    'promo' => [
        'heading' => '📥 Promotion queue',
        'count' => ':count',
        'intro' => 'A buyer asked to be handled on the board via chat. Click “Promote” and the contact is linked automatically — just enter the link and vehicle number.',
        'contact_fallback' => 'Contact :id',
        'contact_meta' => 'Contact :id',
        'assignee' => 'Assignee: :name',
        'unassigned' => 'Unassigned',
        'promote' => 'Promote',
        'dismiss' => 'Dismiss',
        'dismiss_confirm' => 'Dismiss this promotion request?',
        'promoted_flash' => '[:label] Buyer linked — just enter the link and vehicle number.',
    ],

    // List header
    'list' => [
        'heading' => 'My purchase list',
        'count' => ':count',
        'add' => '+ Add purchase',
        'empty' => 'No purchases yet. Use “+ Add purchase” to register one.',
        'row_hint' => '💡 Click a row to view and edit (except time-locked auction vehicles).',
    ],

    // Table headers
    'table' => [
        'vehicle' => 'Vehicle',
        'source' => 'Source',
        'total' => 'Total',
        'inspection_note' => 'Inspection note',
        'buyer' => 'Buyer',
        'status' => 'Status',
    ],

    // Add form
    'add_form' => [
        'origin_label' => 'Source (origin category)',
        'method_prefix' => '💡 Purchase method: ',
        'method_auction' => 'Auction (time-locked · won/failed)',
        'method_encar' => 'Encar instant buy (pending/confirmed)',
        'method_suffix' => ' — determined automatically by category',
        'vehicle_number' => 'Vehicle no.',
        'vehicle_number_ph' => '12GA3456',
        'owner' => 'Owner',
        'owner_hint' => '(owner name)',
        'owner_ph' => 'Registered owner name',
        'car_cost' => 'Car cost',
        'car_cost_ph' => '13000000',
        'discount_rate' => 'Discount rate (%)',
        'discount_rate_ph' => '0',
        'region' => 'Region',
        'region_ph' => 'Type Suwon → autocomplete',
        'c_no' => 'Listing no.',
        'c_no_hint' => '(c_no)',
        'c_no_ph' => 'Auto-filled from link',
        'auction_venue' => 'Auction venue',
        'auction_venue_ph' => 'Lotte / Hyundai Glovis',
        'lot_number' => 'Lot number',
        'lot_number_ph' => 'A-1024',
        'note' => '<b>Vehicle no.</b> is required. Amounts are optional and may be adjusted after the on-site condition check.',
        'saved_flash' => 'Purchase registered.',
        'dup_error' => 'This vehicle number is already registered (#:id).',
        'auction_locked_error' => 'Auction vehicle registration closed at :time. Admin unlock is required.',
        'attr_vehicle_number' => 'Vehicle no.',
        'attr_vin' => 'VIN',
    ],

    // Link extraction / listing price
    'links' => [
        'encar_label' => '🔗 Encar link',
        'encar_hint' => '(auto: vehicle no. · car cost · region · VIN)',
        'encar_ph' => 'https://fem.encar.com/cars/detail/42176484',
        'ssancar_label' => '🔗 SSANCAR link',
        'ssancar_hint' => '(vehicle no. · VIN · Encar price for inspected listings)',
        'ssancar_ph' => 'https://www.ssancar.com/...?c_no= / ?wr_id=',
        'extract' => 'Extract',
        'extracted' => 'Extracted:',
        'parse_error' => 'Could not find an identifier in the link. (Enter manually instead.)',
        'price_label' => 'Listing price (= car cost)',
        'price_hint' => '(auto on link extraction · select currency)',
        'price_ph' => 'Auto-filled from link · can enter manually',
        'price_options_prefix' => '💱 Listing price: ',
        'price_options_suffix' => '— select currency with the buttons (amount changes automatically)',
        'price_help' => '💡 Encar = KRW / SSANCAR = 3 currencies, automatic. The amount in the selected currency goes into “Car cost” below as-is (foreign currency kept · editable). Conversion happens only in Pricing.',
        'contact_label' => 'respond.io contact ID',
        'contact_hint' => '(optional · buyer identifier · auto-verdict matching key)',
        'contact_ph' => 'respond.io buyer contact ID (e.g. 469733036)',
        'enrich_category' => '[:cat] extracted: ',
        'enrich_name' => ' · model: :name',
        'enrich_auto' => ' · auto-filled: :fields',
        'enrich_suffix' => ' — review and save.',
        'fill_vehicle_number' => 'Vehicle no.',
        'fill_price' => 'Listing price (:currencies)',
        'fill_car_cost' => 'Car cost (:currency)',
        'fill_region' => 'Region',
        'fill_vin' => 'VIN',
    ],

    // Pricing
    'pricing' => [
        'heading' => 'Pricing',
        'sales_fee' => '＋ Sales fee (fixed)',
        'car_price' => 'Vehicle amount (Car Price)',
        'car_price_short' => 'Vehicle amount',
        'shipping_label' => 'Shipping (USD fixed)',
        'shipping_prefix' => 'Shipping ',
        'total' => 'Total',
        'total_short' => 'Total',
    ],

    // Payment info
    'payee' => [
        'label' => 'Payment info',
        'hint' => '(optional · settlement account)',
        'bank_ph' => 'Bank',
        'name_ph' => 'Account holder',
        'account_ph' => 'Account no.',
        'help' => '💡 Enter now if known → shown automatically at the purchase stage. Leave blank for the buyer to fill. (Auto hyphens by bank · account number encrypted)',
    ],

    // Attachments
    'attach' => [
        'add_label' => 'Vehicle attachments',
        'add_hint' => '(photos · documents · Excel etc. · up to :max · auto-registered to car-erp on win)',
        'dropzone' => '📎 Choose files (multiple allowed · all except executables)',
        'uploading' => 'Uploading…',
        'selected' => ':count selected — applied on save',
        'help' => '💡 Upload vehicle photos/documents received by sales → auto-registered to car-erp after winning → manager reviews and supplements. (Images = photos / others = documents, automatically; only executables blocked)',
        'exec_error' => 'Executable files (.exe etc.) cannot be uploaded: :name',
        'max_error' => 'Up to :max attachments allowed. (Currently :existing)',
        // Drawer
        'drawer_label' => 'Vehicle attachments',
        'drawer_hint' => '(photos · documents · up to :max)',
        'drawer_empty' => 'No attachments yet.',
        'drawer_add' => '📎 Add files (photos · documents · Excel etc. · except executables)',
        'delete_confirm' => 'Delete this attachment?',
    ],

    // Edit drawer
    'drawer' => [
        'title' => ':number · Edit purchase',
        'summary_locked' => 'Identifiers (vehicle no. · VIN) and source cannot be edited',
        'method_auction' => 'Auction',
        'method_encar' => 'Encar instant buy',
        'locked_notice' => '🔒 This is a time-locked auction vehicle. Contact an admin to edit.',
        'locked_error' => 'Time-locked auction vehicles cannot be edited. (Contact admin)',
        'encar_url' => 'Encar listing URL',
        'encar_url_ph' => 'https://encar.com/...',
        'c_no_ph' => 'e.g. 6797296',
        'contact_label' => 'respond.io contact ID',
        'contact_hint' => '(buyer identifier · auto-verdict matching key)',
        'contact_ph' => 'respond.io buyer contact ID',
        'origin_prefix' => 'Origin:',
        'local_total' => 'On-site total',
        'local_total_pending' => '— (after inspection)',
        'status' => 'Status',
        'buyer' => 'Buyer',
        'buyer_name' => 'Buyer name',
        'updated_flash' => ':number updated.',
    ],
];
