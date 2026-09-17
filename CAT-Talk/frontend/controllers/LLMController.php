<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$config = include __DIR__ . '/../config.php';
class LLMController {
    public static function streamLLMRequest(User $user) {
        // Only allow POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            exit;
        }
        // Accept both form and raw input (JSON or x-www-form-urlencoded)
        $prompt = $_POST['prompt'] ?? null;
        $systemPrompt = $_POST['system_prompt'] ?? null;
        $model = $_POST['model'] ?? 'gpt-4o';
        $transcript = $_POST['transcript'] ?? null;
        $convoHistory = $_POST['convo_history'] ?? null;
        $convoId = $_POST['convo_id'] ?? null;
        if ($prompt === null) {
            $input = file_get_contents('php://input');
            // Try JSON first
            $parsed = json_decode($input, true);
            if (is_array($parsed)) {
                $prompt = $parsed['prompt'] ?? '';
                $systemPrompt = $parsed['system_prompt'] ?? '';
                $model = $parsed['model'] ?? 'gpt-4o';
                $transcript = $parsed['transcript'] ?? null;
                $convoHistory = $parsed['convo_history'] ?? null;
                $convoId = $parsed['convo_id'] ?? null;
            } else {
                // Fallback to URL-encoded
                parse_str($input, $parsed);
                $prompt = $parsed['prompt'] ?? '';
                $systemPrompt = $parsed['system_prompt'] ?? '';
                $model = $parsed['model'] ?? 'gpt-4o';
            }
        }
        if (!$prompt) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Missing prompt';
            exit;
        }
        // error_log("chat history: " . print_r($convoHistory, true));
        // error_log("prompt: " . $prompt);
        // error_log("transcript: " . $transcript);
        // Set headers for streaming
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        // Get API key from config
        $config = include __DIR__ . '/../config.php';
        $apiKey = $config['llm']['openai_api_key'] ?? null;
        if (!$apiKey) {
            http_response_code(500);
            echo 'API key not configured';
            exit;
        }
        // Use OpenAIClient from LLMUtility.php
        require_once __DIR__ . '/../utilities/LLMUtility.php';
        require_once __DIR__ . '/../models/Metrics.php';
        require_once __DIR__ . '/../models/LLM.php';
        // Require file_id and convo_name for chat context
        $fileId = $_POST['file_id'] ?? null;
        $convoName = $_POST['convo_name'] ?? null;
        if ($fileId === null || $convoName === null) {
            $input = file_get_contents('php://input');
            $parsed = json_decode($input, true);
            if (is_array($parsed)) {
                $fileId = $parsed['file_id'] ?? null;
                $convoName = $parsed['convo_name'] ?? null;
            }
        }
        if (!$fileId || !$convoName) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Missing file_id or convo_name';
            exit;
        }
        // Verify user is a member of the project that owns the file
        require_once __DIR__ . '/../models/Files.php';
        require_once __DIR__ . '/../models/Project.php';
        $file = Files::withId($fileId);
        if (!$file) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'File not found';
            exit;
        }
        $projectId = $file->getAssociatedProjectId();
        if (!$projectId) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'File is not associated with a project';
            exit;
        }
        // Use Project instance for isMember
        $project = Project::withId($projectId);
        if (!$project) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Project not found';
            exit;
        }
        $userId = $user->getId();
        $isMember = $project->isMember($userId);
        if (!$isMember) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'You do not have access to this file/project.';
            exit;
        }
        // Load or create conversation
        $conversation = LLMConversation::withFileIDandConvoID($fileId, $convoId);
        if (!$conversation) {
            $conversation = LLMConversation::create($fileId, $convoName, $model);
        }
        $convoId = $conversation->getId();
        error_log("LLMController: Streaming request for convo_id $convoId");
        // Append user message to conversation
        $conversation->appendMessage('user', $prompt);
        // Get or generate task_id from request (frontend should send it, or generate here)
        $taskId = $_POST['task_id'] ?? null;
        if ($taskId === null) {
            $input = file_get_contents('php://input');
            $parsed = json_decode($input, true);
            if (is_array($parsed) && isset($parsed['task_id'])) {
                $taskId = $parsed['task_id'];
            }
            if (!$taskId) {
                // Generate a 32-character lowercase hex string if not provided
                $bytes = random_bytes(16); // 16 bytes = 32 hex chars
                $taskId = strtolower(bin2hex($bytes));
            }
        }
        // Token counting helper (simple whitespace split)
        function countTokensLLM($text) {
            return strlen($text) * 0.3; // Approximate token count based on average token length
            // return preg_match_all('/\S+/', $text);
        }
        $promptToSend = "";
        if ($transcript) {
            // Append transcript to prompt if provided
            $promptToSend = "\n\n[Transcript]\n" . $transcript;
        }
        if ($convoHistory && is_array($convoHistory)) {
            // Append conversation history if provided
            foreach ($convoHistory as $msg) {
                if (is_array($msg) && isset($msg['role']) && isset($msg['content'])) {
                    $promptToSend .= "\n{$msg['role']}: {$msg['content']}";
                }
            }
        }
        $promptToSend .= "\n\n[User Prompt]\n" . $prompt;
        // error_log("promptToSend: " . $promptToSend);
        $tokens_in = countTokensLLM($promptToSend . "\n" . $systemPrompt);
        // error_log("LLMController: Streaming request for task_id $taskId with $tokens_in input tokens");
        $tokens_out = 0;
        $client = new OpenAIClient($apiKey);
        // Build messages array: transcript (system), then chat history, then current prompt
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        if ($transcript) {
            $messages[] = ['role' => 'system', 'content' => "[Transcript]\n" . $transcript];
        }
        if ($convoHistory && is_array($convoHistory)) {
            foreach ($convoHistory as $msg) {
                if (is_array($msg) && isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    ];
                }
            }
        }
        // Add the current user prompt as the last message
        $messages[] = ['role' => 'user', 'content' => $prompt];

        foreach ($messages as $idx => $msg) {
            error_log("Message $idx: role={$msg['role']} content=" . substr($msg['content'], 0, 200));
        }

        // If tokens_in > $tokens_per_chunk, chunk the prompt and summarize each chunk
        $tokens_per_chunk = 10000;
        if ($tokens_in > $tokens_per_chunk) {
            // Helper to split text into ~20000 token chunks (approximate by words)
            function chunkTextByTokens($text, $max_tokens = 5000) {
                $words = preg_split('/\s+/', $text);
                $chunks = [];
                $current = [];
                $count = 0;
                foreach ($words as $word) {
                    $current[] = $word;
                    $count++;
                    if ($count >= $max_tokens) {
                        $chunks[] = implode(' ', $current);
                        $current = [];
                        $count = 0;
                    }
                }
                if (!empty($current)) {
                    $chunks[] = implode(' ', $current);
                }
                return $chunks;
            }
            $prompt_chunks = chunkTextByTokens($transcript, $tokens_per_chunk);
            $summaries = [];
            foreach ($prompt_chunks as $i => $chunk) {
                // Heartbeat during summarization
                echo ": summarizing chunk $i\n\n";
                if (ob_get_level()) ob_end_flush();
                flush();
                if ($i === count($prompt_chunks) - 1) {
                    // Last chunk, don't summarize, use as is
                    $summaries[] = $chunk;
                } else {
                    $summarize_messages = [
                        ['role' => 'system', 'content' => 'Summarize the following text for use as context in a conversation.'],
                        ['role' => 'user', 'content' => $chunk]
                    ];
                    $summary_response = $client->createChatCompletion($summarize_messages, $model, ['max_tokens' => 1024]);
                    if (isset($summary_response['choices'][0]['message']['content'])) {
                        $summaries[] = $summary_response['choices'][0]['message']['content'];
                    } else {
                        $summaries[] = '[Summary failed]';
                    }
                }
            }
            // Combine all summaries and the last chunk
            $combined_prompt = implode("\n\n", $summaries);
            // Overwrite $messages only for summarization case:
            $messages = [];
            if ($systemPrompt) {
                $messages[] = ['role' => 'system', 'content' => $systemPrompt];
            }
            if ($convoHistory && is_array($convoHistory)) {
                foreach ($convoHistory as $msg) {
                    if (is_array($msg) && isset($msg['role']) && isset($msg['content'])) {
                        $messages[] = [
                            'role' => $msg['role'],
                            'content' => $msg['content']
                        ];
                    }
                }
            }
            $messages[] = ['role' => 'user', 'content' => $combined_prompt];
            $messages[] = ['role' => 'user', 'content' => $prompt];
        }
        // error_log("Post summarization messages:");
        // foreach ($messages as $idx => $msg) {
        //     error_log("Message $idx: role={$msg['role']} content=" . substr($msg['content'], 0, 200));
        // }

        // Create or update metrics entry before streaming
        $metrics = new Metrics();
        $metrics->setUserId($user->getId());
        $metrics->createWithType($taskId, 'llm', [
            'tokens_in' => $tokens_in,
            'tokens_out' => 0
        ]);
        // After streaming, append assistant response to conversation
        $assistantResponse = '';
        try {
            // Send convo_id as SSE event
            echo "event: convo_id\n";
            echo "data: $convoId\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            $client->streamChatCompletion($messages, function($chunk, $done) use (&$tokens_out, $metrics, $taskId, $tokens_in, &$assistantResponse) {
            if ($chunk) {
                $delta = $chunk['choices'][0]['delta'] ?? [];
                $payload = [];

                // 1. Capture reasoning (thinking) content if the model provides it
                if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                    $payload['type'] = 'thought';
                    $payload['text'] = $delta['reasoning_content'];
                    
                    $tokens_out += preg_match_all('/\S+/', $payload['text']);
                    $assistantResponse .= $payload['text']; // Save to DB history
                }
                
                // 2. Capture standard response content
                if (isset($delta['content']) && $delta['content'] !== '') {
                    $payload['type'] = 'content';
                    $payload['text'] = $delta['content'];
                    
                    $tokens_out += preg_match_all('/\S+/', $payload['text']);
                    $assistantResponse .= $payload['text']; // Save to DB history
                }

                // 3. Send formatted SSE JSON payload
                if (!empty($payload)) {
                    // Proper SSE requires "data: " followed by the payload, ending in "\n\n"
                    echo "data: " . json_encode($payload) . "\n\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                }
            }
            
            if ($done) {
                // Signal the frontend that the stream is finished
                echo "data: [DONE]\n\n";
                if (ob_get_level()) ob_flush();
                flush();
                
                // Update metrics with final tokens_out
                $metrics->updateDetailsWithType('llm', [
                    'tokens_in' => $tokens_in,
                    'tokens_out' => $tokens_out
                ]);
            }
        }, $model, ['max_tokens' => 3000]);
        } catch (Exception $e) {
            echo "data: [ERROR] " . $e->getMessage() . "\n\n";
            error_log("LLMController: Error during streaming for task_id $taskId: " . $e->getMessage());
        }
        // Append assistant message to conversation
        if (trim($assistantResponse)) {
            $conversation->appendMessage('assistant', trim($assistantResponse));
        }
    }

    public static function loadConversation(User $user) {
        // Only allow GET
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo 'Method Not Allowed';
            exit;
        }
        // Get file_id and convo_id from query parameters
        $fileId = $_GET['file_id'] ?? null;
        $convoId = $_GET['convo_id'] ?? null;
        if (!$fileId || !$convoId) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Missing file_id or convo_id';
            exit;
        }
        // Verify user is a member of the project that owns the file
        require_once __DIR__ . '/../models/Files.php';
        require_once __DIR__ . '/../models/Project.php';
        require_once __DIR__ . '/../models/LLM.php';
        $file = Files::withId($fileId);
        if (!$file) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'File not found';
            exit;
        }
        $projectId = $file->getAssociatedProjectId();
        if (!$projectId) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'File is not associated with a project';
            exit;
        }
        // Use Project instance for isMember
        $project = Project::withId($projectId);
        if (!$project) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Project not found';
            exit;
        }
        $userId = $user->getId();
        $isMember = $project->isMember($userId);
        if (!$isMember) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'You do not have access to this file/project.';
            exit;
        }
        // Load conversation
        $conversation = LLMConversation::withFileIDandConvoID($fileId, $convoId);
        if (!$conversation) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Conversation not found';
            exit;
        }
        // Return only the messages array as JSON
        header('Content-Type: application/json');
        echo json_encode($conversation->getMessages());
        return;
    }

    public static function listConversations(User $user) {
        // Only allow GET
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo 'Method Not Allowed';
            exit;
        }
        $fileId = $_GET['file_id'] ?? null;
        if (!$fileId) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Missing file_id';
            exit;
        }
        require_once __DIR__ . '/../models/Files.php';
        require_once __DIR__ . '/../models/Project.php';
        require_once __DIR__ . '/../models/LLM.php';
        $file = Files::withId($fileId);
        if (!$file) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'File not found';
            exit;
        }
        $projectId = $file->getAssociatedProjectId();
        if (!$projectId) {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo 'File is not associated with a project';
            exit;
        }
        $project = Project::withId($projectId);
        if (!$project) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Project not found';
            exit;
        }
        $userId = $user->getId();
        $isMember = $project->isMember($userId);
        if (!$isMember) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'You do not have access to this file/project.';
            exit;
        }
        // List conversations for this file
        $convos = LLMConversation::listByFile($fileId);
        $result = array_map(function($c) {
            return [
                'convo_name' => $c->getConvoName(),
                'created_at' => $c->getCreatedAt(),
                'model' => $c->getModel(),
                'id' => $c->getId()
            ];
        }, $convos);
        header('Content-Type: application/json');
        echo json_encode($result);
    }
}