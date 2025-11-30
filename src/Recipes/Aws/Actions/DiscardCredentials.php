<?php

namespace BrainletAli\Locksmith\Recipes\Aws\Actions;

use Exception;

/** Delete an old AWS IAM access key. */
class DiscardCredentials
{
    public function __invoke(mixed $client, string $username, string $value): void
    {
        $credentials = json_decode($value, true);

        if (! isset($credentials['access_key_id'])) {
            return;
        }

        try {
            $client->deleteAccessKey([
                'UserName' => $username,
                'AccessKeyId' => $credentials['access_key_id'],
            ]);
        } catch (Exception $e) {
            if (method_exists($e, 'getAwsErrorCode') && $e->getAwsErrorCode() === 'NoSuchEntity') {
                return;
            }

            throw $e;
        }
    }
}
