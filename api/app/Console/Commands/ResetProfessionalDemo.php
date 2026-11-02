<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DemoEnvironment;
use Database\Seeders\ProfessionalDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Fortify;

class ResetProfessionalDemo extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild only the explicitly marked isolated professional demo installation';

    public function handle(): int
    {
        DemoEnvironment::assertIsolated();
        if (DemoEnvironment::readOnly()) {
            $this->error('Use the private writer configuration for reset.');

            return self::FAILURE;
        }
        if (Schema::hasTable('users') && User::where('email', 'not like', '%@harborline.test')->exists()) {
            $this->error('Unexpected identities detected. Refusing to reset this database.');

            return self::FAILURE;
        }
        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => ProfessionalDemoSeeder::class, '--force' => true]);
        $owner = User::where('email', 'morgan@harborline.test')->firstOrFail();
        $report = ['environment' => 'Isolated local QA. Keep this file private.', 'owner' => $owner->email, 'password' => config('demo.password'), 'totpSecret' => Fortify::currentEncrypter()->decrypt($owner->two_factor_secret), 'projectAdministrator' => 'avery@harborline.test', 'developer' => 'jamie@harborline.test'];
        $path = storage_path('demo-access.private.json');
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        chmod($path, 0600);
        $this->info('Demo restored: 10 projects, 150 tasks. Private access report: '.$path);

        return self::SUCCESS;
    }
}
