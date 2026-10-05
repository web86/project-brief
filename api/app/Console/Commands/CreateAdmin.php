<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create the first administrator (interactive; password is never echoed)';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Имя'), 'email' => $this->ask('Email'), 'password' => $this->secret('Пароль (не менее 12 символов)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'password' => 'required|string|min:12|max:1024']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        $this->info('Администратор создан.');

        return self::SUCCESS;
    }
}
