<?php

namespace BrainletAli\Locksmith\Recipes\Aws\Actions;

/** Create a new AWS IAM access key. */
class GenerateCredentials
{
    public function __invoke(mixed $client, string $username): string
    {
        $result = $client->createAccessKey([
            'UserName' => $username,
        ]);

        $accessKey = $result->get('AccessKey');

        return json_encode([
            'username' => $username,
            'access_key_id' => $accessKey['AccessKeyId'],
            'secret_access_key' => $accessKey['SecretAccessKey'],
        ]);
    }
}
