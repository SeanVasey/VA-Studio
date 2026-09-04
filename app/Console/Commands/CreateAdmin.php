<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'vasey:create-admin';

    protected $description = 'Create the first trusted operator using hidden interactive password input.';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Operator name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password (at least 16 characters)')];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users'], 'password' => ['required', Password::min(16)->letters()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($data);
        $user->is_admin = true;
        $user->email_verified_at = now();
        $user->save();
        $this->info('Operator created. Sign in at /admin. No credentials were written to seed files.');

        return self::SUCCESS;
    }
}
