<?php

namespace League\Flysystem\GcpCloudStorage;

use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Psr\Http\Message\StreamInterface;

class GcpCloudStorageAdapter extends AbstractAdapter implements CanOverwriteFiles
{
    /**
     * @var StorageClient
     */
    protected $gcpClient;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * Constructor.
     *
     * @param StorageClient $client
     * @param string $bucket
     * @param string $prefix
     * @param array $options
     */
    public function __construct(StorageClient $client, $bucket, $prefix = '', array $options = [])
    {
        $this->gcpClient = $client;
        $this->bucket = $bucket;
        $this->setPathPrefix($prefix);
        $this->options = $options;
    }

    /**
     * Get the S3Client bucket.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Get the S3Client instance.
     *
     * @return StorageClient
     */
    public function getClient()
    {
        return $this->gcpClient;
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        try {
            if (!$this->has($path)) {
                return $this->upload($path, $contents, $config);
            }
        } catch (\InvalidArgumentException $e) {
            error_log($e->getMessage());
        }

        return false;
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return false|array false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return array
     */
    public function rename($path, $newpath)
    {
        $result = $this->copy($path, $newpath);
        $this->delete($path);

        return $result;
    }

    /**
     * Delete a file.
     *
     * @param string $objectName
     *
     * @return bool
     */
    public function delete($objectName)
    {
        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->object($objectName);
        $object->delete();

        return true;
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $this->delete($dirname . '/');

        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return bool|array
     * @throws \InvalidArgumentException
     */
    public function createDir($dirname, Config $config)
    {
        return $this->upload($dirname . '/', '', $config);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {

        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->object($path);

        return $object->exists();
    }

    /**
     * Read a file.
     *
     * @param string $objectName
     *
     * @return Response
     */
    public function read($objectName)
    {
        return $this->readObject($objectName);
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->applyPathPrefix($directory);
        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $objects = $bucket->objects(['prefix' => $directory]);
        $normalised = [];
        foreach ($objects as $object) {
            $normalised[] = $this->normaliseObject($object);
        }

        return Util::emulateDirectories($normalised);
    }

    /**
     * Returns a dictionary of object metadata from an object.
     *
     * @param StorageObject $object
     *
     * @return array
     */
    protected function normaliseObject(StorageObject $object)
    {
        $name = $this->removePathPrefix($object->name());
        $info = $object->info();
        $isDir = substr($name, -1) === '/';
        if ($isDir) {
            $name = rtrim($name, '/');
        }

        return [
            'type' => $isDir ? 'dir' : 'file',
            'dirname' => Util::dirname($name),
            'path' => $name,
            'timestamp' => strtotime($info['updated']),
            'mime-type' => $info['contentType'] ?? '',
            'size' => $info['size'],
        ];
    }


    /**
     * @param array $options
     *
     * @return array
     */
    protected function retrievePaginatedListing(array $options)
    {
        $resultPaginator = $this->gcpClient->getPaginator('ListObjects', $options);
        $listing = [];

        foreach ($resultPaginator as $result) {
            $listing = array_merge($listing, $result->get('Contents') ?: [], $result->get('CommonPrefixes') ?: []);
        }

        return $listing;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getMetadata($path)
    {
        $command = $this->gcpClient->getCommand(
            'headObject',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ] + $this->options
        );

        /* @var Result $result */
        try {
            $result = $this->gcpClient->execute($command);
        } catch (S3Exception $exception) {
            $response = $exception->getResponse();

            if ($response !== null && $response->getStatusCode() === 404) {
                return false;
            }

            throw $exception;
        }

        return $this->normalizeResponse($result->toArray(), $path);
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Copy a file.
     *
     * @param string $objectName
     * @param string $newObjectName
     *
     * @return array
     */
    public function copy($objectName, $newObjectName)
    {
        //@todo: Copy across buckets

        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->object($objectName);
        $copyObject = $object->copy($this->getBucket(), ['name' => $newObjectName]);
        $info = $copyObject->info();
        $result = [
            'selfLink' => $info['selfLink'],
            'mediaLink' => $info['mediaLink'],
        ];

        return $result;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return Response
     */
    public function readStream($path)
    {
        return $this->readObject($path);
    }

    /**
     * Read an object and normalize the response.
     *
     * @param $path
     *
     * @return Response
     */
    protected function readObject($objectName)
    {
        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->object($objectName);
        $stream = $object->downloadAsStream();

        return $this->normalizeResponse($stream);
    }

    /**
     * @inheritdoc
     * @throws \InvalidArgumentException
     */
    public function setVisibility($path, $visibility)
    {
        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->object($path);
        if ($visibility === 'public') {
            $info = $object->update(['acl' => []], ['predefinedAcl' => 'PUBLICREAD']);
        } else if($visibility === 'private') {
            $info = $object->update(['acl' => []], ['predefinedAcl' => 'private']);
        } else {
            throw new \InvalidArgumentException("Set visibility 'public' or 'private'");
        }

        return [];
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function applyPathPrefix($path)
    {
        return ltrim(parent::applyPathPrefix($path), '/');
    }

    /**
     * {@inheritdoc}
     */
    public function setPathPrefix($prefix)
    {
        $prefix = ltrim($prefix, '/');

        return parent::setPathPrefix($prefix);
    }

    /**
     * Get the object acl presented as a visibility.
     *
     * @param string $path
     *
     * @return string
     */
    protected function getRawVisibility($path)
    {
        $command = $this->gcpClient->getCommand(
            'getObjectAcl',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]
        );

        $result = $this->gcpClient->execute($command);
        $visibility = AdapterInterface::VISIBILITY_PRIVATE;

        foreach ($result->get('Grants') as $grant) {
            if (
                isset($grant['Grantee']['URI'])
                && $grant['Grantee']['URI'] === self::PUBLIC_GRANT_URI
                && $grant['Permission'] === 'READ'
            ) {
                $visibility = AdapterInterface::VISIBILITY_PUBLIC;
                break;
            }
        }

        return $visibility;
    }

    /**
     * Upload an object.
     *
     * @param        $path
     * @param        $body
     * @param Config $config
     *
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function upload($path, $body, Config $config)
    {
        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->upload($body, [
            'name' => $path,
        ]);
        $info = $object->info();
        $result = [
            'selfLink' => $info['selfLink'],
            'mediaLink' => $info['mediaLink'],
        ];

        return $result;
    }

    /**
     * Get options from the config.
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = $this->options;

        if ($visibility = $config->get('visibility')) {
            // For local reference
            $options['visibility'] = $visibility;
            // For external reference
            $options['ACL'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private';
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            $options['mimetype'] = $mimetype;
            // For external reference
            $options['ContentType'] = $mimetype;
        }

        foreach (static::$metaOptions as $option) {
            if (!$config->has($option)) {
                continue;
            }
            $options[$option] = $config->get($option);
        }

        return $options;
    }

    /**
     * Normalize the object result array.
     *
     * @param StreamInterface $stream
     * @param string $path
     *
     * @return Response
     */
    protected function normalizeResponse(StreamInterface $stream, $path = null)
    {
        $response = new Response();

        return $response->withBody($stream);
    }

    /**
     * @param $location
     *
     * @return bool
     */
    protected function doesDirectoryExist($location)
    {
        // Maybe this isn't an actual key, but a prefix.
        // Do a prefix listing of objects to determine.
        $command = $this->gcpClient->getCommand(
            'listObjects',
            [
                'Bucket' => $this->bucket,
                'Prefix' => rtrim($location, '/') . '/',
                'MaxKeys' => 1,
            ]
        );

        try {
            $result = $this->gcpClient->execute($command);

            return $result['Contents'] || $result['CommonPrefixes'];
            // todo:例外クラスを具体的なクラスにする
        } catch (\Exception $e) {
            if ($e->getStatusCode() === 403) {
                return false;
            }

            throw $e;
        }
    }
}
