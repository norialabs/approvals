<?php

declare(strict_types=1);
use Noria\Approvals\Defaults\AuthActors;
use Noria\Approvals\Defaults\ConfiguredPolicy;
use Noria\Approvals\Defaults\ConfiguredTenancy;
use Noria\Approvals\Defaults\GateReviewers;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalDelegation;
use Noria\Approvals\Models\ApprovalEvent;
use Noria\Approvals\Models\ApprovalPolicy;

return [
    'enforced' => env('APPROVALS_ENFORCED', true),

    'intercept' => 'halt',

    'without_actor' => 'hold',

    'ignored' => [],

    'distinct_reviewers' => true,

    'on_conflict' => 'fail',

    'run_migrations' => true,

    'keys' => 'ulid',

    'tables' => [
        'approvals' => 'approvals',
        'events' => 'approval_events',
        'delegations' => 'approval_delegations',
        'policies' => 'approval_policies',
    ],

    'models' => [
        'approval' => Approval::class,
        'event' => ApprovalEvent::class,
        'delegation' => ApprovalDelegation::class,
        'policy' => ApprovalPolicy::class,
    ],

    'subjects' => [
        'key' => 'string',
    ],

    'actors' => [
        'model' => 'App\\Models\\User',
        'table' => 'users',
        'key' => 'int',
        'references' => 'id',
        'constrained' => true,
        'resolver' => AuthActors::class,
    ],

    'tenancy' => [
        'enabled' => false,
        'column' => 'tenant_id',
        'table' => 'tenants',
        'key' => 'int',
        'references' => 'id',
        'constrained' => true,
        'scope_queries' => true,
        'resolver' => ConfiguredTenancy::class,
    ],

    'policy' => ConfiguredPolicy::class,

    'reviewers' => GateReviewers::class,

    'database_policies' => false,

    'default' => [
        'steps' => [
            ['name' => 'review', 'approvals' => 1],
        ],
    ],

    'subject_policies' => [],

    'reminders' => [
        'after' => 1440,
    ],
];
