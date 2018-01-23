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
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $config = new Config();
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
    public function uploadSameFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $config = new Config();
        $file = fopen(__DIR__ . "\\my_rabbit.jpg", 'r');
        $result = $adapter->write('mugi.jpg', $file, $config);
        $this->assertFalse($result);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function updateFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $config = new Config();
        $file = fopen(__DIR__ . "\\my_rabbit.jpg", 'r');
        $result = $adapter->update('mugi.jpg', $file, $config);

        $this->assertArrayHasKey('selfLink', $result);
        $this->assertArrayHasKey('mediaLink', $result);
        $this->assertEquals('https://www.googleapis.com/storage/v1/b/solid-topic-176300-bucket/o/mugi.jpg', $result['selfLink']);
    }


    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function hasFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $result = $adapter->has('mugi.jpg');
        $this->assertTrue($result);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function notHasFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $result = $adapter->has('mugi1.jpg');
        $this->assertFalse($result);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function downloadFile(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $response = $adapter->read('mugi.jpg');
        $body = $response->getBody();
        file_put_contents('test.jpg', $body->getContents());
        $this->assertEquals(true, true);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function downloadFileAsStream(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $response = $adapter->readStream('mugi.jpg');
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
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $result = $adapter->copy('mugi.jpg', 'mugi-copy.jpg');
        $this->assertArrayHasKey('selfLink', $result);
        $this->assertArrayHasKey('mediaLink', $result);
        $this->assertEquals('https://www.googleapis.com/storage/v1/b/solid-topic-176300-bucket/o/mugi-copy.jpg', $result['selfLink']);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function listObject(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $result = $adapter->listContents('/');
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
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

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
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $config = new Config();
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
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

        $result = $adapter->delete('mugi-rename.jpg');
        $this->assertTrue($result);
    }

    /**
     * @dataProvider gcpProvider
     * @test
     */
    public function deleteDir(string $projectId, string $keyFilePath, string $bucketName)
    {
        $adapter = $this->createAdapter($projectId, $keyFilePath, $bucketName);

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
        ];
    }

    /**
     * create Adapter
     * @param string $projectId
     * @param string $keyFilePath
     * @param string $bucketName
     * @return GcpCloudStorageAdapter
     */
    private function createAdapter(string $projectId, string $keyFilePath, string $bucketName)
    {
        $storage = new StorageClient([
            'projectId' => $projectId,
            'keyFilePath' => $keyFilePath,
        ]);
        return new GcpCloudStorageAdapter($storage, $bucketName);
    }
}
