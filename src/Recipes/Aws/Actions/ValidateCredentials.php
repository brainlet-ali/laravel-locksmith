<?php

namespace BrainletAli\Locksmith\Recipes\Aws\Actions;

use Exception;

/** Validate AWS credentials using STS GetCallerIdentity. */
class ValidateCredentials
{
    public function __invoke(string $value): bool
    {
        $credentials = json_decode($value, true);

        if (! isset($credentials['access_key_id'], $credentials['secret_access_key'])) {
            return false;
        }

        $stsClient = app(\Aws\Sts\StsClient::class, [
            'args' => [
                'version' => 'latest',
                'region' => config('locksmith.aws.region', 'us-east-1'),
                'credentials' => [
                    'key' => $credentials['access_key_id'],
                    'secret' => $credentials['secret_access_key'],
                ],
            ],
        ]);

        $retries = (int) config('locksmith.aws.validation_retries', 3);

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                $stsClient->getCallerIdentity();

                return true;
            } catch (Exception $e) {
                if ($attempt < $retries) {
                    sleep(5);
                }
            }
        }

        return false;
    }
}
