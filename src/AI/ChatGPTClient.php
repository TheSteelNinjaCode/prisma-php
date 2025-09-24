<?php

declare(strict_types=1);

namespace PP\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PP\Validator;
use RuntimeException;

class ChatGPTClient
{
    private Client $client;
    private string $apiUrl = '';
    private string $apiKey = '';
    private array $cache = [];

    public function __construct(?Client $client = null)
    {
        // Initialize the Guzzle HTTP client, allowing for dependency injection
        $this->client = $client ?: new Client();

        // API URL for chat completions
        $this->apiUrl = 'https://api.openai.com/v1/chat/completions';

        // Get the API key from environment variables (keep this private and secure)
        $this->apiKey = $_ENV['CHATGPT_API_KEY'];
    }

    /**
     * Determines the appropriate model based on internal logic.
     *
     * @param array $conversationHistory The conversation history array.
     * @return string The model name to be used.
     */
    protected function determineModel(array $conversationHistory): string
    {
        $messageCount = count($conversationHistory);
        $totalTokens = array_reduce(
            $conversationHistory,
            fn($carry, $item) => $carry + str_word_count($item['content'] ?? ''),
            0
        );

        // If the conversation is long or complex, use a model with more tokens
        if ($totalTokens > 4000 || $messageCount > 10) {
            return 'gpt-3.5-turbo-16k'; // Use the model with a larger token limit
        }

        // Default to the standard model for shorter conversations
        return 'gpt-3.5-turbo';
    }

    /**
     * Formats the conversation history to ensure it is valid.
     *
     * @param array $conversationHistory The conversation history array.
     * @return array The formatted conversation history.
     */
    protected function formatConversationHistory(array $conversationHistory): array
    {
        $formattedHistory = [];
        foreach ($conversationHistory as $message) {
            if (is_array($message) && isset($message['role'], $message['content']) && Validator::string($message['content'])) {
                $formattedHistory[] = $message;
            } else {
                $formattedHistory[] = ['role' => 'user', 'content' => (string) $message];
            }
        }
        return $formattedHistory;
    }

    /**
     * Sends a message to the OpenAI API and returns the AI's response as HTML.
     *
     * @param array  $conversationHistory The conversation history array containing previous messages.
     * @param string $userMessage         The new user message to add to the conversation.
     * @return string The AI-generated HTML response.
     *
     * @throws \InvalidArgumentException If a message in the conversation history is not valid.
     * @throws RuntimeException         If the API request fails or returns an unexpected format.
     */
    public function sendMessage(array $conversationHistory, string $userMessage): string
    {
        if (!Validator::string($userMessage)) {
            throw new \InvalidArgumentException("Invalid user message: must be a string.");
        }

        // Optional: Convert emojis or special patterns in the message
        $userMessage = Validator::emojis($userMessage);

        // Prepare the conversation, including a system-level instruction to return valid HTML
        $systemInstruction = [
            'role'    => 'system',
            'content' => 'You are ChatGPT. Please provide your response in valid HTML format.'
        ];

        // Format existing history, then prepend the system message
        $formattedHistory = $this->formatConversationHistory($conversationHistory);
        array_unshift($formattedHistory, $systemInstruction);

        // Append the new user message
        $formattedHistory[] = ['role' => 'user', 'content' => $userMessage];

        // Check cache
        $cacheKey = md5(serialize($formattedHistory));
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Determine the appropriate model to use
        $model = $this->determineModel($formattedHistory);

        try {
            // Sending a POST request to the AI API
            $response = $this->client->request('POST', $this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => $model,
                    'messages'    => $formattedHistory,
                    'max_tokens'  => 500,
                ],
            ]);

            $responseBody    = $response->getBody();
            $responseContent = json_decode((string) $responseBody, true);

            // Check if response is in expected format
            if (isset($responseContent['choices'][0]['message']['content'])) {
                $aiMessage = $responseContent['choices'][0]['message']['content'];

                // Cache the result
                $this->cache[$cacheKey] = $aiMessage;

                return $aiMessage;
            }

            throw new RuntimeException('Unexpected API response format.');
        } catch (RequestException $e) {
            throw new RuntimeException("API request failed: " . $e->getMessage(), 0, $e);
        }
    }
}
