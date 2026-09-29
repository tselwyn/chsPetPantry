<?php
declare(strict_types=1);

/*
 * Navigation registry. An entry is shown only when its page exists in public/ and the
 * signed-in user holds the capability, so modules appear as they are built.
 * Groups render in this order.
 */
return [
    ['group' => 'Home', 'file' => 'index.php', 'label' => 'Home', 'capability' => 'home.view'],

    ['group' => 'Participants', 'file' => 'participant_search.php', 'label' => 'Find a participant', 'capability' => 'participant.search'],
    ['group' => 'Participants', 'file' => 'participant_register.php', 'label' => 'Register a participant', 'capability' => 'participant.register'],
    ['group' => 'Participants', 'file' => 'registration_drafts.php', 'label' => 'Saved drafts', 'capability' => 'participant.register'],

    ['group' => 'Distribution', 'file' => 'events.php', 'label' => 'Distribution events', 'capability' => 'event.checkin'],
    ['group' => 'Distribution', 'file' => 'check_in.php', 'label' => 'Check-in queue', 'capability' => 'event.checkin'],
    ['group' => 'Distribution', 'file' => 'station/index.php', 'label' => 'Distribution station', 'capability' => 'distribution.record'],

    ['group' => 'Inventory', 'file' => 'inventory_stock.php', 'label' => 'Stock on hand', 'capability' => 'inventory.view'],
    ['group' => 'Inventory', 'file' => 'inventory_receipts.php', 'label' => 'Goods received', 'capability' => 'inventory.receive'],
    ['group' => 'Inventory', 'file' => 'inventory_count.php', 'label' => 'Stock count', 'capability' => 'inventory.count'],
    ['group' => 'Inventory', 'file' => 'inventory_catalogue.php', 'label' => 'Product catalogue', 'capability' => 'inventory.view'],

    ['group' => 'Spay/Neuter', 'file' => 'snv_followups.php', 'label' => 'Follow-ups', 'capability' => 'snv.manage'],

    ['group' => 'Reports', 'file' => 'report_hub.php', 'label' => 'Reports', 'capability' => 'report.aggregate.view'],
    ['group' => 'Reports', 'file' => 'board_dashboard.php', 'label' => 'Board dashboard', 'capability' => 'dashboard.board'],

    ['group' => 'Administration', 'file' => 'admin_users.php', 'label' => 'User accounts', 'capability' => 'user.manage'],
    ['group' => 'Administration', 'file' => 'admin_sites.php', 'label' => 'Sites', 'capability' => 'site.manage'],
    ['group' => 'Administration', 'file' => 'admin_devices.php', 'label' => 'Devices', 'capability' => 'device.register'],
    ['group' => 'Administration', 'file' => 'admin_settings.php', 'label' => 'Settings', 'capability' => 'settings.manage'],
    ['group' => 'Administration', 'file' => 'admin_policies.php', 'label' => 'Policy texts', 'capability' => 'policy.manage'],
    ['group' => 'Administration', 'file' => 'audit_log.php', 'label' => 'Audit log', 'capability' => 'audit.view'],
];
