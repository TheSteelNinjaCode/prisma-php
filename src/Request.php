<?php

declare(strict_types=1);

namespace PP;

use PP\Headers\Boom;
use ArrayObject;
use stdClass;
use PP\PrismaPHPSettings;

class Request
{
    /**
     * @var stdClass $params A static property to hold request parameters.
     * 
     * This property is used to hold request parameters that are passed to the request.
     * 
     * Example usage:
     * The parameters can be accessed using the following syntax:
     * ```php
     * $id = Request::$params['id'];
     * OR
     * $id = Request::$params->id;
     * ```
     */
    public static ArrayObject $params;

    /**
     * @var stdClass $dynamicParams A static property to hold dynamic parameters.
     * 
     * This property is used to hold dynamic parameters that are passed to the request.
     * 
     * Example usage:
     * Single parameter:
     * ```php
     * $id = Request::$dynamicParams['id'];
     * OR
     * $id = Request::$dynamicParams->id;
     * ```
     * 
     * Multiple parameters:
     * ```php
     * $dynamicParams = Request::$dynamicParams;
     * echo '<pre>';
     * print_r($dynamicParams);
     * echo '</pre>';
     * ```
     * 
     * The above code will output the dynamic parameters as an array, which can be useful for debugging purposes.
     */
    public static ArrayObject $dynamicParams;

    /**
     * @var stdClass $localStorage A static property to hold request parameters.
     * 
     * This property is used to hold request parameters that are passed to the request.
     * 
     * Example usage:
     * The parameters can be accessed using the following syntax:
     * ```php
     * $id = Request::$localStorage['id'];
     * OR
     * $id = Request::$localStorage->id;
     * ```
     */
    public static ArrayObject $localStorage;

    /**
     * @var mixed $data Holds request data (e.g., JSON body).
     */
    public static mixed $data = null;

    /**
     * @var string $pathname Holds the request pathname.
     */
    public static string $pathname = '';

    /**
     * @var string $uri Holds the request URI.
     */
    public static string $uri = '';

    /**
     * @var string $decodedUri Holds the decoded request URI.
     */
    public static string $decodedUri = '';

    /**
     * @var string $referer Holds the referer of the request.
     */
    public static string $referer = '';

    /**
     * @var string $method Holds the request method.
     */
    public static string $method = '';

    /**
     * @var string $contentType Holds the content type of the request.
     */
    public static string $contentType = '';

    /**
     * @var string $protocol The protocol used for the request.
     */
    public static string $protocol = '';

    /**
     * @var string $domainName The domain name of the request.
     */
    public static string $domainName = '';

    /**
     * @var string $scriptName The script name of the request.
     */
    public static string $scriptName = '';

    /**
     * @var string $documentUrl The full document URL of the request.
     */
    public static string $documentUrl = '';

    /**
     * @var string $fileToInclude The file to include in the request.
     */
    public static string $fileToInclude = '';

    /**
     * @var bool $isGet Indicates if the request method is GET.
     */
    public static bool $isGet = false;

    /**
     * @var bool $isPost Indicates if the request method is POST.
     */
    public static bool $isPost = false;

    /**
     * @var bool $isPut Indicates if the request method is PUT.
     */
    public static bool $isPut = false;

    /**
     * @var bool $isDelete Indicates if the request method is DELETE.
     */
    public static bool $isDelete = false;

    /**
     * @var bool $isPatch Indicates if the request method is PATCH.
     */
    public static bool $isPatch = false;

    /**
     * @var bool $isHead Indicates if the request method is HEAD.
     */
    public static bool $isHead = false;

    /**
     * @var bool $isOptions Indicates if the request method is OPTIONS.
     */
    public static bool $isOptions = false;

    /**
     * @var bool $isAjax Indicates if the request is an AJAX request.
     */
    public static bool $isAjax = false;

    /**
     * Indicates whether the request is a wire request.
     *
     * @var bool
     */
    public static bool $isWire = false;

    /**
     * Indicates whether the request is an X-File request.
     *
     * @var bool
     */
    public static bool $isXFileRequest = false;

