<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlatformAdminService;
use Illuminate\Console\Command;
use Throwable;

class PlatformAdminRevokeCommand extends Command
{
    protected $signature = 'platform:admin-revoke
                            {--email= : Email address of the Platform Super Admin to revoke}
                            {--operator= : Operator identifier executing this revocation}
                            {--reason= : Audited justification for revocation (minimum 10 characters)}
                            {--suspend-account : Also suspend the user account across all sessions}';

    protected $description = 'Revoke Platform Super Admin authority with last-Super-Admin lockout protection (CLI-only, audited)';

    public function handle(PlatformAdminService $adminService): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Target Platform Super Admin email address'))));
        $operator = (string) ($this->option('operator') ?: $this->ask('Operator identifier'));
        $reason = (string) ($this->option('reason') ?: $this->ask('Audited revocation justification (min 10 chars)'));

        /** @var User|null $targetUser */
        $targetUser = User::query()->where('email', $email)->first();
        if (! $targetUser) {
            $this->error('TARGET_NOT_FOUND: No user account matches the specified email address.');

            return self::FAILURE;
        }

        try {
            $result = $adminService->revokeSuperAdmin(
                targetUser: $targetUser,
                operatorIdentifier: $operator,
                reason: $reason,
                suspendAccount: (bool) $this->option('suspend-account'),
            );

            $this->info(sprintf(
                'PLATFORM_SUPERADMIN_%s: user_id=%d email=%s status=%s audit_uuid=%s',
                $result['action'],
                $result['user_id'],
                $result['masked_email'],
                $result['status'],
                $result['audit_event_uuid']
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
