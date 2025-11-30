<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use Aws\Iam\IamClient;
use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Contracts\InitializableRecipe;
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Recipes\Aws\Actions\DiscardCredentials;
use BrainletAli\Locksmith\Recipes\Aws\Actions\GenerateCredentials;
use BrainletAli\Locksmith\Recipes\Aws\Actions\InitCredentials;
use BrainletAli\Locksmith\Recipes\Aws\Actions\ValidateCredentials;
use BrainletAli\Locksmith\Recipes\Aws\AwsRecipe;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;

class AwsRecipeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_aws_recipe_implements_recipe_contract(): void
    {
        $recipe = new AwsRecipe;

        $this->assertInstanceOf(Recipe::class, $recipe);
    }

    public function test_aws_recipe_implements_discardable_recipe_contract(): void
    {
        $recipe = new AwsRecipe;

        $this->assertInstanceOf(DiscardableRecipe::class, $recipe);
    }

    public function test_aws_recipe_implements_initializable_recipe_contract(): void
    {
        $recipe = new AwsRecipe;

        $this->assertInstanceOf(InitializableRecipe::class, $recipe);
    }

    public function test_aws_recipe_discard_handles_missing_key_id(): void
    {
        $credentials = json_encode([
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $recipe = new AwsRecipe;

        // Should not throw - gracefully handles missing access_key_id
        $recipe->discard($credentials);
        $this->assertTrue(true);
    }

    public function test_aws_recipe_can_set_secret_key(): void
    {
        $recipe = new AwsRecipe;

        $result = $recipe->setSecretKey('custom.aws.key');

        $this->assertInstanceOf(AwsRecipe::class, $result);
    }

    public function test_aws_recipe_can_set_iam_client(): void
    {
        $mockClient = Mockery::mock(IamClient::class);

        $recipe = new AwsRecipe;
        $result = $recipe->setIamClient($mockClient);

        $this->assertInstanceOf(AwsRecipe::class, $result);
    }

    public function test_aws_recipe_init_calls_init_credentials_action(): void
    {
        $expectedResult = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIATEST123',
            'secret_access_key' => 'testSecret123',
        ]);

        $mockAction = Mockery::mock(InitCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->andReturn($expectedResult);

        $this->app->instance(InitCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $result = $recipe->init();

        $this->assertEquals($expectedResult, $result);
    }

    public function test_aws_recipe_generate_calls_generate_credentials_action(): void
    {
        // Setup stored credentials
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'username' => 'test-user',
                'access_key_id' => 'AKIAOLDKEY123',
                'secret_access_key' => 'oldSecret123',
            ]),
        ]);

        $expectedResult = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIANEWKEY123',
            'secret_access_key' => 'newSecret123',
        ]);

        $mockClient = Mockery::mock(IamClient::class);

        $mockAction = Mockery::mock(GenerateCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->with($mockClient, 'test-user')
            ->andReturn($expectedResult);

        $this->app->instance(GenerateCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $recipe->setIamClient($mockClient);
        $result = $recipe->generate();

        $this->assertEquals($expectedResult, $result);
    }

    public function test_aws_recipe_validate_calls_validate_credentials_action(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIATEST123',
            'secret_access_key' => 'testSecret123',
        ]);

        $mockAction = Mockery::mock(ValidateCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->with($credentials)
            ->andReturn(true);

        $this->app->instance(ValidateCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $result = $recipe->validate($credentials);

        $this->assertTrue($result);
    }

    public function test_aws_recipe_discard_calls_discard_credentials_action(): void
    {
        // Setup stored credentials
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'username' => 'test-user',
                'access_key_id' => 'AKIAOLDKEY123',
                'secret_access_key' => 'oldSecret123',
            ]),
        ]);

        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIADISCARD123',
            'secret_access_key' => 'discardSecret123',
        ]);

        $mockClient = Mockery::mock(IamClient::class);

        $mockAction = Mockery::mock(DiscardCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->with($mockClient, 'test-user', $credentials);

        $this->app->instance(DiscardCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $recipe->setIamClient($mockClient);
        $recipe->discard($credentials);

        $this->assertTrue(true);
    }

    public function test_aws_recipe_throws_when_no_credentials_stored(): void
    {
        $mockClient = Mockery::mock(IamClient::class);

        $recipe = new AwsRecipe;
        $recipe->setIamClient($mockClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No AWS credentials found');

        $recipe->generate();
    }

    public function test_aws_recipe_throws_when_no_username_in_credentials(): void
    {
        // Setup stored credentials without username
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'access_key_id' => 'AKIATEST123',
                'secret_access_key' => 'testSecret123',
            ]),
        ]);

        $mockClient = Mockery::mock(IamClient::class);

        $recipe = new AwsRecipe;
        $recipe->setIamClient($mockClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No IAM username found');

        $recipe->generate();
    }

    public function test_aws_recipe_get_stored_credentials_returns_null_for_invalid_json(): void
    {
        // Setup invalid credentials (missing required fields)
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode(['invalid' => 'data']),
        ]);

        $mockClient = Mockery::mock(IamClient::class);

        $recipe = new AwsRecipe;
        $recipe->setIamClient($mockClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No AWS credentials found');

        $recipe->generate();
    }

    public function test_aws_recipe_caches_username(): void
    {
        // Setup stored credentials
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'username' => 'test-user',
                'access_key_id' => 'AKIATEST123',
                'secret_access_key' => 'testSecret123',
            ]),
        ]);

        $mockClient = Mockery::mock(IamClient::class);

        $mockAction = Mockery::mock(GenerateCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->twice()
            ->with($mockClient, 'test-user')
            ->andReturn('{}');

        $this->app->instance(GenerateCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $recipe->setIamClient($mockClient);

        // Call generate twice - username should be cached
        $recipe->generate();
        $recipe->generate();

        $this->assertTrue(true);
    }

    public function test_aws_recipe_creates_iam_client_via_container(): void
    {
        // Setup stored credentials
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'username' => 'test-user',
                'access_key_id' => 'AKIATEST123',
                'secret_access_key' => 'testSecret123',
            ]),
        ]);

        $mockClient = Mockery::mock();

        $this->app->bind(IamClient::class, fn () => $mockClient);

        $mockAction = Mockery::mock(GenerateCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->with($mockClient, 'test-user')
            ->andReturn('{}');

        $this->app->instance(GenerateCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $recipe->generate();

        $this->assertTrue(true);
    }

    public function test_aws_recipe_caches_iam_client(): void
    {
        // Setup stored credentials
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'username' => 'test-user',
                'access_key_id' => 'AKIATEST123',
                'secret_access_key' => 'testSecret123',
            ]),
        ]);

        $mockClient = Mockery::mock();
        $callCount = 0;

        $this->app->bind(IamClient::class, function () use ($mockClient, &$callCount) {
            $callCount++;

            return $mockClient;
        });

        $mockAction = Mockery::mock(GenerateCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->twice()
            ->andReturn('{}');

        $this->app->instance(GenerateCredentials::class, $mockAction);

        $recipe = new AwsRecipe;

        // Call generate twice - IamClient should be cached (container called only once)
        $recipe->generate();
        $recipe->generate();

        $this->assertEquals(1, $callCount);
    }

    public function test_aws_recipe_throws_when_iam_client_needed_but_no_credentials(): void
    {
        // No credentials stored, no client pre-set
        $mockAction = Mockery::mock(GenerateCredentials::class);

        $this->app->instance(GenerateCredentials::class, $mockAction);

        $recipe = new AwsRecipe;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No AWS credentials found');

        // This should trigger getIamClient() which will throw
        $recipe->generate();
    }

    public function test_aws_recipe_discard_with_custom_secret_key(): void
    {
        // Setup stored credentials with custom key
        Secret::create([
            'key' => 'custom.aws.key',
            'value' => json_encode([
                'username' => 'custom-user',
                'access_key_id' => 'AKIACUSTOM123',
                'secret_access_key' => 'customSecret123',
            ]),
        ]);

        $credentials = json_encode([
            'username' => 'custom-user',
            'access_key_id' => 'AKIADISCARD123',
            'secret_access_key' => 'discardSecret123',
        ]);

        $mockClient = Mockery::mock(IamClient::class);

        $mockAction = Mockery::mock(DiscardCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->with($mockClient, 'custom-user', $credentials);

        $this->app->instance(DiscardCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $recipe->setSecretKey('custom.aws.key');
        $recipe->setIamClient($mockClient);
        $recipe->discard($credentials);

        $this->assertTrue(true);
    }

    public function test_aws_recipe_uses_custom_aws_region_from_config(): void
    {
        // Setup stored credentials
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'username' => 'test-user',
                'access_key_id' => 'AKIATEST123',
                'secret_access_key' => 'testSecret123',
            ]),
        ]);

        config(['locksmith.aws.region' => 'eu-west-1']);

        $mockClient = Mockery::mock();
        $capturedArgs = null;

        $this->app->bind(IamClient::class, function ($app, $params) use ($mockClient, &$capturedArgs) {
            $capturedArgs = $params;

            return $mockClient;
        });

        $mockAction = Mockery::mock(GenerateCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->with($mockClient, 'test-user')
            ->andReturn('{}');

        $this->app->instance(GenerateCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        $recipe->generate();

        $this->assertEquals('eu-west-1', $capturedArgs['args']['region']);
    }

    public function test_aws_recipe_discard_creates_iam_client_when_not_preset(): void
    {
        // Setup stored credentials
        Secret::create([
            'key' => 'aws.credentials',
            'value' => json_encode([
                'username' => 'test-user',
                'access_key_id' => 'AKIASTORED123',
                'secret_access_key' => 'storedSecret123',
            ]),
        ]);

        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIADISCARD123',
            'secret_access_key' => 'discardSecret123',
        ]);

        $mockClient = Mockery::mock();

        $this->app->bind(IamClient::class, fn () => $mockClient);

        $mockAction = Mockery::mock(DiscardCredentials::class);
        $mockAction->shouldReceive('__invoke')
            ->once()
            ->with($mockClient, 'test-user', $credentials);

        $this->app->instance(DiscardCredentials::class, $mockAction);

        $recipe = new AwsRecipe;
        // Don't preset IAM client - should create it via container
        $recipe->discard($credentials);

        $this->assertTrue(true);
    }
}
