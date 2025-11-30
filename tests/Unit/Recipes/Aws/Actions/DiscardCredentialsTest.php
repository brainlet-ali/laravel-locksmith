<?php

namespace BrainletAli\Locksmith\Tests\Unit\Recipes\Aws\Actions;

use BrainletAli\Locksmith\Recipes\Aws\Actions\DiscardCredentials;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Mockery;

class DiscardCredentialsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_discards_credentials_successfully(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIAOLDKEY123',
            'secret_access_key' => 'oldSecretKey123',
        ]);

        $mockClient = Mockery::mock();
        $mockClient->shouldReceive('deleteAccessKey')
            ->once()
            ->with([
                'UserName' => 'test-user',
                'AccessKeyId' => 'AKIAOLDKEY123',
            ])
            ->andReturn(null);

        $action = new DiscardCredentials;
        $action($mockClient, 'test-user', $credentials);

        $this->assertTrue(true);
    }

    public function test_handles_missing_access_key_id(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'secret_access_key' => 'oldSecretKey123',
        ]);

        $mockClient = Mockery::mock();
        $mockClient->shouldNotReceive('deleteAccessKey');

        $action = new DiscardCredentials;
        $action($mockClient, 'test-user', $credentials);

        $this->assertTrue(true);
    }

    public function test_handles_no_such_entity_exception(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIAOLDKEY123',
            'secret_access_key' => 'oldSecretKey123',
        ]);

        $mockException = new class('Key not found') extends Exception
        {
            public function getAwsErrorCode(): string
            {
                return 'NoSuchEntity';
            }
        };

        $mockClient = Mockery::mock();
        $mockClient->shouldReceive('deleteAccessKey')
            ->once()
            ->andThrow($mockException);

        $action = new DiscardCredentials;
        $action($mockClient, 'test-user', $credentials);

        $this->assertTrue(true);
    }

    public function test_rethrows_other_exceptions(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIAOLDKEY123',
            'secret_access_key' => 'oldSecretKey123',
        ]);

        $mockException = new class('Access denied') extends Exception
        {
            public function getAwsErrorCode(): string
            {
                return 'AccessDenied';
            }
        };

        $mockClient = Mockery::mock();
        $mockClient->shouldReceive('deleteAccessKey')
            ->once()
            ->andThrow($mockException);

        $action = new DiscardCredentials;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Access denied');

        $action($mockClient, 'test-user', $credentials);
    }

    public function test_rethrows_exception_without_aws_error_code(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIAOLDKEY123',
            'secret_access_key' => 'oldSecretKey123',
        ]);

        $mockClient = Mockery::mock();
        $mockClient->shouldReceive('deleteAccessKey')
            ->once()
            ->andThrow(new Exception('Generic error'));

        $action = new DiscardCredentials;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Generic error');

        $action($mockClient, 'test-user', $credentials);
    }
}
