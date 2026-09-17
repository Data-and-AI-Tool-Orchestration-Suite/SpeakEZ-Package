<?php

/**
 * OpenAI API Client for PHP
 * 
 * A comprehensive PHP client for the OpenAI API that supports:
 * - Chat completions (with streaming)
 * - Embeddings
 * - Audio transcription and translation
 * - Image generation
 * - Fine-tuning
 * - Files management
 * - Assistants API
 */
class OpenAIClient {
    private string $apiKey;
    private string $baseUrl = 'https://api-llm-factory.ai.uky.edu/v1';
    private ?string $organization;
    private array $defaultHeaders;

    private $buffer = '';

    /**
     * Constructor
     * 
     * @param string $apiKey Your API key
     * @param string $baseUrl The base URL to reach the model at
     * @param string|null $organization Optional organization ID
     */
    public function __construct(string $apiKey, string $baseUrl = 'https://api-llm-factory.ai.uky.edu/v1', ?string $organization = null) {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->organization = $organization;
        
        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
        
        if ($organization) {
            $this->defaultHeaders['OpenAI-Organization'] = $organization;
        }
    }

    /**
     * Makes a request to the OpenAI API
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array|null $data Request data
     * @param array $additionalHeaders Additional headers
     * @param bool $isMultipart Whether the request is multipart/form-data
     * @return array Response data
     * @throws Exception If the request fails
     */
    private function request(string $endpoint, string $method = 'POST', ?array $data = null, array $additionalHeaders = [], bool $isMultipart = false): array {
        $url = $this->baseUrl . $endpoint;
        $headers = $this->defaultHeaders;
        
        if ($isMultipart) {
            // Remove Content-Type for multipart requests (cURL will set it with the boundary)
            unset($headers['Content-Type']);
        }
        
        $headers = array_merge($headers, $additionalHeaders);
        
        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = "$key: $value";
        }
        