    /**
     * @var string $requestedWith Holds the value of the X-Requested-With header.
     */
    public static string $requestedWith = '';

    /**
     * @var string $remoteAddr Holds the remote address of the request.
     */
    public static string $remoteAddr = '';

    /**
     * @var array<string, string>|null
     */
    private static ?array $normalizedHeaders = null;

    /** @var array{normalized:string,isJson:bool,isForm:bool,isMultipart:bool} */
    private static array $contentTypeInfo = [
        'normalized' => '',
        'isJson' => false,
        'isForm' => false,
        'isMultipart' => false,
    ];

    private static ?string $contentTypeInfoSource = null;

    private static string $rawInput = '';
    private static bool $rawInputLoaded = false;

    public static function init(): void
    {
        self::$params = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        self::$dynamicParams = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        self::$normalizedHeaders = null;
        self::$contentTypeInfoSource = null;
        self::$contentTypeInfo = [
            'normalized' => '',
            'isJson' => false,
            'isForm' => false,
            'isMultipart' => false,
        ];
        self::$rawInput = '';
        self::$rawInputLoaded = false;

        self::$referer = $_SERVER['HTTP_REFERER'] ?? 'Unknown';
        self::$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        self::$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        self::$domainName = $_SERVER['HTTP_HOST'] ?? '';
        self::$scriptName = dirname($_SERVER['SCRIPT_NAME']);
        self::$requestedWith = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

        self::$isGet = self::$method === 'GET';
        self::$isPost = self::$method === 'POST';
        self::$isPut = self::$method === 'PUT';
        self::$isDelete = self::$method === 'DELETE';
        self::$isPatch = self::$method === 'PATCH';
        self::$isHead = self::$method === 'HEAD';
        self::$isOptions = self::$method === 'OPTIONS';

        self::$isWire = self::isWireRequest();
        self::$isAjax = self::isAjaxRequest();
        self::$isXFileRequest = self::isXFileRequest();
        self::$params = self::getParams();
        self::$localStorage = self::getLocalStorage();
        self::$protocol = self::getProtocol();
        self::$documentUrl = self::$protocol . self::$domainName . self::$scriptName;
        self::$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }

    /**
     * Determines if the current request is an AJAX request.
     *
     * @return bool True if the request is an AJAX request, false otherwise.
     */
    private static function isAjaxRequest(): bool
    {
        if (self::$requestedWith !== '' && strcasecmp(self::$requestedWith, 'xmlhttprequest') === 0) {
            return true;
        }

        $contentTypeInfo = self::getContentTypeInfo();
        if (
            $contentTypeInfo['normalized'] !== '' &&
            (
                $contentTypeInfo['isJson']
                || $contentTypeInfo['isForm']
                || $contentTypeInfo['isMultipart']
            )
        ) {
            return true;
        }

        return self::$method === 'POST'
            || self::$method === 'PUT'
            || self::$method === 'PATCH'
            || self::$method === 'DELETE';
    }

    /**
     * Checks if the request is a wire request.
     */
    private static function isWireRequest(): bool
    {
        $header = $_SERVER['HTTP_PP_WIRE_REQUEST'] ?? self::getHeaderValue('pp-wire-request');

        return $header !== null && strcasecmp($header, 'true') === 0;
    }

    /**
     * Checks if the request is an X-File request.
     *
     * @return bool True if the request is an X-File request, false otherwise.
     */
    private static function isXFileRequest(): bool
    {
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') {
            $header = $_SERVER['HTTP_PP_X_FILE_REQUEST'] ?? self::getHeaderValue('pp-x-file-request');

            return $header !== null && strcasecmp($header, 'true') === 0;
        }

