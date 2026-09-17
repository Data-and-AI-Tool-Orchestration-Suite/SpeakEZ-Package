<?php

use Mockery;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\Credentials\Credentials;
use Aws\CommandInterface;
use Psr\Http\Message\RequestInterface;

include_once '/var/www/html/utilities/S3Utility.php';


beforeEach(function () {
    // Mock the S3Client
    $this->mockS3Client = Mockery::mock(S3Client::class);
    $this->mockCommand = Mockery::mock(CommandInterface::class);
    $this->mockRequest = Mockery::mock('Psr\Http\Message\RequestInterface');

    $this->client = new S3Utility('http://localhost:9000', 'minioadmin', 'minioadmin', 'us-east-1', false);
    $this->clientReflection = new ReflectionClass($this->client);
    $this->clientProperty = $this->clientReflection->getProperty('s3Client');
    $this->clientProperty->setAccessible(true);
    $this->clientProperty->setValue($this->client, $this->mockS3Client);
});

afterEach(function () {
    Mockery::close();
});

it('can create a bucket', function () {
    $bucketName = 'my-bucket';

    // Set up the mock to simulate successful bucket creation
    $this->mockS3Client
        ->shouldReceive('createBucket')
        ->once()
        ->with(['Bucket' => $bucketName])
        ->andReturnSelf();

    // Run the method
    $this->client->createBucket($bucketName);
});

it('can delete a bucket', function () {
    $bucketName = 'my-bucket';

    // Set up the mock to simulate successful bucket deletion
    $this->mockS3Client
        ->shouldReceive('deleteBucket')
        ->once()
        ->with(['Bucket' => $bucketName])
        ->andReturnSelf();

    // Run the method
    $this->client->deleteBucket($bucketName);
});

it('can upload a file to a bucket', function () {
    $bucketName = 'my-bucket';
    $filePath = '/path/to/local/file.txt';
    $objectKey = 'file.txt';

    // Set up the mock to simulate successful file upload
    $this->mockS3Client
        ->shouldReceive('putObject')
        ->once()
        ->with([
            'Bucket' => $bucketName,
            'Key'    => $objectKey,
            'SourceFile' => $filePath,
        ])
        ->andReturnSelf();

    // Run the method
    $this->client->uploadFile($bucketName, $filePath, $objectKey);
});

it('can delete an object from a bucket', function () {
    $bucketName = 'my-bucket';
    $objectKey = 'file.txt';

    // Set up the mock to simulate successful object deletion
    $this->mockS3Client
        ->shouldReceive('deleteObject')
        ->once()
        ->with([
            'Bucket' => $bucketName,
            'Key'    => $objectKey,
        ])
        ->andReturnSelf();

    // Run the method
    $this->client->deleteObject($bucketName, $objectKey);
});

it('lists bucket contents successfully', function () {
    $bucketName = 'my-bucket';
    $objects = [
        ['Key' => 'file1.txt'],
        ['Key' => 'file2.txt'],
    ];

    // Mock the response from listObjectsV2
    $this->mockS3Client
        ->shouldReceive('listObjectsV2')
        ->once()
        ->with(['Bucket' => $bucketName])
        ->andReturn(['Contents' => $objects]);

    $result = $this->client->listBucketContents($bucketName);
    
    // Assert that the result contains the expected objects
    expect($result)->toBeArray()->and($result)->toHaveCount(2);
    expect($result[0]['Key'])->toBe('file1.txt');
    expect($result[1]['Key'])->toBe('file2.txt');
});

it('returns null if no objects are found', function () {
    $bucketName = 'my-bucket';

    // Mock the response for an empty bucket
    $this->mockS3Client
        ->shouldReceive('listObjectsV2')
        ->once()
        ->with(['Bucket' => $bucketName])
        ->andReturn(['Contents' => []]);

    $result = $this->client->listBucketContents($bucketName);
    
    // Assert that the result is null (empty bucket)
    expect($result)->toBeNull();
});

it('handles AWS exceptions correctly', function () {
    $bucketName = 'my-bucket';
    $errorMessage = 'An error occurred';

    // Mock the exception thrown by the AWS SDK
    $this->mockS3Client
        ->shouldReceive('listObjectsV2')
        ->once()
        ->with(['Bucket' => $bucketName])
        ->andThrow(new AwsException($errorMessage, new \Aws\Command('listObjectsV2')));

    $result = $this->client->listBucketContents($bucketName);

    // Assert that the result is null when an exception occurs
    expect($result)->toBeNull();
});

it('retrieves file contents successfully', function () {
    $bucketName = 'my-bucket';
    $objectKey = 'file1.txt';
    $fileContents = 'This is a test file content';

    // Mock the successful response from getObject
    $this->mockS3Client
        ->shouldReceive('getObject')
        ->once()
        ->with(['Bucket' => $bucketName, 'Key' => $objectKey])
        ->andReturn(['Body' => $fileContents]);

    $result = $this->client->catFile($bucketName, $objectKey);
    
    // Assert that the result matches the expected file content
    expect($result)->toBe($fileContents);
});

