# Joolanders\Flysystem\S3Small

This is a Flysystem adapter for s3 without the need for the huge AWS PHP SDK

# Installation

```bash
composer require joolanders/flysystem-aws-s3-small
```

# Bootstrap

``` php
<?php

include __DIR__ . '/vendor/autoload.php';

$configuration = new \Akeeba\Engine\Postproc\Connector\S3v4\Configuration(
    'your-key',
    'your-secret',
    'V2|V4', // optional
    'your-region' // optional
);

$connector = new \Akeeba\Engine\Postproc\Connector\S3v4\Connector($configuration);
$adapter = new \Joolanders\Flysystem\S3Small\S3SmallAdapter($connector, 'your-bucket');
$filesystem = new \League\Flysystem\Filesystem($adapter);
```