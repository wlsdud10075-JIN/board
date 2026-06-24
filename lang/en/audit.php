<?php

// Audit log screen (/audit) — change history (board_audit_logs) + car-erp transmission log (integration_events).

return [
    'title' => 'Audit log',
    'super_only' => 'System administrator only',
    'intro' => '🔒 Change history of amounts, status, and identifiers + car-erp transmission records (append-only). Retained for audit and reconciliation.',

    // Change history table
    'change_history' => 'Change history',
    'col_time' => 'Time',
    'col_changer' => 'Changed by',
    'col_vehicle' => 'Vehicle',
    'col_item' => 'Item',
    'col_change' => 'Change',
    'system' => 'System',
    'no_changes' => 'No changes recorded.',

    // car-erp transmission log table
    'transmission' => 'car-erp transmission',
    'col_direction_target' => 'Direction/Target',
    'col_event' => 'Event',
    'col_response' => 'Response',
    'col_content' => 'Content',
    'no_transmissions' => 'No transmission records.',

    // Field name display in change history (field code → label)
    'field' => [
        'source' => 'Source',
        'status' => 'Status',
        'buyer_verdict' => 'Buyer verdict',
        'buyer_name' => 'Buyer',
        'expected_price' => 'Expected price',
        'final_price' => 'Final amount',
        'car_cost' => 'Car cost',
        'discount_rate' => 'Discount rate',
        'shipping_usd' => 'Shipping',
        'owner_name' => 'Owner',
        'payee_name' => 'Payee',
        'payee_bank' => 'Bank',
        'payee_account' => 'Account',
        'vehicle_number' => 'Vehicle number',
        'vin' => 'VIN',
        'car_erp_vehicle_id' => 'car-erp vehicle',
        'region' => 'Region',
        'inspection_note' => 'Extra inspection',
        'inspection_memo' => 'Memo',
        'c_no' => 'Listing no.',
        'encar_url' => 'Encar URL',
        'encar_dealer' => 'Encar dealer',
        'auction_venue' => 'Auction venue',
        'lot_number' => 'Lot number',
        'deleted' => 'Deleted',
    ],
];
