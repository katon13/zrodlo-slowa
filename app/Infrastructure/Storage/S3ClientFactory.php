<?php
declare(strict_types=1);

namespace App\Infrastructure\Storage;

use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;

final class S3ClientFactory
{
    public static function create(array $config): S3ClientInterface
    {
        $options = [
            'version' => 'latest',
            'region' => (string)($config['region'] ?? 'us-east-1'),
            'use_path_style_endpoint' => (bool)($config['path_style'] ?? false),
            'retries' => [
                'mode' => 'standard',
                'max_attempts' => max(1, (int)($config['max_attempts'] ?? 3)),
            ],
            'http' => [
                'connect_timeout' => max(0.1, (float)($config['connect_timeout'] ?? 2.0)),
                'timeout' => max(1.0, (float)($config['request_timeout'] ?? 10.0)),
            ],
        ];

        $endpoint = trim((string)($config['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $options['endpoint'] = $endpoint;
        }

        $accessKey = trim((string)($config['access_key'] ?? ''));
        $secretKey = (string)($config['secret_key'] ?? '');
        if ($accessKey !== '' || $secretKey !== '') {
            if ($accessKey === '' || $secretKey === '') {
                throw new \RuntimeException('S3_ACCESS_KEY i S3_SECRET_KEY muszą być ustawione razem.');
            }
            $options['credentials'] = [
                'key' => $accessKey,
                'secret' => $secretKey,
            ];
        }

        return new S3Client($options);
    }
}