it('returns null if the file does not exist', function () {
    $bucketName = 'my-bucket';
    $objectKey = 'nonexistent-file.txt';
    
    // Mock the exception thrown by the AWS SDK
    $this->mockS3Client
        ->shouldReceive('getObject')
        ->once()
        ->with(['Bucket' => $bucketName, 'Key' => $objectKey])
        ->andThrow(new AwsException('No such key', new \Aws\Command('getObject')));

    $result = $this->client->catFile($bucketName, $objectKey);
    
    // Assert that the result is null when an error occurs
    expect($result)->toBeNull();
});

it('returns null if an error occurs', function () {
    $bucketName = 'my-bucket';
    $objectKey = 'file2.txt';
    
    // Mock the exception thrown by the AWS SDK
    $this->mockS3Client
        ->shouldReceive('getObject')
        ->once()
        ->with(['Bucket' => $bucketName, 'Key' => $objectKey])
        ->andThrow(new AwsException('General error', new \Aws\Command('getObject')));

    $result = $this->client->catFile($bucketName, $objectKey);
    
    // Assert that the result is null when an error occurs
    expect($result)->toBeNull();
});

it('can echo content into a file', function () {
    $bucketName = 'my-bucket';
    $objectKey = 'file.txt';
    $content = 'Hello, world!';

    // Set up the mock to simulate writing to a file
    $this->mockS3Client
        ->shouldReceive('putObject')
        ->once()
        ->with([
            'Bucket' => $bucketName,
            'Key'    => $objectKey,
            'Body'   => $content,
        ])
        ->andReturnSelf();

    // Run the method
    $this->client->echoIntoFile($bucketName, $objectKey, $content);
});

// Test the presigned URL generation
it('generates a presigned URL for upload', function () {
    $bucketName = 'my-bucket';
    $objectKey = 'file.txt';
    $expires = 3600;

    // Mock the CommandInterface
    $this->mockCommand = Mockery::mock(Aws\CommandInterface::class);

    // Mock the getCommand to return the mock command - INCLUDE ContentType parameter
    $this->mockS3Client
        ->shouldReceive('getCommand')
        ->once()
        ->with('PutObject', [
            'Bucket' => $bucketName, 
            'Key' => $objectKey,
            'ContentType' => 'text/plain'  // This is what was missing!
        ])
        ->andReturn($this->mockCommand);

    // Mock the RequestInterface
    $this->mockRequest = Mockery::mock(Psr\Http\Message\RequestInterface::class);
    
    // Mock createPresignedRequest to return the mock request
    $this->mockS3Client
        ->shouldReceive('createPresignedRequest')
        ->once()
        ->with($this->mockCommand, '+' . $expires . ' seconds')
        ->andReturn($this->mockRequest);

    // Mock the UriInterface and return it when getUri is called
    $mockUri = Mockery::mock(Psr\Http\Message\UriInterface::class);
    $mockUri
        ->shouldReceive('__toString')
        ->andReturn('https://example.com/presigned-url'); 

    // Mock getUri to return the mock UriInterface object
    $this->mockRequest
        ->shouldReceive('getUri')
        ->once()
        ->andReturn($mockUri);

    // Call the method and assert the result
    $result = $this->client->generatePresignedUrlUpload($bucketName, $objectKey, $expires);

    // Assert that the result is the expected presigned URL
    expect($result)->toBe('https://example.com/presigned-url');
});

// Test generating presigned links for download
it('can generate a presigned URL for download', function () {
    $bucketName = 'my-bucket';
    $objectKey = 'file.txt';
    $expires = 3600;

    // Mock the CommandInterface
    $this->mockCommand = Mockery::mock(Aws\CommandInterface::class);

    // Set up the mock to simulate generating a presigned URL for download
    $this->mockS3Client
        ->shouldReceive('getCommand')
        ->once()
        ->with('GetObject', [
            'Bucket' => $bucketName,
            'Key'    => $objectKey,
        ])
        ->andReturn($this->mockCommand); // Return the mock CommandInterface object

    // Mock the RequestInterface
    $this->mockRequest = Mockery::mock(Psr\Http\Message\RequestInterface::class);

    // Mock createPresignedRequest to return the mock request
    $this->mockS3Client
        ->shouldReceive('createPresignedRequest')
        ->once()
        ->with($this->mockCommand, '+' . $expires . ' seconds')
        ->andReturn($this->mockRequest); // Return the mock request

    // Mock the UriInterface and return it when getUri is called
    $mockUri = Mockery::mock(Psr\Http\Message\UriInterface::class);
    $mockUri
        ->shouldReceive('__toString') // Ensure that calling (string) on the UriInterface will return the correct string
        ->andReturn('http://example.com/presigned-url'); 

    // Mock getUri to return the mock UriInterface object
    $this->mockRequest
        ->shouldReceive('getUri')
        ->once()
        ->andReturn($mockUri); // Return the mocked UriInterface

    // Capture the output
    ob_start();
    $result = $this->client->generatePresignedUrlDownload($bucketName, $objectKey, $expires);
    ob_end_clean();

    // Assert that the result matches the expected presigned URL
    expect($result)->toBe('http://example.com/presigned-url');
});
