<?php

namespace BrainletAli\Locksmith\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/** Artisan command for interactive package installation. */
class InstallCommand extends Command
{
    protected $signature = 'locksmith:install';

    protected $description = 'Install Locksmith and configure recipes';

    /** @var array<string, array{name: string, package: string|null, description: string}> */
    protected array $availableRecipes = [
        'aws' => [
            'name' => 'AWS IAM',
            'package' => 'aws/aws-sdk-php',
            'description' => 'Rotate AWS IAM access keys',
        ],
    ];

    public function handle(): int
    {
        info('Welcome to Laravel Locksmith!');
        note('Zero-downtime secrets rotation for Laravel applications.');

        $this->newLine();

        // Publish config
        if (confirm('Publish the configuration file?', true)) {
            $this->callSilent('vendor:publish', [
                '--tag' => 'locksmith-config',
            ]);
            info('Configuration published to config/locksmith.php');
        }

        // Publish and run migrations
        if (confirm('Publish and run database migrations?', true)) {
            $this->callSilent('vendor:publish', [
                '--tag' => 'locksmith-migrations',
            ]);
            info('Migrations published to database/migrations');
            $this->call('migrate');
        }

        $this->newLine();

        // Select recipes
        $selectedRecipes = multiselect(
            label: 'Which recipes would you like to install?',
            options: [
                'aws' => 'AWS IAM - Rotate AWS access keys (requires aws/aws-sdk-php)',
            ],
            hint: 'Use space to select, enter to confirm'
        );

        // Install dependencies for selected recipes
        $packagesToInstall = [];
        foreach ($selectedRecipes as $recipe) {
            $package = $this->availableRecipes[$recipe]['package'] ?? null;
            if ($package) {
                $packagesToInstall[] = $package;
            }
        }

        if (! empty($packagesToInstall)) {
            $this->newLine();
            info('Installing required dependencies...');

            foreach ($packagesToInstall as $package) {
                $this->components->task("Installing {$package}", function () use ($package) {
                    $result = Process::run("composer require {$package}");

                    return $result->successful();
                });
            }
        }

        $this->newLine();

        // Show next steps
        info('Locksmith installed successfully!');

        $this->newLine();
        note('Next steps:');

        if (in_array('aws', $selectedRecipes)) {
            $this->line('  <fg=yellow>AWS Recipe:</>');
            $this->line('    1. Set LOCKSMITH_AWS_IAM_USERNAME in .env');
            $this->line('    2. Configure AWS credentials (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY)');
            $this->line('    3. Store initial secret: Locksmith::set(\'aws.credentials\', $initialValue)');
            $this->newLine();
        }

        if (empty($selectedRecipes)) {
            warning('No recipes selected. You can use the generic API with custom closures:');
            $this->newLine();
            $this->line('  Locksmith::rotate(\'my.secret\', ');
            $this->line('      generate: fn () => createNewSecret(),');
            $this->line('      validate: fn ($v) => testSecret($v)');
            $this->line('  );');
            $this->newLine();
        }

        $this->line('  <fg=cyan>Documentation:</> https://github.com/brainlet-ali/laravel-locksmith');

        return self::SUCCESS;
    }
}
