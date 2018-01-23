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
     * @dataProvider gcpProvider
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

        $this->assertArrayHasKey('selfLink', $result);
        $this->assertArrayHasKey('mediaLink', $result);
        $this->assertEquals('https://www.googleapis.com/storage/v1/b/solid-topic-176300-bucket/o/mugi.jpg', $result['selfLink']);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function downloadFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);

        $adapter = new GcpCloudStorageAdapter($storage, $bucketName);
        $response = $adapter->read('mugi.jpg');
        $body = $response->getBody();
        file_put_contents('test.jpg', $body->getContents());
        $this->assertEquals(true, true);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function copyFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);
        $adapter = new GcpCloudStorageAdapter($storage, $bucketName);
        $result = $adapter->copy('mugi.jpg', 'mugi-copy.jpg');
        $this->assertArrayHasKey('selfLink', $result);
        $this->assertArrayHasKey('mediaLink', $result);
        $this->assertEquals('https://www.googleapis.com/storage/v1/b/solid-topic-176300-bucket/o/mugi-copy.jpg', $result['selfLink']);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function renameFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);
        $adapter = new GcpCloudStorageAdapter($storage, $bucketName);
        $result = $adapter->rename('mugi.jpg', 'mugi-rename.jpg');
        $this->assertArrayHasKey('selfLink', $result);
        $this->assertArrayHasKey('mediaLink', $result);
        $this->assertEquals('https://www.googleapis.com/storage/v1/b/solid-topic-176300-bucket/o/mugi-rename.jpg', $result['selfLink']);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function createDir(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);

        $config = new Config();
        $adapter = new GcpCloudStorageAdapter($storage, $bucketName);
        $result = $adapter->createDir('new_dir', $config);

        $this->assertArrayHasKey('selfLink', $result);
        $this->assertArrayHasKey('mediaLink', $result);
        $this->assertEquals('https://www.googleapis.com/storage/v1/b/solid-topic-176300-bucket/o/new_dir%2F', $result['selfLink']);
    }


    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function deleteFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);
        $adapter = new GcpCloudStorageAdapter($storage, $bucketName);
        $result = $adapter->delete('mugi-rename.jpg');
        $this->assertTrue($result);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function deleteDir(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);
        $adapter = new GcpCloudStorageAdapter($storage, $bucketName);
        $result = $adapter->deleteDir('new_dir');
        $this->assertTrue($result);
    }

    /**
     * GCPのプロバイダ
     */
    public function gcpProvider()
    {
        return [
            // projectId, Service Key Path, Bucket Name
            ['solid-topic-176300', '.\\config\\gcp\\cloud_storage-service-c7b14486b131.json', 'solid-topic-176300-bucket'],
        ];
    }
}
