<?php

// User management screen (/users · super only).

return [
    'title' => 'User management',
    'subtitle' => 'System admin (super) only. Create accounts, set roles, assign system admin, toggle active status. Inactive accounts are blocked from work screens.',
    'add_user' => 'Add user',
    'edit_user' => 'Edit user',

    // Table headers
    'col_name' => 'Name',
    'col_email' => 'Email',
    'col_perm_role' => 'Permission / Role',
    'col_car_erp_match' => 'car-erp sales match',
    'col_status' => 'Status',

    // Rows
    'me' => 'Me',
    'status_active' => 'Active',
    'status_inactive' => 'Inactive',
    'action_deactivate' => 'Deactivate',
    'action_activate' => 'Activate',

    // Form
    'label_name' => 'Name',
    'ph_name' => 'John Doe',
    'label_email' => 'Email (login ID)',
    'ph_email' => 'user@board.test',
    'label_phone' => 'Mobile (AlimTalk recipient)',
    'ph_phone' => '010-1234-5678',
    'label_region' => 'Assigned region',
    'region_hint' => 'Region inspection AlimTalk recipient basis (fixed roster)',
    'ph_region' => 'e.g. Gyeonggi Hwaseong',
    'label_role' => 'Role',
    'label_car_erp_email' => 'car-erp sales email',
    'optional_only_if_different' => '(optional · only if different from login)',
    'ph_car_erp_email' => 'car-erp salesperson email',
    'hint_car_erp_email' => 'Integration B <b>auto-matches the car-erp salesperson by email</b>. <b>If the login email above = the car-erp sales email, leave this blank</b> (auto match). Only when the login email differs, enter the car-erp sales email here and it will be matched by that.',
    'label_respond_email' => 'respond.io agent email',
    'ph_respond_email' => 'respond.io agent email',
    'hint_respond_email' => 'Integration A <b>promotion queue</b> is visible only to the respond.io agent handling the conversation. <b>If the login email = the respond.io agent email, leave this blank</b> (auto match). Only when different, enter it here and routing will use that.',
    'label_password' => 'Password',
    'label_password_edit_suffix' => '(enter only when changing)',
    'ph_password_keep' => 'Leave blank to keep current',
    'ph_password_new' => '6+ characters',
    'super_checkbox' => 'System admin',
    'super_checkbox_desc' => '(full access + user management)',
    'active_checkbox' => 'Active account (login allowed)',

    // flash / validation messages
    'saved' => 'Saved.',
    'err_cannot_deactivate_self' => 'You cannot deactivate your own account.',
    'err_cannot_remove_own_super' => 'You cannot remove your own system admin permission.',
];
