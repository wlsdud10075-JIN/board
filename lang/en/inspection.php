<?php

// Inspection screen text.

return [
    'title' => 'Inspection',
    'subtitle_manage' => 'Manager: assign people for the day',
    'subtitle_mine' => 'Showing your assigned regions only',
    'subtitle_flow' => 'Grouped by region · :mode → check vehicle condition → set total amount → forward to buyer',

    // Region assignment panel
    'assign_panel_title' => "Today's region assignment",
    'max_per_region' => 'Up to :max per region',
    'region' => 'Region',
    'region_select' => 'Select region',
    'assignee_inspection' => 'Assignee (Inspection)',
    'assignee_select' => 'Select assignee',
    'assign_button' => '+ Assign',
    'assign_hint' => 'Once a :region is set on pending vehicles, you can assign here. (Enter the region under Listings)',
    'assign_hint_region_word' => 'region',
    'max_per_region_error' => 'You can assign up to :max people per region.',
    'only_inspection_assignable' => 'Only inspection staff can be assigned.',
    'assigned_ok' => 'Assigned.',

    // Assignment summary table
    'col_region' => 'Region',
    'col_people' => 'Assigned people',
    'col_cars' => 'Vehicles',
    'unassigned' => 'Unassigned',
    'cars_count' => ':count',

    // Region vehicle list
    'region_unset' => 'No region set',
    'items_count' => ':count',
    'no_assignment_label' => 'Unassigned',
    'final_amount_prefix' => 'Total :amount KRW',
    'amount_undecided' => 'Amount TBD',
    'empty_for_manager' => 'No vehicles to inspect.',
    'empty_for_inspector' => 'No region assigned today. (Awaiting manager assignment)',

    // Drawer
    'drawer_title' => 'Inspection',
    'expected_price_line' => 'Expected price :price · set total amount after checking condition',

    // Photos / video
    'photos_section' => 'Vehicle photos/video (exterior only · excl. documents/plate)',
    'photo_upload_label' => 'Take with rear camera / upload photo·video',
    'uploading' => 'Uploading…',
    'new_files_count' => ':count new file(s) — applied on save',
    'share_to_buyer' => 'Share to buyer',
    'share_to_buyer_on' => '✓ Shared to buyer',
    'photo_share_hint' => 'Turn on "Share to buyer" for :exterior to send to the buyer (excl. documents/plate). Sent automatically with the USD amount on forward.',
    'photo_share_hint_exterior' => 'exterior photos/videos only',

    // Inspection region
    'inspection_region_section' => 'Inspection region',
    'region_placeholder' => 'e.g. Suwon, Gyeonggi (autocomplete as you type)',

    // Memo
    'memo_section' => 'Condition memo',
    'memo_placeholder' => 'e.g. driver seat wear, minor front bumper scratch',

    // Inspection note
    'note_section' => 'Inspection note',
    'note_placeholder' => 'e.g. warranty missing, tire replacement recommended',

    // Pricing
    'pricing_section' => 'Pricing',
    'car_cost_label' => 'Car cost (:symbol)',
    'car_cost_placeholder' => '13000000',
    'discount_rate_label' => 'Discount rate (%)',
    'sales_fee_label' => '＋ Sales fee (fixed)',
    'car_price_label' => 'Car Price',
    'shipping_label' => 'Shipping (USD fixed)',
    'shipping_line' => 'Shipping :amount',
    'total_label' => 'Total',
    'shipping_rate_note' => 'Shipping $:usd × :rate KRW applied',

    // Forward to buyer
    'forward_section' => 'Forward to buyer',
    'buyer_name_placeholder' => 'Buyer name (respond.io contact)',
    'forward_button' => 'Forward photos + total amount to buyer',
    'forward_button_selected' => '— Selected ✓',
    'forward_hint' => 'Select, then press :save below to forward. After forwarding, handle the buyer reply on the :verdicts screen.',
    'forward_hint_save' => 'Save',
    'forward_hint_verdicts' => '"Buyer Reply"',

    // (a) guard: same-buyer auto-pending conflict
    'conflict_title' => 'This buyer already has an :vehicle.',
    'conflict_auto_word' => 'auto-pending vehicle (:vehicle)',
    'conflict_desc' => 'Auto reply handles one vehicle at a time. What would you like to do?',
    'conflict_wait' => 'Proceed after the prior vehicle (wait)',
    'conflict_manual' => 'Switch to manual and forward',
    'conflict_manual_note' => '※ With "switch to manual", this vehicle is handled directly on the Buyer Reply screen.',

    'already_forwarded' => 'Already forwarded to buyer (awaiting reply). Handle accept/reject on the :verdicts screen.',
    'already_forwarded_verdicts' => '"Buyer Reply"',

    // Validation / flash messages
    'attr_region' => 'region',
    'attr_assignee' => 'assignee',
    'attr_buyer_name' => 'buyer name',
    'need_amount_to_forward' => 'You must enter a car cost (or total amount) to forward to the buyer.',
    'saved_ok' => 'Saved.',
    'forwarded_manual' => 'Forwarded to buyer (manual reply — handle on the Buyer Reply screen).',
    'forwarded_auto' => 'Forwarded to buyer (awaiting auto reply — processed automatically on respond.io reply).',
    'forward_held' => 'Forwarding held. Forward again after handling the prior vehicle reply. (Inputs saved)',
];
