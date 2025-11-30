<?php

namespace BrainletAli\Locksmith\Recipes\Aws\Actions;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Prompt user for initial AWS IAM credentials. */
class InitCredentials
{
    public function __invoke(): ?string
    {
        // @codeCoverageIgnoreStart
        $username = text(label: 'IAM Username', required: true);
        // @codeCoverageIgnoreEnd
        if (empty($username)) {
            return null;
        }

        // @codeCoverageIgnoreStart
        $accessKeyId = password(label: 'Access Key ID');
        // @codeCoverageIgnoreEnd
        if (empty($accessKeyId)) {
            return null;
        }

        // @codeCoverageIgnoreStart
        $secretAccessKey = password(label: 'Secret Access Key');
        // @codeCoverageIgnoreEnd
        if (empty($secretAccessKey)) {
            return null;
        }

        $credentials = json_encode([
            'username' => $username,
            'access_key_id' => $accessKeyId,
            'secret_access_key' => $secretAccessKey,
        ]);

        // @codeCoverageIgnoreStart
        info('Validating credentials...');
        // @codeCoverageIgnoreEnd

        if (! app(ValidateCredentials::class)($credentials)) {
            // @codeCoverageIgnoreStart
            error('Invalid credentials. Please check your Access Key ID and Secret Access Key.');
            // @codeCoverageIgnoreEnd

            return null;
        }

        // @codeCoverageIgnoreStart
        info('Credentials validated successfully.');
        // @codeCoverageIgnoreEnd

        return $credentials;
    }
}
