<?php

namespace App\Support;

/**
 * Everything a role can be allowed to do. Admins can tick these per role on
 * the Roles page; the admin role always has all of them. Each one is a Gate
 * ability, so routes use `can:<permission>` and views use `@can`.
 */
final class Permission
{
    public const VIEW_DASHBOARDS = 'view_dashboards';

    public const VIEW_INCIDENTS = 'view_incidents';

    public const RESPOND_INCIDENTS = 'respond_incidents';

    public const ACKNOWLEDGE_RESULTS = 'acknowledge_results';

    public const REVIEW_CORRECTIONS = 'review_corrections';

    public const VIEW_AGENTS = 'view_agents';

    public const MANAGE_AGENTS = 'manage_agents';

    public const MANAGE_OFFICIAL_RESULTS = 'manage_official_results';

    public const REVIEW_MEDIA = 'review_media';

    public const EXPORT_DATA = 'export_data';

    public const DELETE_EVIDENCE = 'delete_evidence';

    public const MODERATE_TOWNHALL = 'moderate_townhall';

    public const MANAGE_TOWNHALL = 'manage_townhall';

    public const MANAGE_BROADCASTS = 'manage_broadcasts';

    public const MANAGE_USERS = 'manage_users';

    public const MANAGE_SYSTEM = 'manage_system';

    public const VIEW_AUDIT = 'view_audit';

    public const SUBMIT_FIELD_REPORTS = 'submit_field_reports';

    /**
     * Grouped for the Roles page: group => [permission => [label, hint]].
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function groups(): array
    {
        return [
            'Situation room' => [
                self::VIEW_DASHBOARDS => ['See the dashboards', 'Dashboard, collation, 25% rule, polling units, map, official vs PVT, photos, situation report'],
                self::VIEW_INCIDENTS => ['See incidents', 'The incident feed'],
                self::RESPOND_INCIDENTS => ['Respond to incidents', 'Acknowledge, resolve and reopen; incident pop-ups'],
                self::ACKNOWLEDGE_RESULTS => ['Acknowledge results', 'Mark new results as seen; result pop-ups'],
                self::REVIEW_CORRECTIONS => ['Review corrections', 'Approve or reject corrections'],
                self::REVIEW_MEDIA => ['Review photos and videos', 'Mark EC8A photos and incident media as checked or doubtful, upload photos'],
                self::MANAGE_OFFICIAL_RESULTS => ['Enter official results', 'IReV figures, EC8B/EC8C collations, reading IReV sheets, CSV import'],
            ],
            'Agents' => [
                self::VIEW_AGENTS => ['See agents', 'Agents page with names and phone numbers, silent PUs'],
                self::MANAGE_AGENTS => ['Add agents and reset PINs', 'Creates the agent on the USSD service too'],
                self::SUBMIT_FIELD_REPORTS => ['Use the agent pages', 'Check in, materials, results, corrections, incidents, photos and videos (for agents)'],
            ],
            'Engagement' => [
                self::MODERATE_TOWNHALL => ['Moderate the town hall', 'Approve questions, put them on air, presenter view'],
                self::MANAGE_TOWNHALL => ['Set up town hall sessions', 'Create and edit sessions, draft reminders'],
                self::MANAGE_BROADCASTS => ['Send broadcasts', 'SMS and WhatsApp broadcasts and the contact list'],
            ],
            'Administration' => [
                self::EXPORT_DATA => ['Export data', 'CSV exports with phone numbers, and backups'],
                self::DELETE_EVIDENCE => ['Delete evidence', 'Delete EC8A photos, videos and official result entries'],
                self::MANAGE_USERS => ['Manage users and roles', 'Add people, change their role, edit roles'],
                self::MANAGE_SYSTEM => ['Manage the system', 'System page: sync, database, register, notifications set-up, rehearsal data'],
                self::VIEW_AUDIT => ['See the audit log', ''],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(array_merge(...array_values(self::groups())));
    }

    public static function label(string $permission): string
    {
        foreach (self::groups() as $permissions) {
            if (isset($permissions[$permission])) {
                return $permissions[$permission][0];
            }
        }

        return $permission;
    }

    /**
     * The roles a fresh install starts with.
     *
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public static function defaultRoles(): array
    {
        return [
            'admin' => ['name' => 'Admin', 'description' => 'Everything, including users, roles and the system.', 'permissions' => self::all()],
            'coordinator' => ['name' => 'Coordinator', 'description' => 'Runs the situation room: incidents, results, corrections, agents, official results and the town hall.', 'permissions' => [
                self::VIEW_DASHBOARDS, self::VIEW_INCIDENTS, self::RESPOND_INCIDENTS, self::ACKNOWLEDGE_RESULTS, self::REVIEW_CORRECTIONS,
                self::REVIEW_MEDIA, self::MANAGE_OFFICIAL_RESULTS, self::VIEW_AGENTS, self::MANAGE_AGENTS, self::MODERATE_TOWNHALL,
            ]],
            'observer' => ['name' => 'Observer', 'description' => 'Can watch the dashboards and incidents, but not act or see phone numbers.', 'permissions' => [
                self::VIEW_DASHBOARDS, self::VIEW_INCIDENTS,
            ]],
            'agent' => ['name' => 'Agent', 'description' => 'Polling agents. They sign in with their phone number and USSD PIN and see only the agent pages.', 'permissions' => [
                self::SUBMIT_FIELD_REPORTS,
            ]],
        ];
    }
}
