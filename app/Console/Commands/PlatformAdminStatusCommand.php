<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlatformAdminService;
use Illuminate\Console\Command;

class PlatformAdminStatusCommand extends Command
{
    protected $signature = 'platform:admin-status
                            {--json : Output sanitized status report as JSON}
                            {--require-real : Exit with non-zero code if no non-synthetic Platform Super Admin is active}';

    protected $description = 'Inspect Platform Super Admin provisioning state without exposing credentials or PII';

    public function handle(PlatformAdminService $adminService): int
    {
        $report = $adminService->inspectSuperAdminStatus();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('=== Unified Recruitment AI Platform — Super Admin Provisioning Status ===');
            $this->line('Real Production Super Admin Provisioned : '.($report['real_superadmin_provisioned'] ? 'YES' : 'NO (Action Required)'));
            $this->line('Active Real Super Admins                : '.$report['active_real_superadmin_count']);
            $this->line('Active Synthetic/Demo Super Admins      : '.$report['active_demo_superadmin_count']);
            $this->line('Suspended Super Admins                  : '.$report['suspended_superadmin_count']);

            if ($report['accounts'] !== []) {
                $rows = array_map(fn (array $a) => [
                    $a['id'],
                    $a['masked_email'],
                    $a['status'],
                    $a['platform_role'],
                    $a['mfa_enabled'] ? 'YES' : 'NO',
                    $a['must_rotate_password'] ? 'YES' : 'NO',
                    $a['is_synthetic_demo'] ? 'DEMO (.test)' : 'REAL',
                    $a['last_login_at'] ?? 'never',
                ], $report['accounts']);

                $this->table(
                    ['ID', 'Masked Email', 'Status', 'Platform Role', 'MFA', 'Rotate Pwd?', 'Identity Class', 'Last Login'],
                    $rows
                );
            } else {
                $this->warn('No Platform Super Admin accounts exist in the database.');
            }
        }

        if ($this->option('require-real') && ! $report['real_superadmin_provisioned']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
