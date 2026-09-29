<?php
declare(strict_types=1);

/*
 * Default capability matrix (docs/PFPMS_Implementation_Plan.md §4).
 *
 * Coordinator inherits Volunteer; Administrator inherits Coordinator; Board inherits nothing.
 * Whether Coordinator and Board are real roles is an open client question (notes §4.1):
 * answering it means editing this file, not the code.
 * Some capabilities need more than the role, e.g. report.identifiable also needs
 * user_account.can_extract_identifiable; the code that uses them checks that too.
 */
return [
    'Volunteer' => [
        'inherits' => null,
        'capabilities' => [
            'home.view', 'profile.self', 'auth.pin_switch',
            'participant.search', 'participant.view', 'participant.register', 'participant.update',
            'pet.edit', 'pet.delete_request',
            'event.checkin', 'distribution.record', 'history.view', 'snv.refer', 'inventory.view',
            'offline.distribute', 'offline.checkin', 'offline.register', 'offline.pet_edit',
        ],
    ],
    'Coordinator' => [
        'inherits' => 'Volunteer',
        'capabilities' => [
            'event.manage', 'event.dashboard', 'inventory.receive', 'inventory.count', 'catalog.barcode_link',
            'intake_question.manage', 'language.manage', 'device.register', 'allotment.view',
            'session.roster', 'session.remote_signout', 'user.site_grant_temporary',
            'distribution.reverse', 'sync.review', 'snv.manage', 'report.aggregate.view',
        ],
    ],
    'Administrator' => [
        'inherits' => 'Coordinator',
        'capabilities' => [
            'site.all', 'site.manage', 'settings.manage', 'lookup.manage', 'policy.manage', 'service_area.manage',
            'catalog.manage', 'clinic.manage', 'budget.manage', 'user.manage', 'allotment.manage',
            'participant.update_restricted', 'participant.deactivate', 'participant.merge', 'participant.delete',
            'participant.restore', 'participant.erasure', 'participant.area_override', 'participant.search_include_deleted',
            'alert.create', 'alert.resolve', 'pet.delete', 'pet.limit_override', 'pet.microchip_resolve',
            'distribution.authorize_override', 'distribution.authorize_emergency',
            'import.run', 'import.during_distribution_hours',
            'report.run', 'report.schedule', 'report.identifiable', 'audit.view',
        ],
    ],
    'Board' => [
        'inherits' => null,
        'capabilities' => ['home.view', 'profile.self', 'dashboard.board', 'report.aggregate.view', 'site.all'],
    ],
];
