<?php

namespace BrainletAli\Locksmith\Tests\Unit\Recipes\Aws\Actions;

use Aws\Iam\IamClient;
use BrainletAli\Locksmith\Recipes\Aws\Actions\GenerateCredentials;
use BrainletAli\Locksmith\Tests\TestCase;
use Mockery;

class GenerateCredentialsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generates_credentials_successfully(): void
    {
        $mockResult = Mockery::mock();
        $mockResult->shouldReceive('get')
            ->with('AccessKey')
            ->andReturn([
                'AccessKeyId' => 'AKIANEWKEY123',
                'SecretAccessKey' => 'newSecretKey123',
            ]);

        $mockClient = Mockery::mock(IamClient::class);
        $mockClient->shouldReceive('createAccessKey')
            ->once()
            ->with(['UserName' => 'test-user'])
            ->andReturn($mockResult);

        $action = new GenerateCredentials;
        $result = $action($mockClient, 'test-user');

        $credentials = json_decode($result, true);

        $this->assertEquals('test-user', $credentials['username']);
        $this->assertEquals('AKIANEWKEY123', $credentials['access_key_id']);
        $this->assertEquals('newSecretKey123', $credentials['secret_access_key']);
    }
}