        $curl = curl_init();
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $formattedHeaders,
        ];
        
        if ($data !== null) {
            if ($isMultipart) {
                $curlOptions[CURLOPT_POSTFIELDS] = $data;
            } else {
                $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_SLASHES);
            }
        }
        
        curl_setopt_array($curl, $curlOptions);
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if ($err) {
            throw new Exception("cURL Error: $err");
        }
        
        $responseData = json_decode($response, true);
        
        if ($statusCode >= 400) {
            $errorMessage = $responseData['message'] ?? 'Unknown error';
            $errorType = $responseData['type'] ?? 'unknown_error';
            throw new Exception("API Error ($statusCode): $errorMessage [$errorType]");
        }
        
        return $responseData;
    }

    /**
     * Stream response from the API and process each chunk with a callback
     * 
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param callable $callback Function to process each chunk
     * @throws Exception If the request fails
     */
    private function streamRequest(string $endpoint, array $data, callable $callback): void {
        $url = $this->baseUrl . $endpoint;
        $headers = $this->defaultHeaders;
       
        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = "$key: $value";
        }
       
        // Set up streaming parameters
        $data['stream'] = true;
       
        // Reset buffer for new request
        $this->buffer = '';
        
        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_WRITEFUNCTION => function($curl, $data) use ($callback) {
                // Append new data to buffer
                $this->buffer .= $data;
                
                // Optional: Log raw incoming data
                // file_put_contents('debug.log', "RAW CHUNK: " . $data . "\n\n", FILE_APPEND);
                
                // Process complete SSE messages (data: {}\n\n format)
                $completeMessages = [];
                if (preg_match_all('/data: (.*?)\n\n/s', $this->buffer, $matches)) {
                    foreach ($matches[0] as $fullMatch) {
                        // Remove the complete message from the buffer
                        $this->buffer = str_replace($fullMatch, '', $this->buffer);
                        
                        // Extract just the JSON part
                        if (preg_match('/data: (.*?)\n/s', $fullMatch, $jsonMatch)) {
                            $jsonData = $jsonMatch[1];
                            
                            if ($jsonData === '[DONE]') {
                                $callback(null, true); // Signal completion
                            } else {
                                $decodedData = json_decode($jsonData, true);
                                if ($decodedData === null) {
                                    // Optional: Log invalid JSON
                                    // file_put_contents('debug.log', "INVALID JSON: " . $jsonData . "\n\n", FILE_APPEND);
                                    error_log("INVALID JSON: " . $jsonData);
                                    continue; // Skip invalid JSON
                                }
                                $callback($decodedData, false);
                            }
                        }
                    }
                }
                
                return strlen($data);
            },
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0, // No timeout for streaming
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $formattedHeaders,
        ]);
        
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
       
       
        if ($err) {
            throw new Exception("cURL Error: $err");
        }
       
        if ($statusCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'Stream request failed.';
            $errorType = $errorData['error']['type'] ?? 'unknown_error';
            throw new Exception("API Error ($statusCode): $errorMessage [$errorType]");
        }
        
        // Process any remaining data in buffer if needed
        if (!empty($this->buffer) && strpos($this->buffer, 'data: ') === 0) {
            $jsonData = substr($this->buffer, 6);
            if ($jsonData === '[DONE]') {
                $callback(null, true);
            } else {
                $decodedData = json_decode($jsonData, true);
                if ($decodedData !== null) {
                    $callback($decodedData, false);
                }
            }
        }
    }

    /**
     * Create a chat completion
     * 
     * @param array $messages Array of message objects
     * @param string $model Model to use (e.g., 'gpt-4')
     * @param array $options Additional options
     * @return array Response data
     */
    public function createChatCompletion(array $messages, string $model = 'gpt-4o', array $options = []): array {
        $data = array_merge([
            'model' => $model,
            'messages' => $messages,
        ], $options);
        
        return $this->request('/chat/completions', 'POST', $data);
    }

    /**
     * Stream a chat completion
     * 
     * @param array $messages Array of message objects
     * @param callable $callback Function to process each chunk
     * @param string $model Model to use (e.g., 'gpt-4')
     * @param array $options Additional options
     */
    public function streamChatCompletion(array $messages, callable $callback, string $model = 'gpt-4o', array $options = []): void {
        $data = array_merge([
            'model' => $model,
            'messages' => $messages,
        ], $options);
        
        $this->streamRequest('/chat/completions', $data, $callback);
    }

    /**
     * Create an embedding
     * 
     * @param array|string $input Text to embed
     * @param string $model Model to use
     * @param array $options Additional options
     * @return array Response data
     */
    public function createEmbedding($input, string $model = 'text-embedding-3-large', array $options = []): array {
        $data = array_merge([
            'model' => $model,
            'input' => $input,
        ], $options);
        
        return $this->request('/embeddings', 'POST', $data);
    }

    /**
     * Transcribe audio to text
     * 
     * @param string $audioFilePath Path to audio file
     * @param string $model Model to use
     * @param array $options Additional options
     * @return array Response data
     */
    public function transcribeAudio(string $audioFilePath, string $model = 'whisper-1', array $options = []): array {
        if (!file_exists($audioFilePath)) {
            throw new Exception("Audio file not found: $audioFilePath");
        }
        
        $data = [
            'file' => new CURLFile($audioFilePath),
            'model' => $model,
        ];
        
        foreach ($options as $key => $value) {
            $data[$key] = $value;
        }
        
        return $this->request('/audio/transcriptions', 'POST', $data, [], true);
    }

    /**
     * Translate audio to English text
     * 
     * @param string $audioFilePath Path to audio file
     * @param string $model Model to use
     * @param array $options Additional options
     * @return array Response data
     */
    public function translateAudio(string $audioFilePath, string $model = 'whisper-1', array $options = []): array {
        if (!file_exists($audioFilePath)) {
            throw new Exception("Audio file not found: $audioFilePath");
        }
        
        $data = [
            'file' => new CURLFile($audioFilePath),
            'model' => $model,
        ];
        
        foreach ($options as $key => $value) {
            $data[$key] = $value;
        }
        
        return $this->request('/audio/translations', 'POST', $data, [], true);
    }

    /**
     * Generate an image with DALL-E
     * 
     * @param string $prompt Text description of the desired image
     * @param array $options Additional options
     * @return array Response data
     */
    public function generateImage(string $prompt, array $options = []): array {
        $data = array_merge([
            'prompt' => $prompt,
            'model' => 'dall-e-3',
            'n' => 1,
            'size' => '1024x1024',
        ], $options);
        
        return $this->request('/images/generations', 'POST', $data);
    }

    /**
     * Upload a file to OpenAI
     * 
     * @param string $filePath Path to file
     * @param string $purpose Purpose of the file
     * @return array Response data
     */
    public function uploadFile(string $filePath, string $purpose = 'fine-tune'): array {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }
        
        $data = [
            'file' => new CURLFile($filePath),
            'purpose' => $purpose,
        ];
        
        return $this->request('/files', 'POST', $data, [], true);
    }

    /**
     * List available files
     * 
     * @return array Response data
     */
    public function listFiles(): array {
        return $this->request('/files', 'GET');
    }

    /**
     * Delete a file
     * 
     * @param string $fileId ID of the file to delete
     * @return array Response data
     */
    public function deleteFile(string $fileId): array {
        return $this->request("/files/$fileId", 'DELETE');
    }

    /**
     * Create a fine-tuning job
     * 
     * @param string $trainingFileId ID of the training file
     * @param string $model Base model to fine-tune
     * @param array $options Additional options
     * @return array Response data
     */
    public function createFineTuningJob(string $trainingFileId, string $model = 'gpt-3.5-turbo', array $options = []): array {
        $data = array_merge([
            'training_file' => $trainingFileId,
            'model' => $model,
        ], $options);
        
        return $this->request('/fine_tuning/jobs', 'POST', $data);
    }

    /**
     * List fine-tuning jobs
     * 
     * @return array Response data
     */
    public function listFineTuningJobs(): array {
        return $this->request('/fine_tuning/jobs', 'GET');
    }

    /**
     * Create an assistant
     * 
     * @param string $model The model to use
     * @param array $options Additional options
     * @return array Response data
     */
    public function createAssistant(string $model, array $options = []): array {
        $data = array_merge([
            'model' => $model,
        ], $options);
        
        return $this->request('/assistants', 'POST', $data);
    }

    /**
     * Create a thread
     * 
     * @param array $messages Optional initial messages
     * @return array Response data
     */
    public function createThread(array $messages = []): array {
        $data = [];
        if (!empty($messages)) {
            $data['messages'] = $messages;
        }
        
        return $this->request('/threads', 'POST', $data);
    }

    /**
     * Add a message to a thread
     * 
     * @param string $threadId Thread ID
     * @param string $role Role (user)
     * @param string $content Message content
     * @param array $options Additional options
     * @return array Response data
     */
    public function createMessage(string $threadId, string $role, string $content, array $options = []): array {
        $data = array_merge([
            'role' => $role,
            'content' => $content,
        ], $options);
        
        return $this->request("/threads/$threadId/messages", 'POST', $data);
    }

    /**
     * Run an assistant on a thread
     * 
     * @param string $threadId Thread ID
     * @param string $assistantId Assistant ID
     * @param array $options Additional options
     * @return array Response data
     */
    public function createRun(string $threadId, string $assistantId, array $options = []): array {
        $data = array_merge([
            'assistant_id' => $assistantId,
        ], $options);
        
        return $this->request("/threads/$threadId/runs", 'POST', $data);
    }

    /**
     * Get run status
     * 
     * @param string $threadId Thread ID
     * @param string $runId Run ID
     * @return array Response data
     */
    public function getRun(string $threadId, string $runId): array {
        return $this->request("/threads/$threadId/runs/$runId", 'GET');
    }

    /**
     * List messages in a thread
     * 
     * @param string $threadId Thread ID
     * @param array $options Additional options
     * @return array Response data
     */
    public function listMessages(string $threadId, array $options = []): array {
        $query = http_build_query($options);
        $endpoint = "/threads/$threadId/messages";
        if (!empty($query)) {
            $endpoint .= "?$query";
        }
        
        return $this->request($endpoint, 'GET');
    }

    /**
     * List available models
     * 
     * @return array Response data
     */
    public function listModels(): array {
        return $this->request('/models', 'GET');
    }
}