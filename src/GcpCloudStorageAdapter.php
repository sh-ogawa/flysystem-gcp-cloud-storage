<?php

namespace League\Flysystem\GcpCloudStorage;

use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Psr\Http\Message\StreamInterface;

class GcpCloudStorageAdapter extends AbstractAdapter implements CanOverwriteFiles
{
    const PUBLIC_GRANT_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    /**
     * @var array
     */
    protected static $resultMap = [
        'Body' => 'contents',
        'ContentLength' => 'size',
        'ContentType' => 'mimetype',
        'Size' => 'size',
        'Metadata' => 'metadata',
    ];

    /**
     * @var array
     */
    protected static $metaOptions = [
        'ACL',
        'CacheControl',
        'ContentDisposition',
        'ContentEncoding',
        'ContentLength',
        'ContentType',
        'Expires',
        'GrantFullControl',
        'GrantRead',
        'GrantReadACP',
        'GrantWriteACP',
        'Metadata',
        'RequestPayer',
        'SSECustomerAlgorithm',
        'SSECustomerKey',
        'SSECustomerKeyMD5',
        'SSEKMSKeyId',
        'ServerSideEncryption',
        'StorageClass',
        'Tagging',
        'WebsiteRedirectLocation',
    ];

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
     * Set the S3Client bucket.
     *
     * @return string
     */
    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
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
     * @return bool
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
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
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
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
     * @deprecated
     */
    public function deleteDir($dirname)
    {
        // todo:no supported method
        try {
            $prefix = $this->applyPathPrefix($dirname) . '/';
            $this->gcpClient->deleteMatchingObjects($this->bucket, $prefix);
        } catch (DeleteMultipleObjectsException $exception) {
            return false;
        }

        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return bool|array
     * @deprecated
     */
    public function createDir($dirname, Config $config)
    {
        // todo:no supported method
        return $this->upload($dirname . '/', '', $config);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return bool
     * @deprecated
     */
    public function has($path)
    {
        // todo:no supported method
        $location = $this->applyPathPrefix($path);

        if ($this->gcpClient->doesObjectExist($this->bucket, $location, $this->options)) {
            return true;
        }

        return $this->doesDirectoryExist($location);
    }

    /**
     * Read a file.
     *
     * @param string $objectName
     *
     * @return false|array
     */
    public function read($objectName)
    {
        $response = $this->readObject($objectName);

        if ($response !== false) {
            $response['contents'] = $response['contents']->getContents();
        }

        return $response;
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
        $prefix = $this->applyPathPrefix(rtrim($directory, '/') . '/');
        $options = ['Bucket' => $this->bucket, 'Prefix' => ltrim($prefix, '/')];

        if ($recursive === false) {
            $options['Delimiter'] = '/';
        }

        $listing = $this->retrievePaginatedListing($options);
        $normalizer = [$this, 'normalizeResponse'];
        $normalized = array_map($normalizer, $listing);

        return Util::emulateDirectories($normalized);
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
     * @param string $newBucketName
     *
     * @return bool
     */
    public function copy($objectName, $newBucketName)
    {
        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->object($objectName);
        $object->copy($newBucketName, ['name' => $newBucketName]);

        return true;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $response = $this->readObject($path);

        if ($response !== false) {
            $response['stream'] = $response['contents']->detach();
            unset($response['contents']);
        }

        return $response;
    }

    /**
     * Read an object and normalize the response.
     *
     * @param $path
     *
     * @return array|bool
     */
    protected function readObject($objectName)
    {
        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->object($objectName);
        $stream = $object->downloadAsStream();

        //return $this->normalizeResponse($stream);
        return false;
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $command = $this->gcpClient->getCommand(
            'putObjectAcl',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
                'ACL' => $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private',
            ]
        );

        try {
            $this->gcpClient->execute($command);
            // todo：例外クラスを具体的なクラスにする
        } catch (\Exception $exception) {
            return false;
        }

        return compact('path', 'visibility');
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
        return ['visibility' => $this->getRawVisibility($path)];
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
     * @return bool
     */
    protected function upload($path, $body, Config $config)
    {
        $key = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);
        $acl = array_key_exists('ACL', $options) ? $options['ACL'] : 'private';

        if (!isset($options['ContentType'])) {
            $options['ContentType'] = Util::guessMimeType($path, $body);
        }

        if (!isset($options['ContentLength'])) {
            $options['ContentLength'] = is_string($body) ? Util::contentSize($body) : Util::getStreamSize($body);
        }

        if ($options['ContentLength'] === null) {
            unset($options['ContentLength']);
        }

        //$uploader = $this->gcpClient->signedUrlUploader($path, $body, $options);

        $client = $this->getClient();
        $bucket = $client->bucket($this->getBucket());
        $object = $bucket->upload($body, [
            'name' => $path,
        ]);

        return true;
        //return $this->normalizeResponse($options, $key);
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
     * @return array
     */
    protected function normalizeResponse(StreamInterface $stream, $path = null)
    {
        $result = [
            'path' => $path ?: $this->removePathPrefix(
                isset($response['Key']) ? $response['Key'] : $response['Prefix']
            ),
        ];
        $result = array_merge($result, Util::pathinfo($result['path']));

        if (isset($response['LastModified'])) {
            $result['timestamp'] = strtotime($response['LastModified']);
        }

        if (substr($result['path'], -1) === '/') {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');

            return $result;
        }

        return array_merge($result, Util::map($response, static::$resultMap), ['type' => 'file']);
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
