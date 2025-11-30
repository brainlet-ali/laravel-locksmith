<?php

namespace BrainletAli\Locksmith\Tests\Unit\Console\Commands;

use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_command_with_no_options(): void
    {
        $this->artisan('locksmith:install')
            ->expectsConfirmation('Publish the configuration file?', 'no')
            ->expectsConfirmation('Publish and run database migrations?', 'no')
            ->expectsQuestion('Which recipes would you like to install?', [])
            ->assertSuccessful();
    }

    public function test_install_command_with_config_publish(): void
    {
        $this->artisan('locksmith:install')
            ->expectsConfirmation('Publish the configuration file?', 'yes')
            ->expectsConfirmation('Publish and run database migrations?', 'no')
            ->expectsQuestion('Which recipes would you like to install?', [])
            ->assertSuccessful();
    }

    public function test_install_command_with_migrations(): void
    {
        $this->artisan('locksmith:install')
            ->expectsConfirmation('Publish the configuration file?', 'no')
            ->expectsConfirmation('Publish and run database migrations?', 'yes')
            ->expectsQuestion('Which recipes would you like to install?', [])
            ->assertSuccessful();
    }

    public function test_install_command_shows_generic_api_when_no_recipes_selected(): void
    {
        $this->artisan('locksmith:install')
            ->expectsConfirmation('Publish the configuration file?', 'no')
            ->expectsConfirmation('Publish and run database migrations?', 'no')
            ->expectsQuestion('Which recipes would you like to install?', [])
            ->assertSuccessful()
            ->expectsOutputToContain('No recipes selected');
    }

    public function test_install_command_shows_aws_instructions_when_aws_selected(): void
    {
        Process::fake();

        $this->artisan('locksmith:install')
            ->expectsConfirmation('Publish the configuration file?', 'no')
            ->expectsConfirmation('Publish and run database migrations?', 'no')
            ->expectsQuestion('Which recipes would you like to install?', ['aws'])
            ->assertSuccessful()
            ->expectsOutputToContain('AWS Recipe:');
    }

    public function test_install_command_installs_aws_sdk_when_selected(): void
    {
        Process::fake([
            'composer require aws/aws-sdk-php' => Process::result(output: 'Package installed'),
        ]);

        $this->artisan('locksmith:install')
            ->expectsConfirmation('Publish the configuration file?', 'no')
            ->expectsConfirmation('Publish and run database migrations?', 'no')
            ->expectsQuestion('Which recipes would you like to install?', ['aws'])
            ->assertSuccessful();

        Process::assertRan('composer require aws/aws-sdk-php');
    }

    public function test_install_command_shows_documentation_link(): void
    {
        $this->artisan('locksmith:install')
            ->expectsConfirmation('Publish the configuration file?', 'no')
            ->expectsConfirmation('Publish and run database migrations?', 'no')
            ->expectsQuestion('Which recipes would you like to install?', [])
            ->assertSuccessful()
            ->expectsOutputToContain('Documentation:');
    }
}
