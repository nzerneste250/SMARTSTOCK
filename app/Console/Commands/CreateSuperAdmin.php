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

    protected $description = 'Create the first SmartStock Super Admin account';

    public function handle(): int
    {
        if (User::where('platform_role', 'super_admin')->exists()) {
            $this->error('A Super Admin already exists.');
            return self::FAILURE;
        }

        $this->warn('This command creates a privileged platform account.');

        if (!$this->confirm('Do you want to continue?', false)) {
            return self::SUCCESS;
        }

        $name = trim($this->ask('Full name') ?? '');
        $email = strtolower(trim($this->ask('Email address') ?? ''));
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
                // Check again under the transaction.
                if (User::where('platform_role', 'super_admin')->exists()) {
                    throw new \RuntimeException(
                        'A Super Admin already exists.'
                    );
                }

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