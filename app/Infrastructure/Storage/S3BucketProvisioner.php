<?php
declare(strict_types=1);

namespace App\Infrastructure\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3ClientInterface;

final class S3BucketProvisioner
{
    public function __construct(
        private readonly S3ClientInterface $client,
        private readonly string $bucket,
        private readonly string $region,
    ) {}

    public function provision(): string
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
            return 'already_exists';
        } catch (AwsException $error) {
            if (
                $error->getStatusCode() !== 404
                && !in_array((string)$error->getAwsErrorCode(), ['NoSuchBucket', 'NotFound'], true)
            ) {
                throw new \RuntimeException('Nie udało się sprawdzić bucketu S3.', 0, $error);
            }
        }

        $parameters = ['Bucket' => $this->bucket];
        if ($this->region !== '' && $this->region !== 'us-east-1') {
            $parameters['CreateBucketConfiguration'] = [
                'LocationConstraint' => $this->region,
            ];
        }
        try {
            $this->client->createBucket($parameters);
            $this->client->waitUntil('BucketExists', ['Bucket' => $this->bucket]);
            return 'created';
        } catch (AwsException $error) {
            throw new \RuntimeException('Nie udało się utworzyć bucketu S3.', 0, $error);
        }
    }
}
