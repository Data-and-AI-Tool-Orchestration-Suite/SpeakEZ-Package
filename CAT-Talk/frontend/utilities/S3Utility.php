<?php

require 'vendor/autoload.php'; // Composer autoloader

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\Credentials\Credentials;
use Aws\CommandInterface;

class S3Utility {
    private $s3Client;

    public function __construct($endpoint, $accessKey, $secretKey, $region = 'us-east-1', $useSsl = true)
    {
        // Configure S3Client (AWS or MinIO)
        $this->s3Client = new S3Client([
            'version'     => 'latest',
            'region'      => $region,
            'endpoint'    => $endpoint,  // Custom endpoint for MinIO
            'use_path_style_endpoint' => true, // MinIO requires this
            'credentials' => new Credentials($accessKey, $secretKey),
            'http'         => [
                'verify' => false, // Disable SSL verification if using MinIO with self-signed certificates
            ],
            'scheme' => $useSsl ? 'https' : 'http', // Use https for secure connections
        ]);
    }

    public static function createConnection(): S3Utility {
        global $CONFIG;
        $instance = new S3Utility(
            $CONFIG["s3"]["endpoint"],
            $CONFIG["s3"]["id"],
            $CONFIG["s3"]["secret"],
        );
        return $instance;
    }

    /**
     * Create a bucket with a specific name
     * @param string $bucketName: The name of the bucket to crerate
     * @return bool: Whether the bucket was created
     */
    public function createBucket(string $bucketName): bool {
        try {
            $this->s3Client->createBucket([
                'Bucket' => $bucketName,
            ]);
            // error_log("Bucket '{$bucketName}' created successfully.\n");
            return true;
        } catch (AwsException $e) {
            error_log("Error creating bucket: " . $e->getMessage() . "\n");
        }
        return false;
    }

    /**
     * Delete a bucket by name
     * @param string $bucketName: The name of the bucket to delete
     * @return bool: Whether the bucket was deleted
     */
    public function deleteBucket(string $bucketName): bool {
        try {
            $this->s3Client->deleteBucket([
                'Bucket' => $bucketName,
            ]);
            // error_log("Bucket '{$bucketName}' deleted successfully.\n");
            return true;
        } catch (AwsException $e) {
            error_log("Error deleting bucket: " . $e->getMessage() . "\n");
        }
        return false;
    }

    /**
     * Upload a file to a bucket
     * @param string $bucketName: The name of the bucket to upload into
     * @param string $filePath: The local path of the file
     * @param $objectKey: The name of the new object in the S3 server
     * @return bool: Whether the file was uploaded
     */
    public function uploadFile(string $bucketName, string $filePath, string $objectKey): bool {
        try {
            $this->s3Client->putObject([
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
                'SourceFile' => $filePath,
            ]);
            // error_log("File '{$objectKey}' uploaded successfully to bucket '{$bucketName}'.\n");
            return true;
        } catch (AwsException $e) {
            error_log("Error uploading file: " . $e->getMessage() . "\n");
        }
        return false;
    }

