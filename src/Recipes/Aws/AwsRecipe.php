<?php

namespace BrainletAli\Locksmith\Recipes\Aws;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Contracts\InitializableRecipe;
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Recipes\Aws\Actions\DiscardCredentials;
use BrainletAli\Locksmith\Recipes\Aws\Actions\GenerateCredentials;
use BrainletAli\Locksmith\Recipes\Aws\Actions\InitCredentials;
use BrainletAli\Locksmith\Recipes\Aws\Actions\ValidateCredentials;
use RuntimeException;

/** AWS IAM access key rotation recipe. */
class AwsRecipe implements DiscardableRecipe, InitializableRecipe, Recipe
{
    protected mixed $iamClient = null;

    protected ?string $username = null;

    protected string $secretKey = 'aws.credentials';

    /** Prompt user for initial AWS IAM credentials. */
    public function init(): ?string
    {
        return app(InitCredentials::class)();
    }

    /** Create a new AWS IAM access key. */
    public function generate(): string
    {
        return app(GenerateCredentials::class)(
            $this->getIamClient(),
            $this->getUsername()
        );
    }

    /** Validate AWS credentials using STS GetCallerIdentity. */
    public function validate(string $value): bool
    {
        return app(ValidateCredentials::class)($value);
    }

    /** Delete an old AWS IAM access key. */
    public function discard(string $value): void
    {
        $credentials = json_decode($value, true);

        if (! isset($credentials['access_key_id'])) {
            return;
        }

        app(DiscardCredentials::class)(
            $this->getIamClient(),
            $this->getUsername(),
            $value
        );
    }

    /** Set the secret key used to store credentials. */
    public function setSecretKey(string $key): self
    {
        $this->secretKey = $key;

        return $this;
    }

    /** Set a custom IAM client (for testing). */
    public function setIamClient(mixed $client): self
    {
        $this->iamClient = $client;

        return $this;
    }

    /** Get the IAM username from stored credentials. */
    protected function getUsername(): string
    {
        if ($this->username) {
            return $this->username;
        }

        $credentials = $this->getStoredCredentials();

        if (! $credentials) {
            throw new RuntimeException(
                'No AWS credentials found. Run: php artisan locksmith:init '.$this->secretKey
            );
        }

        if (! isset($credentials['username'])) {
            throw new RuntimeException(
                'No IAM username found in stored credentials. Re-initialize with: php artisan locksmith:init '.$this->secretKey
            );
        }

        return $this->username = $credentials['username'];
    }

    /** Get stored credentials from Locksmith. */
    protected function getStoredCredentials(): ?array
    {
        $stored = Locksmith::get($this->secretKey);

        if (! $stored) {
            return null;
        }

        $credentials = json_decode($stored, true);

        if (! isset($credentials['access_key_id'], $credentials['secret_access_key'])) {
            return null;
        }

        return $credentials;
    }

    /** Get the IAM client instance. */
    protected function getIamClient(): mixed
    {
        if ($this->iamClient) {
            return $this->iamClient;
        }

        $stored = $this->getStoredCredentials();

        if (! $stored) {
            throw new RuntimeException(
                'No AWS credentials found. Run: php artisan locksmith:init '.$this->secretKey
            );
        }

        return $this->iamClient = app(\Aws\Iam\IamClient::class, [
            'args' => [
                'version' => 'latest',
                'region' => config('locksmith.aws.region', 'us-east-1'),
                'credentials' => [
                    'key' => $stored['access_key_id'],
                    'secret' => $stored['secret_access_key'],
                ],
            ],
        ]);
    }
}
