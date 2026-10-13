<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'smartstock:create-super-admin';

    protected $description = 'Provision or promote a SmartStock Platform Super Admin account';

    public function handle(): int
    {
        $this->warn('This command creates a privileged platform account.');

        if (!$this->confirm('Do you want to continue?', false)) {
            return self::SUCCESS;
        }

        $email = strtolower(trim($this->ask('Email address') ?? ''));
        $existing = User::where('email', $email)->first();

        if ($existing) {
            $this->line('An account already exists for this email.');
            $this->line('Current platform role: ' . $existing->platform_role);

            if (!$this->confirm('Assign the super_admin role to this account?', false)) {
                $this->info('No changes made.');
                return self::SUCCESS;
            }

            $overwritePassword = $this->confirm(
                'Overwrite this account password?',
                false
            );

            $password = null;
            $confirmation = null;

            if ($overwritePassword) {
                $password = $this->secret('New password');
                $confirmation = $this->secret('Confirm new password');
            }

            $validator = Validator::make([
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ], [
                'email' => ['required', 'email', 'max:255'],
                'password' => $overwritePassword ? [
                    'required',
                    'confirmed',
                    Password::min(12)->mixedCase()->numbers()->symbols(),
                ] : ['nullable'],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            try {
                DB::transaction(function () use (
                    $email,
                    $password,
                    $existing
                ) {
                    $user = User::whereKey($existing->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($password !== null) {
                        $user->password = $password;
                    }

                    $user->platform_role = 'super_admin';
                    $user->save();
                });
            } catch (\Throwable) {
                $this->error('Super Admin provisioning failed. Check Laravel logs.');
                return self::FAILURE;
            }

            $this->info('Super Admin account updated successfully.');
            $this->line('Email: ' . $email);
            return self::SUCCESS;
        }

        $name = trim($this->ask('Full name') ?? '');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $email, $password) {
                $user = new User();
                $user->name = $name;
                $user->email = $email;
                $user->password = $password;
                $user->platform_role = 'super_admin';
                $user->save();
            });
        } catch (\Throwable $e) {
            $this->error('Account creation failed. Check Laravel logs.');
            return self::FAILURE;
        }

        $this->info('Super Admin created successfully.');
        $this->line('Email: ' . $email);

        return self::SUCCESS;
    }
}