        return false;
    }

    /**
     * Get the request parameters.
     *
     * @return ArrayObject The request parameters as an ArrayObject with properties.
     */
    private static function getParams(): ArrayObject
    {
        if (self::$method === 'GET') {
            return new ArrayObject($_GET, ArrayObject::ARRAY_AS_PROPS);
        }

        $params = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        $rawInput = self::getRawInput();
        $contentTypeInfo = self::getContentTypeInfo();

        if ($contentTypeInfo['isJson']) {
            if ($rawInput !== '') {
                self::$data = json_decode($rawInput, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return new ArrayObject(self::$data, ArrayObject::ARRAY_AS_PROPS);
                }

                Boom::badRequest('Invalid JSON body')->toResponse();
            }

            return $params;
        }

        if ($contentTypeInfo['isForm']) {
            if ($rawInput !== '') {
                parse_str($rawInput, $parsedParams);
                return new ArrayObject($parsedParams, ArrayObject::ARRAY_AS_PROPS);
            }

            return new ArrayObject($_POST, ArrayObject::ARRAY_AS_PROPS);
        }

        return $params;
    }

    /**
     * Retrieves the local storage data from the session or initializes it if not present.
     *
     * This method checks if the local storage data is available in the static data array or the session.
     * If the data is found, it is decoded from JSON if necessary and returned as an ArrayObject.
     * If the data is not found, an empty ArrayObject is returned.
     *
     * @return ArrayObject The local storage data as an ArrayObject.
     */
    private static function getLocalStorage(): ArrayObject
    {
        $sessionKey = PrismaPHPSettings::$localStoreKey;
        $localStorage = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);

        if (isset(self::$data[$sessionKey])) {
            $data = self::$data[$sessionKey];

            if (is_array($data)) {
                $_SESSION[$sessionKey] = $data;
                $localStorage = new ArrayObject($data, ArrayObject::ARRAY_AS_PROPS);
            } else {
                $decodedData = self::decodeArrayPayload($data);

                if ($decodedData !== null) {
                    $_SESSION[$sessionKey] = $decodedData;
                    $localStorage = new ArrayObject($decodedData, ArrayObject::ARRAY_AS_PROPS);
                } else {
                    Boom::badRequest('Invalid JSON body')->toResponse();
                }
            }
        } else {
            if (isset($_SESSION[$sessionKey])) {
                $sessionData = $_SESSION[$sessionKey];

                if (is_array($sessionData)) {
                    $localStorage = new ArrayObject($sessionData, ArrayObject::ARRAY_AS_PROPS);
                } else {
                    $decodedData = self::decodeArrayPayload($sessionData);

                    if ($decodedData !== null) {
                        $_SESSION[$sessionKey] = $decodedData;
                        $localStorage = new ArrayObject($decodedData, ArrayObject::ARRAY_AS_PROPS);
                    }
                }
            }
        }

        return $localStorage;
    }

    private static function getRawInput(): string
    {
        if (!self::$rawInputLoaded) {
            $rawInput = file_get_contents('php://input');
            self::$rawInput = is_string($rawInput) ? $rawInput : '';
            self::$rawInputLoaded = true;
        }

        return self::$rawInput;
    }

    private static function decodeArrayPayload(mixed $payload): ?array
    {
        if (!is_string($payload)) {
            return null;
        }

        $decodedData = json_decode($payload, true);

        return is_array($decodedData) && json_last_error() === JSON_ERROR_NONE
            ? $decodedData
            : null;
    }

    private static function isJsonContentType(string $contentType): bool
    {
        if ($contentType === '' || !str_starts_with($contentType, 'application/')) {
            return false;
        }

        return str_contains($contentType, '/json') || str_contains($contentType, '+json');
    }

    /**
     * @return array{normalized:string,isJson:bool,isForm:bool,isMultipart:bool}
     */
    private static function getContentTypeInfo(): array
    {
        if (self::$contentTypeInfoSource === self::$contentType) {
            return self::$contentTypeInfo;
        }

        $normalized = self::$contentType;
        if ($normalized !== '' && strpbrk($normalized, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false) {
            $normalized = strtolower($normalized);
        }

        self::$contentTypeInfoSource = self::$contentType;
        self::$contentTypeInfo = [
            'normalized' => $normalized,
            'isJson' => self::isJsonContentType($normalized),
            'isForm' => str_contains($normalized, 'application/x-www-form-urlencoded'),
            'isMultipart' => str_contains($normalized, 'multipart/form-data'),
        ];

        return self::$contentTypeInfo;
    }

    /**
     * Get the protocol of the request.
     */
    private static function getProtocol(): string
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
        ) ? "https://" : "http://";
    }

    /**
     * Get the Bearer token from the Authorization header.
     */
    public static function getBearerToken(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['AUTHORIZATION']
            ?? self::getHeaderValue('authorization')
            ?? null;

        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function getNormalizedHeaders(): array
    {
        if (self::$normalizedHeaders !== null) {
            return self::$normalizedHeaders;
        }

        $headers = [];

        if (function_exists('getallheaders')) {
            $rawHeaders = getallheaders();

            if (is_array($rawHeaders)) {
                foreach ($rawHeaders as $name => $value) {
                    if (!is_string($value)) {
                        continue;
                    }

                    self::storeNormalizedHeader($headers, (string) $name, $value);
                }
            }
        }

        foreach ($_SERVER as $name => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $headerName = substr($name, 5);
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5', 'AUTHORIZATION'], true)) {
                $headerName = $name;
            } else {
                continue;
            }

            self::storeNormalizedHeader($headers, $headerName, $value);
        }

        self::$normalizedHeaders = $headers;

        return self::$normalizedHeaders;
    }

    private static function getHeaderValue(string $name): ?string
    {
        $headers = self::getNormalizedHeaders();
        $normalizedName = strtolower($name);

        if (str_starts_with($normalizedName, 'http_')) {
            $normalizedName = substr($normalizedName, 5);
        } elseif (str_starts_with($normalizedName, 'http-')) {
            $normalizedName = substr($normalizedName, 5);
        }

        $hyphenatedName = str_replace('_', '-', $normalizedName);
        $underscoredName = str_replace('-', '_', $hyphenatedName);

        return $headers[$hyphenatedName] ?? $headers[$underscoredName] ?? null;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function storeNormalizedHeader(array &$headers, string $name, string $value): void
    {
        $hyphenatedName = strtolower(str_replace('_', '-', $name));
        $underscoredName = str_replace('-', '_', $hyphenatedName);

        $headers[$hyphenatedName] = $value;
        $headers[$underscoredName] = $value;
    }

    /**
     * Handle preflight OPTIONS request.
     */
    public static function handlePreflight(): void
    {
        if (self::$method === 'OPTIONS') {
            header('HTTP/1.1 200 OK');
            exit;
        }
    }

    /**
     * Check if the request method is allowed.
     */
    public static function checkAllowedMethods(): void
    {
        if (!in_array(self::$method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'])) {
            Boom::methodNotAllowed()->toResponse();
        }
    }

    /**
     * Redirects the client to a specified URL.
     *
     * This method handles both normal and AJAX/wire requests. For normal requests,
     * it sends a standard HTTP redirection header. For AJAX/wire requests, it outputs
     * a custom redirect message.
     *
     * @param string $url The URL to redirect to.
     * @param bool $replace Whether to replace the current header. Default is true.
     * @param int $responseCode The HTTP response code to use for the redirection. Default is 0.
     *
     * @return void
     */
    public static function redirect(string $url, bool $replace = true, int $responseCode = 0): void
    {
        if (headers_sent()) {
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $status = $responseCode > 0 ? $responseCode : 302;

        if (!self::$isWire && !self::$isAjax) {
            header("Location: $url", $replace, $status);
            exit;
        }

        http_response_code(200);
        header("X-PP-Redirect: $url");
        header("X-PP-Redirect-Status: $status");
        header("X-PP-Redirect-Replace: " . ($replace ? "1" : "0"));
        header("Cache-Control: no-store");

        exit;
    }

    public static function getDecodedUrl(string $uri): string
    {
        $parsedUrl = parse_url($uri);

        $queryString = isset($parsedUrl['query']) ? '?' . urldecode($parsedUrl['query']) : '';
        $path = $parsedUrl['path'] ?? '';

        $decodedUrl = urldecode($path . $queryString);

        return $decodedUrl;
    }
}
