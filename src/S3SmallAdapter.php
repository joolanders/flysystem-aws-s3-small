<?php

namespace Joolanders\Flysystem\S3Small;

use Akeeba\Engine\Postproc\Connector\S3v4\Connector;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotDeleteFile;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotGetFile;
use Akeeba\Engine\Postproc\Connector\S3v4\Exception\CannotOpenFileForWrite;
use Akeeba\Engine\Postproc\Connector\S3v4\Request;
use Akeeba\Engine\Postproc\Connector\S3v4\Response\Error;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Symfony\Component\Console\Input\Input;

/**
 * Class S3SmallAdapter
 * @package Joolanders\Flysystem\S3Small
 */
class S3SmallAdapter extends AbstractAdapter implements CanOverwriteFiles
{
    /**
     * @var array
     */
    protected static $resultMap = [
        'Body' => 'contents',
        'ContentLength' => 'size',
        'ContentType' => 'mimetype',
        'size' => 'size',
        'Metadata' => 'metadata',
        'StorageClass' => 'storageclass',
        'hash' => 'etag',
        'VersionId' => 'versionid'
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
     * @var \Akeeba\Engine\Postproc\Connector\S3v4\Connector
     */
    protected $s3Client;

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
     * @param Connector $client
     * @param string $bucket
     * @param string $prefix
     * @param array $options
     */
    public function __construct (Connector $client, $bucket, $prefix = '', array $options = [])
    {
        $this->s3Client = $client;
        $this->bucket = $bucket;
        $this->setPathPrefix($prefix);
        $this->options = $options;
    }

    /**
     * Get the S3Client bucket.
     *
     * @return string
     */
    public function getBucket ()
    {
        return $this->bucket;
    }

    /**
     * Set the S3Client bucket.
     *
     * @param string $bucket
     */
    public function setBucket ($bucket)
    {
        $this->bucket = $bucket;
    }

    /**
     * Get the Connector instance.
     *
     * @return Connector
     */
    public function getClient ()
    {
        return $this->s3Client;
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return false|array false on failure file meta data on success
     */
    public function write ($path, $contents, Config $config)
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
    public function update ($path, $contents, Config $config)
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
    public function rename ($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete ($path)
    {
        $location = $this->applyPathPrefix($path);

        $this->s3Client->deleteObject($this->bucket, $location);

        return !$this->has($path);
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir ($dirname)
    {
        $prefix = $this->applyPathPrefix($dirname) . '/';

        try {
            $this->s3Client->deleteObject($this->bucket, $prefix);
        } catch (CannotDeleteFile $e) {
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
     */
    public function createDir ($dirname, Config $config)
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
    public function has ($path)
    {
        $location = $this->applyPathPrefix($path);

        $list = $this->s3Client->getBucket($this->bucket, $location);
        if (count($list) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function read ($path)
    {
        $response = $content = $this->readObject($path);

        if ($response !== false) {
            $response = [
                'type' => 'file',
                'path' => $this->applyPathPrefix($path),
                'contents' => $content
            ];
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
    public function listContents ($directory = '', $recursive = false)
    {
        $prefix = $this->applyPathPrefix(rtrim($directory, '/') . '/');
        $path = ltrim($prefix, '/');

        $listing = $this->retrievePaginatedListing($path, $recursive);

        $normalizer = [$this, 'normalizeResponse'];
        $normalized = array_map($normalizer, $listing);

        return Util::emulateDirectories($normalized);
    }

    /**
     * @param string $path
     * @param bool $recursive
     *
     * @return array
     */
    protected function retrievePaginatedListing ($path, $recursive = false)
    {
        $delimiter = null;

        if ($recursive === false) {
            $delimiter = '/';
        }

        return $this->s3Client->getBucket($this->bucket, $path, null, null, $delimiter, true);
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getMetadata ($path)
    {
        $command = new Request('HEAD', $this->bucket, $this->applyPathPrefix($path), $this->s3Client->getConfiguration());

        $response = $command->getResponse();

        if (!$response->error->isError() && (($response->code !== 200) && ($response->code !== 206))) {
            $response->error = new Error(
                $response->code,
                "Unexpected HTTP status {$response->code}"
            );
        }

        if ($response->error->isError()) {
            return false;
        }

        return $response->getHeaders();
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getSize ($path)
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
    public function getMimetype ($path)
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
    public function getTimestamp ($path)
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
    public function writeStream ($path, $resource, Config $config)
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
    public function updateStream ($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy ($path, $newpath)
    {
        // to implement
        /**
        $command = $this->s3Client->getCommand(
            'copyObject',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($newpath),
                'CopySource' => urlencode($this->bucket . '/' . $this->applyPathPrefix($path)),
                'ACL' => $this->getRawVisibility($path) === AdapterInterface::VISIBILITY_PUBLIC
                    ? 'public-read' : 'private',
            ] + $this->options
        );

        try {
            $this->s3Client->execute($command);
        } catch (S3Exception $e) {
            return false;
        }

        return true;
         * */
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream ($path)
    {
        return $this->readObject($path);
    }

    /**
     * Read an object and normalize the response.
     *
     * @param $path
     *
     * @return string|bool
     */
    protected function readObject ($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            return $this->s3Client->getObject($this->bucket, $path, false);
        } catch (CannotOpenFileForWrite $e) {
            return false;
        } catch (CannotGetFile $e) {
            return false;
        }
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility ($path, $visibility)
    {
        // To implement
         /*
        $command = $this->s3Client->getCommand(
            'putObjectAcl',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
                'ACL' => $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private',
            ]
        );

        try {
            $this->s3Client->execute($command);
        } catch (S3Exception $exception) {
            return false;
        }

        return compact('path', 'visibility');
         */
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility ($path)
    {
        // to implement
        // return ['visibility' => $this->getRawVisibility($path)];
    }

    /**
     * {@inheritdoc}
     */
    public function applyPathPrefix ($path)
    {
        return ltrim(parent::applyPathPrefix($path), '/');
    }

    /**
     * {@inheritdoc}
     */
    public function setPathPrefix ($prefix)
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
    protected function getRawVisibility ($path)
    {
        // To implement
        /*
        $command = $this->s3Client->getCommand(
            'getObjectAcl',
            [
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]
        );

        $result = $this->s3Client->execute($command);
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
        */
    }

    /**
     * Upload an object.
     *
     * @param        $path
     * @param        $body
     * @param Config $config
     *
     * @return array|bool
     */
    protected function upload ($path, $body, Config $config)
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

        if (is_string($body)) {
            $input = \Akeeba\Engine\Postproc\Connector\S3v4\Input::createFromData($body);
        } else {
            $input = \Akeeba\Engine\Postproc\Connector\S3v4\Input::createFromResource($body, false);
        }

        $this->s3Client->putObject($input, $this->bucket, $key, $acl);

        return $this->normalizeResponse($options, $path);
    }

    /**
     * Get options from the config.
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig (Config $config)
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
     * @param array $response
     * @param string $path
     *
     * @return array
     */
    protected function normalizeResponse (array $response, $path = null)
    {
        $result = [
            'path' => $path ?: $this->removePathPrefix(
                isset($response['name']) ? $response['name'] : $response['prefix']
            ),
        ];
        $result = array_merge($result, Util::pathinfo($result['path']));

        if (isset($response['time'])) {
            $result['timestamp'] = $response['time'];
        }

        if (substr($result['path'], -1) === '/') {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');

            return $result;
        }

        return array_merge($result, Util::map($response, static::$resultMap), ['type' => 'file']);
    }
}
