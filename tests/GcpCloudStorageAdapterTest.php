<?php

namespace League\Flysystem\GcpCloudStorage;

use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Config;
use PHPUnit\Framework\TestCase;

class GcpCloudStorageAdapterTest extends TestCase
{
    /**
     * @var StorageClient
     */
    private $client;
    private $bucket;
    const PATH_PREFIX = 'path-prefix';

    /**
     * @test
     */
    public function uploadFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);

        $config = new Config();
        $adapter = new GcpCloudStorageAdapter($storage, $bucketName);
        $file = fopen(__DIR__ . "\\my_rabbit.jpg", 'r');
        $result = $adapter->write('mugi.jpg', $file, $config);

        $this->assertEquals(true, $result);
    }
}
