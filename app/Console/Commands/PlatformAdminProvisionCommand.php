<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlatformAdminService;
use Illuminate\Console\Command;
use Throwable;

class PlatformAdminProvisionCommand extends Command
{
    protected $signature = 'platform:admin-provision
                            {--email= : Verified organizational email address for the Platform Super Admin}
                            {--name= : Full name of the Platform Super Admin}
                            {--operator= : Operator or ticket identifier executing this CLI action}
                            {--reason= : Audited justification (minimum 10 characters)}
                            {--password-stdin : Read strong password from STDIN instead of interactive secret prompt}
                            {--promote-existing : Explicitly authorize promoting an existing non-superadmin account}
                            {--recover-suspended : Explicitly authorize recovering a suspended administrator account}
                            {--reset-password : Force password rotation on an existing active Platform Super Admin}
                            {--require-password-rotation : Require the administrator to rotate their password on next web login}';

    protected $description = 'Explicitly provision, promote, or recover a real Platform Super Admin account (CLI-only, audited, idempotent)';

    public function handle(PlatformAdminService $adminService): int
    {
        $canPrompt = ! $this->option('no-interaction') && $this->input->isInteractive();
        $email = (string) ($this->option('email') ?: ($canPrompt ? $this->ask('Administrator email address (non-.test organizational email)') : ''));
        $name = (string) ($this->option('name') ?: ($canPrompt ? $this->ask('Administrator full name') : ''));
        $operator = (string) ($this->option('operator') ?: ($canPrompt ? $this->ask('Operator identifier (e.g., ssh-operator or ticket ref)') : ''));
        $reason = (string) ($this->option('reason') ?: ($canPrompt ? $this->ask('Audited justification (min 10 chars)') : ''));

        $plainPassword = null;
        if ($this->option('password-stdin')) {
            $stdinContent = stream_get_contents(STDIN);
            $plainPassword = $stdinContent !== false ? trim($stdinContent, "\r\n") : null;
        } elseif ($canPrompt) {
            $prompted = $this->secret('Enter strong password (min 14 chars, upper/lower/digit/symbol; leave blank if idempotent check)');
            if (is_string($prompted) && $prompted !== '') {
                $confirm = $this->secret('Confirm strong password');
                if ($prompted !== $confirm) {
                    $this->error('PASSWORD_MISMATCH: Password confirmation did not match.');

                    return self::FAILURE;
                }
                $plainPassword = $prompted;
            }
        }

        try {
            $result = $adminService->provisionSuperAdmin(
                email: $email,
                name: $name,
                plainPassword: $plainPassword,
                operatorIdentifier: $operator,
                reason: $reason,
                allowPromoteExisting: (bool) $this->option('promote-existing'),
                allowSuspendedRecovery: (bool) $this->option('recover-suspended'),
                forcePasswordUpdate: (bool) $this->option('reset-password'),
                requirePasswordRotation: (bool) $this->option('require-password-rotation'),
            );

            $this->info(sprintf(
                'PLATFORM_SUPERADMIN_%s: user_id=%d uuid=%s email=%s audit_uuid=%s',
                $result['action'],
                $result['user_id'],
                $result['user_uuid'],
                $result['masked_email'],
                $result['audit_event_uuid']
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
