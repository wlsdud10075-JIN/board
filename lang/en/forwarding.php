<?php

// Awaiting forward — sales forwards inspected vehicles to the buyer

return [
    'title' => 'Awaiting forward',
    'subtitle' => '🔍 Get the inspected vehicle\'s photos (download/share), send them to the buyer, then tap "Mark as sent" to move it to "Buyer Reply".',
    'panel_title' => 'Inspected · awaiting forward',
    'count' => ':count waiting',
    'empty' => 'No vehicles awaiting forward. (Inspected vehicles appear here.)',

    'th_vehicle' => 'Vehicle',
    'th_origin' => 'Origin',
    'th_final_price' => 'Total',
    'th_inspection_note' => 'Extra notes',

    // Progress badges (inspected → photos ready)
    'badge_inspected' => 'Inspected',
    'badge_no_photos' => 'No photos',

    // Amount editing (re-quote / adjust) — auto-saves & recalculates on edit
    'amount_section' => 'Amount (auto-applies on edit)',
    'amount_hint' => 'If the buyer asks for a discount, just edit car price / discount / shipping. Leaving a field auto-saves and recalculates the total & quote card. (For foreign-currency deals, re-tap the currency button above to re-fix the conversion.)',

    // Quote card — currency/amounts to send to the buyer
    'quote_section' => 'Quote currency',
    'quote_car' => 'Car Price',
    'quote_shipping' => 'Shipping',
    'quote_total' => 'Total',
    'quote_unset' => 'Amount not set — price under negotiation (photos only, no card).',

    // Photos — send via your own messenger
    'share_button' => 'Share all :count photos',
    'share_card_only' => 'Share quote card',
    'share_hint' => 'Send all inspection photos at once to KakaoTalk/WhatsApp etc. For a single photo, tap it then long-press.',
    'share_hint_quote' => 'Sends one quote card + all inspection photos at once to KakaoTalk/WhatsApp etc. Change currency with the toggle above.',

    // Videos — too large for the share sheet, so sent separately as a presigned link
    'video_section' => 'Videos (send as link)',
    'video_send' => 'Send',
    'video_hint' => 'Videos are too large to share like photos. Tap to put the video link on the share sheet/clipboard, then paste it into KakaoTalk etc. (Link valid 60 min.)',

    'forward_section' => 'Forward to buyer',
    'buyer_placeholder' => 'Buyer name (optional)',
    'attr_buyer_name' => 'buyer name',
    'forward_button' => 'Mark as sent',
    'forward_hint' => 'Tap once you have sent the photos to the buyer. (Auto channel also sends photos + total) → moves to "Buyer Reply".',
    'flash_forwarded' => 'Forwarded :vehicle to the buyer. (Handle accept/reject on the Buyer Reply screen.)',

    // In-app notification (inspected vehicle arrived)
    'notify' => '🔔 :count inspected — awaiting forward',
    'notify_synced' => ':count synced to car-erp — ERP transfer complete',

    'conflict_title' => 'This buyer already has an auto awaiting-reply vehicle (:vehicle).',
    'conflict_desc' => 'Auto replies are handled one vehicle per buyer at a time. You can forward this one manually.',
    'conflict_manual' => 'Forward manually',
];
