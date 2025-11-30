<?php

namespace BrainletAli\Locksmith\Tests\Unit\Jobs;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Jobs\RotateSecretJob;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Recipes\Aws\AwsRecipe;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

class RotateSecretJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        Secret::create(['key' => 'aws.secret', 'value' => 'old_key']);

        RotateSecretJob::dispatch('aws.secret', AwsRecipe::class, 60);

        Queue::assertPushed(RotateSecretJob::class, function ($job) {
            return $job->key === 'aws.secret'
                && $job->recipeClass === AwsRecipe::class
                && $job->gracePeriod === 60;
        });
    }

    public function test_job_rotates_secret_when_processed(): void
    {
        Secret::create(['key' => 'aws.secret', 'value' => 'old_key']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('new_aws_queued');
        $mock->shouldReceive('validate')->once()->andReturn(true);
        $this->app->instance(AwsRecipe::class, $mock);

        $job = new RotateSecretJob('aws.secret', AwsRecipe::class, 60);
        $job->handle();

        $this->assertEquals('new_aws_queued', Secret::where('key', 'aws.secret')->first()->value);
    }

    public function test_job_creates_rotation_log(): void
    {
        Secret::create(['key' => 'aws.secret', 'value' => 'old_key']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('new_key');
        $mock->shouldReceive('validate')->once()->andReturn(true);
        $this->app->instance(AwsRecipe::class, $mock);

        $job = new RotateSecretJob('aws.secret', AwsRecipe::class, 60);
        $job->handle();

        $this->assertDatabaseHas('locksmith_rotation_logs', [
            'status' => RotationStatus::Success->value,
        ]);
    }

    public function test_job_handles_missing_secret_gracefully(): void
    {
        $job = new RotateSecretJob('nonexistent.key', AwsRecipe::class, 60);
        $job->handle();

        $this->assertDatabaseCount('locksmith_rotation_logs', 0);
    }

    public function test_job_handles_validation_failure(): void
    {
        Secret::create(['key' => 'aws.secret', 'value' => 'old_key']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('invalid_key');
        $mock->shouldReceive('validate')->once()->andReturn(false);
        $this->app->instance(AwsRecipe::class, $mock);

        $job = new RotateSecretJob('aws.secret', AwsRecipe::class, 60);
        $job->handle();

        // Value should not change on validation failure
        $this->assertEquals('old_key', Secret::where('key', 'aws.secret')->first()->value);
    }
}
