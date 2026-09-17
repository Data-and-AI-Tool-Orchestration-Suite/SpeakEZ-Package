<?php

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

class S3ClientWrapper {
    private $s3;
    private $bucket;

    public function __construct() {
        global $CONFIG;
        $this->bucket = $CONFIG['s3']['bucket'];
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1', // this doesn't matter
            'endpoint' => $CONFIG['s3']['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $CONFIG['s3']['id'],
                'secret' => $CONFIG['s3']['secret']
            ]
        ]);
    }

    public function listBuckets() {
        try {
            $result = $this->s3->listBuckets();
            return $result['Buckets'];
        } catch (AwsException $e) {
            // Output error message if fails
            error_log("Error: " . $e->getMessage());
        }
        return "error";
    }

    public function putObject($filepath) {
        if (!file_exists($filepath)) {
            throw new Exception("File not found: $filepath");
        }

        $filename = basename($filepath);
        return $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $filename,
            'SourceFile' => $filepath
        ]);
    }

    public function getObject($filename, $saveToPath) {
        global $CONFIG;
        return $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $CONFIG['s3']['directory'].$filename,
            'SaveAs' => $saveToPath
        ]);
    }
}

//// Usage example
//$s3ClientWrapper = new S3ClientWrapper('YOUR_ACCESS_KEY', 'YOUR_SECRET_KEY', 'https://<XXXXXX>.stackhero-network.com');
//
//// Send a PutObject request and get the result object.
//$insert = $s3ClientWrapper->putObject('testbucket', 'testkey', 'Hello from MinIO!!');
//
//// Download the contents of the object.
//$retrieve = $s3ClientWrapper->getObject('testbucket', 'testkey', 'testkey_local');
//
//// Print the body of the result by indexing into the result object.
//echo file_get_contents('testkey_local');
