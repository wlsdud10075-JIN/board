<?php

return [
    'group' => [
        'work' => 'Work',
        'manage' => 'Management',
        'system' => 'System',
    ],

    'menu' => [
        'listings' => 'Purchases (Invoice)',
        'forwarding' => 'Awaiting forward',
        'verdicts' => 'Buyer Replies',
        'portal' => 'My Finance (Portal)',
        'inspection' => 'Inspection',
        'auction' => 'Auction / Purchase',
        'manage' => 'Admin',
        'users' => 'User Management',
        'audit' => 'Audit Log',
        'settings' => 'Feature Settings',
    ],

    'crumb' => [
        'dashboard' => 'Dashboard',
        'listings' => 'Purchases (Invoice)',
        'forwarding' => 'Awaiting forward',
        'verdicts' => 'Buyer Replies',
        'portal' => 'Finance · Receivables · Shipping',
        'inspection' => 'Inspection',
        'auction' => 'Auction / Purchase',
        'manage' => 'Admin',
        'users' => 'User Management',
        'audit' => 'Audit Log',
        'settings_profile' => 'My Settings',
        'settings_password' => 'Password',
        'settings_appearance' => 'Appearance',
        'admin_settings' => 'Feature Settings',
    ],

    'role' => [
        'sales' => 'Sales',
        'inspection' => 'Inspection',
        'auction' => 'Auction',
        'manager' => 'Management',
    ],

    'perm' => [
        'super' => 'System Admin',
        'manager' => 'Operations Manager',
    ],

    'lang' => [
        'label' => 'Language',
        'ko' => '한국어',
        'en' => 'English',
    ],

    'action' => [
        'work_guide' => 'Work Guide',
        'my_settings' => 'My Settings',
        'logout' => 'Log Out',
    ],

    'brand_sub' => 'Purchase · Inspection · Auction',

    'bell' => [
        'title' => 'Request alerts',
        'aria' => 'Open request alerts',
        'results' => 'Results',
        'in_progress' => 'In progress',
        'empty_results' => 'No results yet.',
        'empty_in_progress' => 'No requests in progress.',
        'unavailable' => 'Could not load request status from ERP — an empty list does not mean "no requests".',
        'new' => 'New',
        'resolved_by' => 'by :name',
        'requested_at' => 'requested :at',
        'holds' => 'Inspection on hold',
        'hold_chip' => 'On hold',
        'toast_hold' => '🔔 :count inspection(s) put on hold — open the bell for the reason',
        'push_title_hold' => '🔔 Inspection on hold',
        // Urgent inspection decisions (P1, 2026-10-29) — approved/rejected by a ssancar.com work manager
        'urgents' => 'Urgent inspection decisions',
        'urgent_chip_approved' => 'Approved',
        'urgent_chip_rejected' => 'Rejected',
        'urgent_approved_body' => 'Urgent approved — inspection on :date (:by)',
        'urgent_rejected_body' => 'Urgent rejected (:by):note — scheduled date unchanged, you can request again',
        'toast_urgent_approved' => '✅ :count urgent inspection(s) approved',
        'toast_urgent_rejected' => '🔔 :count urgent inspection(s) rejected — open the bell for the reason',
        'push_title_urgent_approved' => '✅ Urgent inspection approved',
        'push_title_urgent_rejected' => '⛔ Urgent inspection rejected',
        'toast_rejected' => '🔔 :count request(s) rejected — open the bell for the reason',
        'toast_done' => '✅ :count request(s) confirmed',
        'footer' => 'See the portal Inventory and Sales tabs for details.',
        'push_enable' => 'Enable phone alerts',
        'push_on' => 'Phone alerts on — you will be notified even when the screen is off',
        'push_disable' => 'Turn off',
        'push_denied' => 'Notifications are blocked in this browser — allow them in site settings and try again.',
        'push_unsupported' => 'This browser does not support push notifications. On iPhone, use the app added to the Home Screen.',
        'push_error' => 'Setup failed — please try again.',
        'push_title_rejected' => '🔔 Request rejected',
        'push_title_done' => '✅ Request confirmed',
    ],
    'menu_toggle' => 'Menu',
];
