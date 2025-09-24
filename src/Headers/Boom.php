<?php

declare(strict_types=1);

namespace PP\Headers;

use InvalidArgumentException;
use JsonException;

/**
 * HTTP‑error helper.
 *
 * @method static self badRequest(string $message = 'Bad Request', array $details = [])
 * @method static self unauthorized(string $message = 'Unauthorized', array $details = [])
 * @method static self paymentRequired(string $message = 'Payment Required', array $details = [])
 * @method static self forbidden(string $message = 'Forbidden', array $details = [])
 * @method static self notFound(string $message = 'Not Found', array $details = [])
 * @method static self methodNotAllowed(string $message = 'Method Not Allowed', array $details = [])
 * @method static self notAcceptable(string $message = 'Not Acceptable', array $details = [])
 * @method static self conflict(string $message = 'Conflict', array $details = [])
 * @method static self gone(string $message = 'Gone', array $details = [])
 * @method static self lengthRequired(string $message = 'Length Required', array $details = [])
 * @method static self preconditionFailed(string $message = 'Precondition Failed', array $details = [])
 * @method static self payloadTooLarge(string $message = 'Payload Too Large', array $details = [])
 * @method static self uriTooLarge(string $message = 'URI Too Large', array $details = [])
 * @method static self unsupportedMediaType(string $message = 'Unsupported Media Type', array $details = [])
 * @method static self rangeNotSatisfiable(string $message = 'Range Not Satisfiable', array $details = [])
 * @method static self expectationFailed(string $message = 'Expectation Failed', array $details = [])
 * @method static self iAmATeapot(string $message = "I'm a teapot", array $details = [])
 * @method static self misdirectedRequest(string $message = 'Misdirected Request', array $details = [])
 * @method static self unprocessableEntity(string $message = 'Unprocessable Entity', array $details = [])
 * @method static self locked(string $message = 'Locked', array $details = [])
 * @method static self failedDependency(string $message = 'Failed Dependency', array $details = [])
 * @method static self tooEarly(string $message = 'Too Early', array $details = [])
 * @method static self upgradeRequired(string $message = 'Upgrade Required', array $details = [])
 * @method static self preconditionRequired(string $message = 'Precondition Required', array $details = [])
 * @method static self tooManyRequests(string $message = 'Too Many Requests', array $details = [])
 * @method static self requestHeaderFieldsTooLarge(string $message = 'Request Header Fields Too Large', array $details = [])
 * @method static self unavailableForLegalReasons(string $message = 'Unavailable for Legal Reasons', array $details = [])
 * @method static self internal(string $message = 'Internal Server Error', array $details = [])
 * @method static self notImplemented(string $message = 'Not Implemented', array $details = [])
 * @method static self badGateway(string $message = 'Bad Gateway', array $details = [])
 * @method static self serviceUnavailable(string $message = 'Service Unavailable', array $details = [])
 * @method static self gatewayTimeout(string $message = 'Gateway Timeout', array $details = [])
 * @method static self httpVersionNotSupported(string $message = 'HTTP Version Not Supported', array $details = [])
 * @method static self insufficientStorage(string $message = 'Insufficient Storage', array $details = [])
 * @method static self loopDetected(string $message = 'Loop Detected', array $details = [])
 * @method static self notExtended(string $message = 'Not Extended', array $details = [])
 * @method static self networkAuthenticationRequired(string $message = 'Network Authentication Required', array $details = [])
 * @method static self networkConnectTimeoutError(string $message = 'Network Connect Timeout Error', array $details = [])
 */
class Boom
{
    private const PHRASES = [
        /* 4XX Client error */
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable for Legal Reasons',
        499 => 'Client Closed Request',

        /* 5XX Server error */
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
        599 => 'Network Connect Timeout Error',
    ];

    /** @var int */
    protected int $statusCode;

    /** @var string */
    protected string $errorMessage;

    /** @var array<string,mixed> */
    protected array $errorDetails;

    public function __construct(
        int    $statusCode,
        string $errorMessage = '',
        array  $errorDetails = [],
    ) {
        if (!isset(self::PHRASES[$statusCode])) {
            throw new InvalidArgumentException("Unsupported HTTP status code: $statusCode");
        }

        $this->statusCode   = $statusCode;
        $this->errorMessage = $errorMessage ?: self::PHRASES[$statusCode];
        $this->errorDetails = $errorDetails;
    }

    public static function create(int $code, ?string $msg = null, array $details = []): self
    {
        return new self($code, $msg ?? '', $details);
    }

    /**
     * Dynamic factories: Boom::tooManyRequests(), Boom::badRequest(), …
     *
     * @param array{0?:string,1?:array<mixed>} $args
     */
    public static function __callStatic(string $method, array $args): self
    {
        // Convert camelCase to Studly Caps → Reason‑Phrase → code
        $normalized = strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $method) ?? '');
        $code       = array_search(
            ucwords(str_replace(' ', ' ', $normalized)),
            self::PHRASES,
            true
        );

        if ($code === false) {
            throw new InvalidArgumentException("Undefined Boom factory: $method()");
        }

        $msg     = $args[0] ?? '';
        $details = $args[1] ?? [];

        return new self((int)$code, $msg, $details);
    }

    public function toResponse(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');

        try {
            echo json_encode(
                [
                    'statusCode' => $this->statusCode,
                    'error'      => $this->errorMessage,
                    'details'    => (object)$this->errorDetails,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            echo '{"statusCode":500,"error":"JSON encoding error"}';
        }

        exit; // Ensure no further output
    }

    public static function isBoom(mixed $err): bool
    {
        return $err instanceof self;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
    public function getErrorDetails(): array
    {
        return $this->errorDetails;
    }
}
