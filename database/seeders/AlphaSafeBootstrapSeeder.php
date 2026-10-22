<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\LegalEntity;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Requisition;
use App\Models\RequisitionVersion;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AlphaSafeBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $permissionsCatalog = [
            ['slug' => 'org.manage', 'name' => 'Manage Organization Structure', 'module' => 'organization'],
            ['slug' => 'iam.manage', 'name' => 'Manage Tenant Users & Roles', 'module' => 'iam'],
            ['slug' => 'requisitions.view', 'name' => 'View Workforce Requisitions', 'module' => 'requisitions'],
            ['slug' => 'requisitions.create', 'name' => 'Create & Submit Requisitions', 'module' => 'requisitions'],
            ['slug' => 'requisitions.approve', 'name' => 'Approve Workforce Requisitions', 'module' => 'requisitions'],
            ['slug' => 'jobs.manage', 'name' => 'Manage Job Descriptions & Versions', 'module' => 'jobs'],
            ['slug' => 'jobs.publish', 'name' => 'Approve & Publish Jobs to Career Portal', 'module' => 'jobs'],
            ['slug' => 'candidates.view', 'name' => 'View Candidate CRM & Documents', 'module' => 'candidates'],
            ['slug' => 'candidates.manage', 'name' => 'Manage Candidate Profiles & Consent', 'module' => 'candidates'],
            ['slug' => 'applications.manage', 'name' => 'Manage Applications & Stage Transitions', 'module' => 'applications'],
            ['slug' => 'screening.manage', 'name' => 'Run & Review Candidate Screening & Assessments', 'module' => 'screening'],
            ['slug' => 'interviews.manage', 'name' => 'Schedule Interviews & Submit Scorecards', 'module' => 'interviews'],
            ['slug' => 'decisions.manage', 'name' => 'Record Authorized Hiring Decisions', 'module' => 'decisions'],
            ['slug' => 'offers.manage', 'name' => 'Draft & Issue Candidate Offers', 'module' => 'offers'],
            ['slug' => 'offers.approve', 'name' => 'Approve Candidate Offers', 'module' => 'offers'],
            ['slug' => 'hr_validation.manage', 'name' => 'Execute Post-Offer HR Checklist Validation', 'module' => 'hr_handover'],
            ['slug' => 'hr_handover.approve', 'name' => 'Explicitly Approve HR Handover to Dynamics', 'module' => 'hr_handover'],
            ['slug' => 'hr_handover.dispatch', 'name' => 'Dispatch & Reconcile Dynamics HR Handover', 'module' => 'hr_handover'],
            ['slug' => 'ai.use', 'name' => 'Invoke Governed AI Recruitment Assistance', 'module' => 'ai'],
            ['slug' => 'ai.admin', 'name' => 'Administer AI Gateway, Prompts, Budgets & Kill Switch', 'module' => 'ai'],
            ['slug' => 'api.admin', 'name' => 'Manage REST API Clients & Webhooks', 'module' => 'integrations'],
            ['slug' => 'mcp.use', 'name' => 'Invoke Allowlisted MCP Tools', 'module' => 'mcp'],
            ['slug' => 'dynamics.admin', 'name' => 'Configure Dynamics 365 Finance HR Connector', 'module' => 'dynamics'],
            ['slug' => 'reports.view', 'name' => 'View Recruitment & Operational Analytics', 'module' => 'analytics'],
            ['slug' => 'audit.view', 'name' => 'View Append-Only Security & Compliance Audit Trail', 'module' => 'audit'],
        ];

        $permissionMap = [];
        foreach ($permissionsCatalog as $permData) {
            $perm = Permission::updateOrCreate(
                ['slug' => $permData['slug']],
                $permData
            );
            $permissionMap[$perm->slug] = $perm->id;
        }

        $rolesCatalog = [
            'platform_superadmin' => [
                'name' => 'Platform Superadmin',
                'description' => 'Platform configuration, tenant lifecycle, provider health. Scoped support access only.',
                'permissions' => ['ai.admin', 'api.admin', 'dynamics.admin', 'reports.view', 'audit.view'],
            ],
            'tenant_admin' => [
                'name' => 'Tenant Owner / Admin',
                'description' => 'Full tenant administration, organization structure, IAM, workflows, and integrations.',
                'permissions' => [
                    'org.manage', 'iam.manage', 'requisitions.view', 'requisitions.create', 'requisitions.approve',
                    'jobs.manage', 'jobs.publish', 'candidates.view', 'candidates.manage', 'applications.manage',
                    'screening.manage', 'interviews.manage', 'decisions.manage', 'offers.manage', 'offers.approve',
                    'ai.use', 'ai.admin', 'api.admin', 'mcp.use', 'dynamics.admin', 'reports.view', 'audit.view',
                ],
            ],
            'recruitment_admin' => [
                'name' => 'Recruitment Admin',
                'description' => 'Recruitment rules, templates, screening criteria, workflows, and operational reports.',
                'permissions' => [
                    'org.manage', 'requisitions.view', 'requisitions.create', 'requisitions.approve',
                    'jobs.manage', 'jobs.publish', 'candidates.view', 'candidates.manage', 'applications.manage',
                    'screening.manage', 'interviews.manage', 'decisions.manage', 'offers.manage', 'offers.approve',
                    'ai.use', 'mcp.use', 'reports.view',
                ],
            ],
            'recruiter' => [
                'name' => 'Recruiter',
                'description' => 'Requisitions, jobs, candidates, applications, screening, interviews, and offer drafting.',
                'permissions' => [
                    'requisitions.view', 'requisitions.create', 'jobs.manage', 'jobs.publish',
                    'candidates.view', 'candidates.manage', 'applications.manage', 'screening.manage',
                    'interviews.manage', 'offers.manage', 'ai.use', 'mcp.use', 'reports.view',
                ],
            ],
            'hiring_manager' => [
                'name' => 'Hiring Manager / Interviewer',
                'description' => 'Assigned requisitions, candidates, interview scorecards, and hiring decisions.',
                'permissions' => [
                    'requisitions.view', 'requisitions.create', 'requisitions.approve',
                    'candidates.view', 'interviews.manage', 'decisions.manage', 'offers.approve', 'reports.view',
                ],
            ],
            'hr_validator' => [
                'name' => 'HR Validator',
                'description' => 'Post-offer HR checklist verification and employment data validation (cannot approve handover).',
                'permissions' => [
                    'requisitions.view', 'candidates.view', 'hr_validation.manage', 'reports.view',
                ],
            ],
            'hr_approver' => [
                'name' => 'HR / Onboarding Approver',
                'description' => 'Explicit final approval or rejection of Dynamics 365 Finance HR employee handover.',
                'permissions' => [
                    'requisitions.view', 'candidates.view', 'hr_handover.approve', 'hr_handover.dispatch', 'reports.view', 'audit.view',
                ],
            ],
            'candidate' => [
                'name' => 'Candidate',
                'description' => 'Candidate portal persona restricted to own profile, consent, documents, and applications.',
                'permissions' => [],
            ],
            'integration_service' => [
                'name' => 'Integration Service Identity',
                'description' => 'Non-interactive service scope for API/MCP/Dynamics integration operations.',
                'permissions' => ['requisitions.view', 'jobs.manage', 'candidates.view', 'applications.manage', 'mcp.use'],
            ],
            'auditor' => [
                'name' => 'Compliance Auditor',
                'description' => 'Read-only access to permitted audit trails, reports, and compliance evidence.',
                'permissions' => ['requisitions.view', 'reports.view', 'audit.view'],
            ],
        ];

        $roleMap = [];
        foreach ($rolesCatalog as $slug => $meta) {
            $role = Role::updateOrCreate(
                ['tenant_id' => null, 'slug' => $slug],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                    'is_system' => true,
                ]
            );
            $permIds = array_values(array_intersect_key($permissionMap, array_flip($meta['permissions'])));
            $role->permissions()->sync($permIds);
            $roleMap[$slug] = $role;
        }

        if (app()->environment(['local', 'testing'])) {
            $tenantA = Tenant::updateOrCreate(
                ['slug' => 'acme-demo'],
                [
                    'name' => 'Acme Global Tech (Demo)',
                    'primary_domain' => 'acme.career.tringers.com',
                    'default_timezone' => 'UTC',
                    'default_currency' => 'USD',
                    'default_locale' => 'en',
                    'status' => 'ACTIVE',
                    'branding_config' => ['primary_color' => '#0d6efd', 'tagline' => 'Building the Future of Enterprise AI'],
                    'settings' => ['require_self_approval_block' => true, 'require_hr_dual_control' => true],
                    'retention_days' => 365,
                    'ai_kill_switch' => false,
                    'ai_monthly_budget_usd' => 1000.00,
                    'mcp_enabled' => true,
                    'dynamics_live_enabled' => false,
                    'is_demo' => true,
                ]
            );

            $tenantB = Tenant::updateOrCreate(
                ['slug' => 'globex-demo'],
                [
                    'name' => 'Globex Healthcare Systems (Demo)',
                    'primary_domain' => 'globex.career.tringers.com',
                    'default_timezone' => 'Europe/London',
                    'default_currency' => 'GBP',
                    'default_locale' => 'en',
                    'status' => 'ACTIVE',
                    'branding_config' => ['primary_color' => '#198754', 'tagline' => 'Next-Generation Clinical Operations'],
                    'settings' => ['require_self_approval_block' => true, 'require_hr_dual_control' => true],
                    'retention_days' => 730,
                    'ai_kill_switch' => false,
                    'ai_monthly_budget_usd' => 750.00,
                    'mcp_enabled' => true,
                    'dynamics_live_enabled' => false,
                    'is_demo' => true,
                ]
            );

            $demoUsers = [
                [
                    'email' => 'admin@acme-demo.test',
                    'name' => 'Alice Vance (Acme Tenant Admin)',
                    'tenant' => $tenantA,
                    'role' => 'tenant_admin',
                    'superadmin' => false,
                ],
                [
                    'email' => 'recruiter@acme-demo.test',
                    'name' => 'Marcus Chen (Acme Recruiter)',
                    'tenant' => $tenantA,
                    'role' => 'recruiter',
                    'superadmin' => false,
                ],
                [
                    'email' => 'manager@acme-demo.test',
                    'name' => 'Elena Rostova (Acme Hiring Manager)',
                    'tenant' => $tenantA,
                    'role' => 'hiring_manager',
                    'superadmin' => false,
                ],
                [
                    'email' => 'hr.validator@acme-demo.test',
                    'name' => 'David Patel (Acme HR Validator)',
                    'tenant' => $tenantA,
                    'role' => 'hr_validator',
                    'superadmin' => false,
                ],
                [
                    'email' => 'hr.approver@acme-demo.test',
                    'name' => 'Sarah Jenkins (Acme HR Handover Approver)',
                    'tenant' => $tenantA,
                    'role' => 'hr_approver',
                    'superadmin' => false,
                ],
                [
                    'email' => 'auditor@acme-demo.test',
                    'name' => 'Victor Krum (Acme Compliance Auditor)',
                    'tenant' => $tenantA,
                    'role' => 'auditor',
                    'superadmin' => false,
                ],
                [
                    'email' => 'admin@globex-demo.test',
                    'name' => 'Dr. Julian Bashir (Globex Tenant Admin)',
                    'tenant' => $tenantB,
                    'role' => 'tenant_admin',
                    'superadmin' => false,
                ],
                [
                    'email' => 'platform.superadmin@tringers.test',
                    'name' => 'Platform Superadmin (Scoped Governance)',
                    'tenant' => $tenantA,
                    'role' => 'platform_superadmin',
                    'superadmin' => true,
                ],
            ];

            $defaultPasswordHash = Hash::make('AlphaDemo#2026!');
            $createdUsers = [];

            foreach ($demoUsers as $u) {
                $user = User::updateOrCreate(
                    ['email' => $u['email']],
                    [
                        'name' => $u['name'],
                        'password' => $defaultPasswordHash,
                        'current_tenant_id' => $u['tenant']->id,
                        'is_platform_superadmin' => $u['superadmin'],
                        'mfa_enabled' => true,
                    ]
                );

                TenantMembership::updateOrCreate(
                    [
                        'tenant_id' => $u['tenant']->id,
                        'user_id' => $user->id,
                        'role_id' => $roleMap[$u['role']]->id,
                    ],
                    [
                        'status' => 'ACTIVE',
                        'abac_scope' => ['scope_mode' => 'tenant_scoped'],
                    ]
                );

                $createdUsers[$u['email']] = $user;
            }

            if (Schema::hasTable('legal_entities') && Schema::hasTable('requisitions')) {
                $acmeLe = LegalEntity::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantA->id, 'code' => 'USMF'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'name' => 'Acme US Manufacturing & Cloud LLC (Demo)',
                        'country_code' => 'US',
                        'currency_code' => 'USD',
                        'dynamics_company_code' => 'USMF',
                        'is_active' => true,
                    ]
                );

                $acmeDept = Department::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantA->id, 'code' => 'ENG-AI'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'legal_entity_id' => $acmeLe->id,
                        'name' => 'AI Platform & Distributed Systems',
                        'cost_centre_code' => 'CC-4100',
                        'head_user_id' => $createdUsers['manager@acme-demo.test']->id,
                        'is_active' => true,
                    ]
                );

                $acmeLoc = Location::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantA->id, 'code' => 'NYC-HQ'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'name' => 'New York Innovation Hub',
                        'city' => 'New York',
                        'country_code' => 'US',
                        'timezone' => 'America/New_York',
                        'work_mode' => 'HYBRID',
                        'is_active' => true,
                    ]
                );

                $reqA = Requisition::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantA->id, 'requisition_number' => 'REQ-2026-0101'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'title' => 'Senior AI Platform Architect (Demo)',
                        'department_id' => $acmeDept->id,
                        'location_id' => $acmeLoc->id,
                        'legal_entity_id' => $acmeLe->id,
                        'hiring_manager_user_id' => $createdUsers['manager@acme-demo.test']->id,
                        'recruiter_user_id' => $createdUsers['recruiter@acme-demo.test']->id,
                        'created_by_user_id' => $createdUsers['recruiter@acme-demo.test']->id,
                        'headcount' => 2,
                        'filled_headcount' => 0,
                        'employment_type' => 'FULL_TIME',
                        'requisition_reason' => 'NEW_ROLE',
                        'budget_reference' => 'BUD-2026-AI-101',
                        'salary_min' => 165000,
                        'salary_max' => 210000,
                        'currency_code' => 'USD',
                        'target_start_date' => '2026-12-01',
                        'justification' => 'Expand AI platform architecture and Dynamics 365 HR integration reliability team.',
                        'status' => Requisition::STATUS_OPEN,
                        'current_version' => 1,
                        'approved_version' => 1,
                        'approved_by_user_id' => $createdUsers['manager@acme-demo.test']->id,
                        'approved_at' => now(),
                    ]
                );

                RequisitionVersion::withoutTenantScope()->updateOrCreate(
                    ['requisition_id' => $reqA->id, 'version_number' => 1],
                    [
                        'tenant_id' => $tenantA->id,
                        'snapshot' => ['title' => $reqA->title, 'budget_reference' => $reqA->budget_reference],
                        'change_summary' => 'Seeded demo open requisition',
                        'created_by_user_id' => $createdUsers['recruiter@acme-demo.test']->id,
                        'created_at' => now(),
                    ]
                );

                // Seed Tenant B (Globex) isolated organization & requisition
                $globexLe = LegalEntity::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantB->id, 'code' => 'GBPM'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'name' => 'Globex UK Medical Systems Ltd (Demo)',
                        'country_code' => 'GB',
                        'currency_code' => 'GBP',
                        'dynamics_company_code' => 'GBPM',
                        'is_active' => true,
                    ]
                );

                $globexDept = Department::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantB->id, 'code' => 'CLIN-INFO'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'legal_entity_id' => $globexLe->id,
                        'name' => 'Clinical Informatics',
                        'cost_centre_code' => 'CC-9900',
                        'head_user_id' => $createdUsers['admin@globex-demo.test']->id,
                        'is_active' => true,
                    ]
                );

                $globexLoc = Location::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantB->id, 'code' => 'LON-HQ'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'name' => 'London Clinical Centre',
                        'city' => 'London',
                        'country_code' => 'GB',
                        'timezone' => 'Europe/London',
                        'work_mode' => 'ONSITE',
                        'is_active' => true,
                    ]
                );

                Requisition::withoutTenantScope()->updateOrCreate(
                    ['tenant_id' => $tenantB->id, 'requisition_number' => 'REQ-2026-0901'],
                    [
                        'uuid' => (string) Str::uuid(),
                        'title' => 'Lead Clinical Systems Engineer (Globex Demo)',
                        'department_id' => $globexDept->id,
                        'location_id' => $globexLoc->id,
                        'legal_entity_id' => $globexLe->id,
                        'hiring_manager_user_id' => $createdUsers['admin@globex-demo.test']->id,
                        'created_by_user_id' => $createdUsers['admin@globex-demo.test']->id,
                        'headcount' => 1,
                        'filled_headcount' => 0,
                        'employment_type' => 'FULL_TIME',
                        'requisition_reason' => 'NEW_ROLE',
                        'budget_reference' => 'BUD-GLOBEX-901',
                        'salary_min' => 85000,
                        'salary_max' => 110000,
                        'currency_code' => 'GBP',
                        'justification' => 'Clinical data interoperability lead.',
                        'status' => Requisition::STATUS_OPEN,
                        'current_version' => 1,
                        'approved_version' => 1,
                        'approved_by_user_id' => $createdUsers['admin@globex-demo.test']->id,
                        'approved_at' => now(),
                    ]
                );
            }
        }
    }
}