    /**
     * Delete an object from a bucket
     * @param string $bucketName: The name of the bucket to delete from
     * @param $objectKey: The name of the object to delete
     * @return bool: Whether the object was deleted
     */
    public function deleteObject(string $bucketName, string $objectKey): bool {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
            ]);
            // error_log("Object '{$objectKey}' deleted successfully from bucket '{$bucketName}'.\n");
            return true;
        } catch (AwsException $e) {
            error_log("Error deleting object: " . $e->getMessage() . "\n");
        }
        return false;
    }

    /**
     * List the contents of a bucket
     * @param string $bucketName: The name of the bucket to list
     * @return ?array: The list of objects, null if there is an error or no objects
     */
    public function listBucketContents($bucketName): ?array {
        try {
            $contents = [];
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $bucketName,
            ]);
            
            // Check if 'Contents' exists in the result
            if (isset($result['Contents']) && is_array($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $contents[] = $object;
                }
            }

            // If no contents were found, return an empty array
            return $contents ?: null;
        } catch (AwsException $e) {
            error_log("Error listing bucket contents: " . $e->getMessage() . "\n");
        }
        return null;
    }

    /**
     * 'cat' the contents of a file (download and display)
     * @param string $bucketName: The name of the bucket to cat from
     * @param string $objectKey: The name of the object to cat
     * @return string|null: The file contents as a string, or null if there is an error
     */
    public function catFile($bucketName, $objectKey): ?string {
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
            ]);
            
            // Cast the Body (stream) to string before returning it
            return (string) $result['Body'];
        } catch (AwsException $e) {
            error_log("Error retrieving file: " . $e->getMessage() . "\n");
        }
        return null;
    }

    /**
     * 'echo' content into an object on the server
     * @param string $bucketName: The name of the bucket to echo into
     * @param $objectKey: The name of the object to cat
     * @param $content: The content to echo into the object
     * @return bool: Whether the file was echoed into
     */
    public function echoIntoFile($bucketName, $objectKey, $content): bool {
        try {
            $this->s3Client->putObject([
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
                'Body'   => $content,
            ]);
            // error_log("Content written to file '{$objectKey}' in bucket '{$bucketName}'.\n");
            return true;
        } catch (AwsException $e) {
            error_log("Error writing to file: " . $e->getMessage() . "\n");
        }
        return false;
    }

    /**
     * Generate a presigned link to upload a file into S3
     * @param string $bucketName: The name of the bucket to upload to
     * @param string $objectKey: The name of the object to upload to with the link
     * @param int $expires: The seconds before this link will expire
     * @return ?string: The presigned URL, null if an error occurred
     */
    public function generatePresignedUrlUpload(string $bucketName, string $objectKey, int $expires = 3600): ?string {
        try {
            // Ensure the object key is properly formatted (no leading slashes)
            $objectKey = ltrim($objectKey, '/');
            
            // Create the command to put an object
            $cmd = $this->s3Client->getCommand('PutObject', [
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
                'ContentType' => $this->getMimeType($objectKey), // Optional: set content type
            ]);
            
            // Generate the presigned request using the command
            $request = $this->s3Client->createPresignedRequest($cmd, '+' . $expires . ' seconds');
            
            // Get the presigned URL as a string
            $presignedUrl = (string) $request->getUri();
            
            // Log for debugging (remove in production)
            error_log("Generated presigned upload URL for key: {$objectKey}");
            error_log("URL: {$presignedUrl}");
            
            return $presignedUrl;
        } catch (AwsException $e) {
            error_log("Error generating presigned upload URL: " . $e->getMessage());
            error_log("Object key: {$objectKey}");
        }
        return null;
    }

    /**
     * Generate a presigned link to download a file from S3
     * @param string $bucketName: The name of the bucket to download from
     * @param string $objectKey: The name of the object to download with the link
     * @param int $expires: The seconds before this link will expire
     * @return ?string: The presigned URL, null if an error occurred
     */
    public function generatePresignedUrlDownload(string $bucketName, string $objectKey, int $expires = 3600): ?string {
        try {
            // Ensure the object key is properly formatted (no leading slashes)
            $objectKey = ltrim($objectKey, '/');
            
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
            ]);
            
            $request = $this->s3Client->createPresignedRequest($cmd, '+' . $expires . ' seconds');
            $presignedUrl = (string) $request->getUri();
            
            // Log for debugging (remove in production)
            error_log("Generated presigned download URL for key: {$objectKey}");
            error_log("URL: {$presignedUrl}");
            
            return $presignedUrl;
        } catch (AwsException $e) {
            error_log("Error generating presigned download URL: " . $e->getMessage());
            error_log("Object key: {$objectKey}");
        }
        return null;
    }

    /**
     * Helper method to determine MIME type based on file extension
     * @param string $objectKey: The object key/filename
     * @return string: The MIME type
     */
    private function getMimeType(string $objectKey): string {
        $extension = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Alternative method using getObjectUrl for simple download URLs (non-expiring)
     * @param string $bucketName: The name of the bucket
     * @param string $objectKey: The name of the object
     * @return string: The object URL
     */
    public function getObjectUrl(string $bucketName, string $objectKey): string {
        $objectKey = ltrim($objectKey, '/');
        return $this->s3Client->getObjectUrl($bucketName, $objectKey);
    }

        /**
     * Download a file from a bucket to a local path
     * @param string $bucketName: The name of the bucket to download from
     * @param string $objectKey: The name of the object to download
     * @param string $localPath: The local file path to save to
     * @return bool: Whether the file was downloaded successfully
     */
    public function downloadFile(string $bucketName, string $objectKey, string $localPath): bool {
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $bucketName,
                'Key'    => $objectKey,
                'SaveAs' => $localPath,
            ]);
            return true;
        } catch (AwsException $e) {
            error_log("Error downloading file: " . $e->getMessage() . "\n");
        }
        return false;
    }

    /**
     * List the "contents" of a virtual folder (prefix) in a bucket.
     * * This method simulates folder listing by using a prefix and a delimiter.
     * It returns a structured array with 'files' (objects in the current prefix)
     * and 'folders' (common prefixes, i.e., subfolders).
     *
     * @param string $bucketName: The name of the bucket
     * @param string $folderPath: The path of the folder (e.g., 'images/user/' or 'images/user')
     * @return ?array: An associative array ['files' => [], 'folders' => []], or null on error
     */
    public function listFolderContents(string $bucketName, string $folderPath): ?array {
        // 1. Normalize the prefix to ensure it ends with a '/'.
        //    An empty path ('') means we list the root.
        $prefix = $folderPath;
        if (!empty($prefix) && substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $bucketName,
                'Prefix' => $prefix,
                'Delimiter' => '/', // This is the magic key!
            ]);

            $output = [
                'files' => [],
                'folders' => [],
            ];

            // 'Contents' contains objects/files in the current folder
            if (isset($result['Contents']) && is_array($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    // S3 sometimes includes the prefix itself as a 0-byte object
                    // We'll skip it as it's not really a "file" in the folder.
                    if ($object['Key'] !== $prefix) {
                        $output['files'][] = $object;
                    }
                }
            }

            // 'CommonPrefixes' contains the "subfolders"
            if (isset($result['CommonPrefixes']) && is_array($result['CommonPrefixes'])) {
                foreach ($result['CommonPrefixes'] as $commonPrefix) {
                    // $commonPrefix is an array like ['Prefix' => 'path/to/subfolder/']
                    // We'll just store the prefix string
                    $output['folders'][] = $commonPrefix['Prefix'];
                }
            }

            // Return the structured list
            return $output;

        } catch (AwsException $e) {
            error_log("Error listing folder contents for '{$prefix}': " . $e->getMessage() . "\n");
        }
        return null;
    }

}


