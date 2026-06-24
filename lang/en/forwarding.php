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

    // Photos — send via your own messenger
    'download_button' => 'Download all photos',
    'share_button' => 'Share photos',
    'share_hint' => 'Desktop: download. Mobile: share directly to KakaoTalk/WhatsApp. (Buyer-facing exterior photos only.)',

    'forward_section' => 'Forward to buyer',
    'buyer_placeholder' => 'Buyer name (respond.io contact)',
    'attr_buyer_name' => 'buyer name',
    'forward_button' => 'Mark as sent',
    'forward_hint' => 'Tap once you have sent the photos to the buyer. (Auto channel also sends photos + total) → moves to "Buyer Reply".',
    'flash_forwarded' => 'Forwarded :vehicle to the buyer. (Handle accept/reject on the Buyer Reply screen.)',

    // In-app notification (inspected vehicle arrived)
    'notify' => '🔔 :count inspected — awaiting forward',

    'conflict_title' => 'This buyer already has an auto awaiting-reply vehicle (:vehicle).',
    'conflict_desc' => 'Auto replies are handled one vehicle per buyer at a time. You can forward this one manually.',
    'conflict_manual' => 'Forward manually',
];
