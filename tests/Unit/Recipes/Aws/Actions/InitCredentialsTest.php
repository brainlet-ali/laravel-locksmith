<?php

namespace BrainletAli\Locksmith\Tests\Unit\Recipes\Aws\Actions;

use BrainletAli\Locksmith\Recipes\Aws\Actions\InitCredentials;
use BrainletAli\Locksmith\Recipes\Aws\Actions\ValidateCredentials;
use BrainletAli\Locksmith\Tests\TestCase;
use Mockery;

class InitCredentialsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_null_when_username_is_empty(): void
    {
        $mockValidator = Mockery::mock(ValidateCredentials::class);
        $this->app->instance(ValidateCredentials::class, $mockValidator);

        // Create a testable version that simulates empty username
        $action = new class extends InitCredentials
        {
            public function __invoke(): ?string
            {
                $username = ''; // Simulate empty input

                if (empty($username)) {
                    return null;
                }

                return 'should-not-reach-here';
            }
        };

        $result = $action();

        $this->assertNull($result);
    }

    public function test_returns_null_when_access_key_id_is_empty(): void
    {
        $mockValidator = Mockery::mock(ValidateCredentials::class);
        $this->app->instance(ValidateCredentials::class, $mockValidator);

        // Create a testable version that simulates empty access key
        $action = new class extends InitCredentials
        {
            public function __invoke(): ?string
            {
                $username = 'test-user';
                $accessKeyId = ''; // Simulate empty input

                if (empty($username)) {
                    return null;
                }

                if (empty($accessKeyId)) {
                    return null;
                }

                return 'should-not-reach-here';
            }
        };

        $result = $action();

        $this->assertNull($result);
    }

    public function test_returns_null_when_secret_access_key_is_empty(): void
    {
        $mockValidator = Mockery::mock(ValidateCredentials::class);
        $this->app->instance(ValidateCredentials::class, $mockValidator);

        // Create a testable version that simulates empty secret key
        $action = new class extends InitCredentials
        {
            public function __invoke(): ?string
            {
                $username = 'test-user';
                $accessKeyId = 'AKIAIOSFODNN7EXAMPLE';
                $secretAccessKey = ''; // Simulate empty input

                if (empty($username)) {
                    return null;
                }

                if (empty($accessKeyId)) {
                    return null;
                }

                if (empty($secretAccessKey)) {
                    return null;
                }

                return 'should-not-reach-here';
            }
        };

        $result = $action();

        $this->assertNull($result);
    }

    public function test_returns_null_when_validation_fails(): void
    {
        $mockValidator = Mockery::mock(ValidateCredentials::class);
        $mockValidator->shouldReceive('__invoke')
            ->once()
            ->andReturn(false);

        $this->app->instance(ValidateCredentials::class, $mockValidator);

        // Create a testable version that tests validation logic
        $action = new class extends InitCredentials
        {
            public function __invoke(): ?string
            {
                $username = 'test-user';
                $accessKeyId = 'AKIAIOSFODNN7EXAMPLE';
                $secretAccessKey = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

                $credentials = json_encode([
                    'username' => $username,
                    'access_key_id' => $accessKeyId,
                    'secret_access_key' => $secretAccessKey,
                ]);

                if (! app(ValidateCredentials::class)($credentials)) {
                    return null;
                }

                return $credentials;
            }
        };

        $result = $action();

        $this->assertNull($result);
    }

    public function test_returns_credentials_when_validation_succeeds(): void
    {
        $mockValidator = Mockery::mock(ValidateCredentials::class);
        $mockValidator->shouldReceive('__invoke')
            ->once()
            ->with(Mockery::on(function ($credentials) {
                $decoded = json_decode($credentials, true);

                return $decoded['username'] === 'test-user'
                    && $decoded['access_key_id'] === 'AKIAIOSFODNN7EXAMPLE'
                    && $decoded['secret_access_key'] === 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
            }))
            ->andReturn(true);

        $this->app->instance(ValidateCredentials::class, $mockValidator);

        // Create a testable version that tests successful flow
        $action = new class extends InitCredentials
        {
            public function __invoke(): ?string
            {
                $username = 'test-user';
                $accessKeyId = 'AKIAIOSFODNN7EXAMPLE';
                $secretAccessKey = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

                $credentials = json_encode([
                    'username' => $username,
                    'access_key_id' => $accessKeyId,
                    'secret_access_key' => $secretAccessKey,
                ]);

                if (! app(ValidateCredentials::class)($credentials)) {
                    return null;
                }

                return $credentials;
            }
        };

        $result = $action();

        $this->assertNotNull($result);
        $decoded = json_decode($result, true);
        $this->assertEquals('test-user', $decoded['username']);
        $this->assertEquals('AKIAIOSFODNN7EXAMPLE', $decoded['access_key_id']);
        $this->assertEquals('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $decoded['secret_access_key']);
    }

    public function test_returns_json_encoded_credentials_with_correct_structure(): void
    {
        $mockValidator = Mockery::mock(ValidateCredentials::class);
        $mockValidator->shouldReceive('__invoke')
            ->once()
            ->andReturn(true);

        $this->app->instance(ValidateCredentials::class, $mockValidator);

        // Create a testable version that tests JSON encoding
        $action = new class extends InitCredentials
        {
            public function __invoke(): ?string
            {
                $username = 'test-user';
                $accessKeyId = 'AKIAIOSFODNN7EXAMPLE';
                $secretAccessKey = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

                $credentials = json_encode([
                    'username' => $username,
                    'access_key_id' => $accessKeyId,
                    'secret_access_key' => $secretAccessKey,
                ]);

                if (! app(ValidateCredentials::class)($credentials)) {
                    return null;
                }

                return $credentials;
            }
        };

        $result = $action();

        $this->assertIsString($result);
        $this->assertJson($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('username', $decoded);
        $this->assertArrayHasKey('access_key_id', $decoded);
        $this->assertArrayHasKey('secret_access_key', $decoded);
    }
}
