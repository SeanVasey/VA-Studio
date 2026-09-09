<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class CreateAdmin extends Command
{
    protected $signature = 'vasey:create-admin';

    protected $description = 'Create a trusted operator using hidden interactive password input and an audit record.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Operator creation requires an interactive trusted console; credentials cannot be supplied as command options.');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('Operator name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password (at least 16 characters)')];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users'], 'password' => ['required', Password::min(16)->letters()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($data): void {
                $user = new User($data);
                $user->is_admin = true;
                $user->email_verified_at = now();
                $user->save();
                AuditEvent::create([
                    'actor_id' => null, 'action' => 'access.operator.created',
                    'subject_type' => User::class, 'subject_id' => $user->id,
                    'context' => ['schema_version' => 1, 'authority' => 'trusted_interactive_console', 'is_admin' => true, 'email_verification' => 'console_attested'],
                ]);
            });
        } catch (Throwable) {
            $this->error('Operator creation failed. No account or audit changes were committed. Check database and audit availability.');

            return self::FAILURE;
        }
        $this->info('Operator created. Sign in at /admin. No credentials were written to seed files.');

        return self::SUCCESS;
    }
}
