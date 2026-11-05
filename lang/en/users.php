<?php

// User management screen (/users · manager role + super).

return [
    'title' => 'User management',
    'subtitle' => 'Create accounts, set roles and active status. Inactive accounts are blocked from work screens. Only a system admin can assign system admin or edit a system admin account.',
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
    'super_row_locked' => 'Higher tier only',

    // Form
    'label_name' => 'Name',
    'ph_name' => 'John Doe',
    'label_email' => 'Email (login ID)',
    'ph_email' => 'user@board.test',
    'label_phone' => 'Mobile (AlimTalk recipient)',
    'ph_phone' => '010-1234-5678',
    'label_region' => 'Assigned region',
    'region_hint' => 'Vehicles in this region are auto-assigned on the inspection screen, and region inspection AlimTalk goes here',
    'ph_region' => 'e.g. 경기 화성시',
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
    'active_checkbox' => 'Active account (login allowed)',

    // flash / validation messages
    'saved' => 'Saved.',
    'err_cannot_deactivate_self' => 'You cannot deactivate your own account.',
    'err_cannot_remove_own_super' => 'You cannot remove your own system admin permission.',
    'err_super_target_forbidden' => 'Higher-tier accounts (system admin, operations manager) can only be edited by the same tier or above.',
    'search_ph' => 'Name · email · phone',
    'filter_all' => 'All',
    'per_page' => ':count per page',
    'filter_perm_super' => 'System admin',
    'label_tier' => 'Level',
    'tier' => ['user' => 'User', 'manager' => 'Operations manager', 'admin' => 'Top manager', 'super' => 'System admin'],
    'tier_desc' => [
        'user' => 'Uses screens by role (sales, inspection, auction, manager). Writes the work journal.',
        'manager' => 'All screens regardless of role + audit log + user management (not higher levels). Cannot assign levels or change settings. As team leader, first approver of work journals.',
        'admin' => 'Operations manager rights + assigning levels (except system admin). No settings or portal proxy actions. Holds approval titles (GM, Executive, CEO).',
        'super' => 'Everything + settings + portal proxy actions.',
    ],
    'label_approval_title' => 'Approval title (work journal)',
    'title_none' => 'None',
    'team_leader_option' => 'Team leader',
    'title_hint' => 'One person per title — assigning moves it from the current holder.',
    'title_badge' => 'Approver :title',
    'title_released' => '(:title moved from :names)',
    'err_cannot_grant_tier' => 'You cannot assign that level.',
    'filter_perm_admin' => 'Top manager',
    'filter_perm_manager' => 'Work manager',
    'filter_perm_user' => 'User',
    'count' => ':count users',
    'search_empty' => 'No users match.',
];
