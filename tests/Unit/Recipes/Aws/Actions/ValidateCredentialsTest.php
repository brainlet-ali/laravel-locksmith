<?php

namespace BrainletAli\Locksmith\Tests\Unit\Recipes\Aws\Actions;

use BrainletAli\Locksmith\Recipes\Aws\Actions\ValidateCredentials;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Mockery;

class ValidateCredentialsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_false_when_access_key_id_missing(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $action = new ValidateCredentials;
        $result = $action($credentials);

        $this->assertFalse($result);
    }

    public function test_returns_false_when_secret_access_key_missing(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
        ]);

        $action = new ValidateCredentials;
        $result = $action($credentials);

        $this->assertFalse($result);
    }

    public function test_returns_false_when_json_is_invalid(): void
    {
        $action = new ValidateCredentials;
        $result = $action('not-valid-json');

        $this->assertFalse($result);
    }

    public function test_returns_false_when_credentials_are_empty(): void
    {
        $credentials = json_encode([]);

        $action = new ValidateCredentials;
        $result = $action($credentials);

        $this->assertFalse($result);
    }

    public function test_returns_true_when_sts_call_succeeds(): void
    {
        $credentials = json_encode([
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $mockStsClient = Mockery::mock();
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andReturn(['Account' => '123456789']);

        $this->app->bind(\Aws\Sts\StsClient::class, fn () => $mockStsClient);

        $action = new ValidateCredentials;
        $result = $action($credentials);

        $this->assertTrue($result);
    }

    public function test_returns_false_after_all_retries_fail(): void
    {
        config(['locksmith.aws.validation_retries' => 1]);

        $credentials = json_encode([
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $mockStsClient = Mockery::mock();
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andThrow(new Exception('Invalid credentials'));

        $this->app->bind(\Aws\Sts\StsClient::class, fn () => $mockStsClient);

        $action = new ValidateCredentials;
        $result = $action($credentials);

        $this->assertFalse($result);
    }

    public function test_retries_on_failure_before_returning_false(): void
    {
        config(['locksmith.aws.validation_retries' => 2]);

        $credentials = json_encode([
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $mockStsClient = Mockery::mock();
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->twice()
            ->andThrow(new Exception('Invalid credentials'));

        $this->app->bind(\Aws\Sts\StsClient::class, fn () => $mockStsClient);

        $action = new ValidateCredentials;
        $result = $action($credentials);

        $this->assertFalse($result);
    }

    public function test_succeeds_on_retry_after_initial_failure(): void
    {
        config(['locksmith.aws.validation_retries' => 2]);

        $credentials = json_encode([
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $mockStsClient = Mockery::mock();
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andThrow(new Exception('Temporary failure'));
        $mockStsClient->shouldReceive('getCallerIdentity')
            ->once()
            ->andReturn(['Account' => '123456789']);

        $this->app->bind(\Aws\Sts\StsClient::class, fn () => $mockStsClient);

        $action = new ValidateCredentials;
        $result = $action($credentials);

        $this->assertTrue($result);
    }
}
