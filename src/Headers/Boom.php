<?php

declare(strict_types=1);

namespace PPHP\Headers;

class Boom
{
    /**
     * HTTP status code.
     *
     * @var int
     */
    protected int $statusCode = 500;

    /**
     * Error message.
     *
     * @var string
     */
    protected string $errorMessage = 'Internal Server Error';

    /**
     * Additional error details.
     *
     * @var array
     */
    protected array $errorDetails = [];

    /**
     * Boom constructor.
     *
     * @param int    $statusCode    HTTP status code.
     * @param string $errorMessage  Error message.
     * @param array  $errorDetails  Additional error details.
     */
    public function __construct(int $statusCode, string $errorMessage, array $errorDetails = [])
    {
        $this->statusCode    = $statusCode;
        $this->errorMessage  = $errorMessage;
        $this->errorDetails  = $errorDetails;
    }

    /**
     * Factory method for 400 Bad Request.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function badRequest(string $message = 'Bad Request', array $details = []): self
    {
        return new self(400, $message, $details);
    }

    /**
     * Factory method for 401 Unauthorized.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function unauthorized(string $message = 'Unauthorized', array $details = []): self
    {
        return new self(401, $message, $details);
    }

    /**
     * Factory method for 402 Payment Required.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function paymentRequired(string $message = 'Payment Required', array $details = []): self
    {
        return new self(402, $message, $details);
    }

    /**
     * Factory method for 403 Forbidden.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function forbidden(string $message = 'Forbidden', array $details = []): self
    {
        return new self(403, $message, $details);
    }

    /**
     * Factory method for 404 Not Found.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function notFound(string $message = 'Not Found', array $details = []): self
    {
        return new self(404, $message, $details);
    }

    /**
     * Factory method for 405 Method Not Allowed.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function methodNotAllowed(string $message = 'Method Not Allowed', array $details = []): self
    {
        return new self(405, $message, $details);
    }

    /**
     * Factory method for 406 Not Acceptable.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function notAcceptable(string $message = 'Not Acceptable', array $details = []): self
    {
        return new self(406, $message, $details);
    }

    /**
     * Factory method for 500 Internal Server Error.
     *
     * @param string $message Error message.
     * @param array  $details Additional error details.
     *
     * @return self
     */
    public static function internal(string $message = 'Internal Server Error', array $details = []): self
    {
        return new self(500, $message, $details);
    }

    /**
     * Sends the HTTP error response and terminates the script.
     *
     * @return void
     */
    public function toResponse(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');

        echo json_encode([
            'statusCode' => $this->statusCode,
            'error'      => $this->errorMessage,
            'details'    => $this->errorDetails,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);

        exit; // Ensures no further execution after sending the response
    }

    /**
     * Checks if the provided error is an instance of Boom.
     *
     * @param mixed $error The error to check.
     *
     * @return bool
     */
    public static function isBoom($error): bool
    {
        return $error instanceof self;
    }

    /**
     * Gets the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Gets the error message.
     *
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Gets the additional error details.
     *
     * @return array
     */
    public function getErrorDetails(): array
    {
        return $this->errorDetails;
    }
}
