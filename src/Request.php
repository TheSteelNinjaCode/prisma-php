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
     * The base URL for the application.
     * 
     * @var string
     */
    public const baseUrl = '/src/app';

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

    public static function init(): void
    {
        self::$params = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        self::$dynamicParams = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);

        self::$referer = $_SERVER['HTTP_REFERER'] ?? 'Unknown';
        self::$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        self::$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        self::$domainName = $_SERVER['HTTP_HOST'] ?? '';
        self::$scriptName = dirname($_SERVER['SCRIPT_NAME']);
        self::$requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

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
        // Check for standard AJAX header
        if (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            return true;
        }

        // Check for common AJAX content types
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $ajaxContentTypes = [
                'application/json',
                'application/x-www-form-urlencoded',
                'multipart/form-data',
            ];

            foreach ($ajaxContentTypes as $contentType) {
                if (stripos($_SERVER['CONTENT_TYPE'], $contentType) !== false) {
                    return true;
                }
            }
        }

        // Check for common AJAX request methods
        $ajaxMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        if (
            !empty($_SERVER['REQUEST_METHOD']) &&
            in_array(strtoupper($_SERVER['REQUEST_METHOD']), $ajaxMethods, true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the request is a wire request.
     */
    private static function isWireRequest(): bool
    {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        return isset($headers['http_pp_wire_request']) && strtolower($headers['http_pp_wire_request']) === 'true';
    }

    /**
     * Checks if the request is an X-File request.
     *
     * @return bool True if the request is an X-File request, false otherwise.
     */
    private static function isXFileRequest(): bool
    {
        $serverFetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if (isset($serverFetchSite) && $serverFetchSite === 'same-origin') {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            return isset($headers['http_pp_x_file_request']) && $headers['http_pp_x_file_request'] === 'true';
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
        $params = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);

        switch (self::$method) {
            case 'GET':
                $params = new ArrayObject($_GET, ArrayObject::ARRAY_AS_PROPS);
                break;
            default:
                // Handle JSON input with different variations (e.g., application/json, application/ld+json, etc.)
                if (preg_match('#^application/(|\S+\+)json($|[ ;])#', self::$contentType)) {
                    $jsonInput = file_get_contents('php://input');
                    if ($jsonInput !== false && !empty($jsonInput)) {
                        self::$data = json_decode($jsonInput, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $params = new ArrayObject(self::$data, ArrayObject::ARRAY_AS_PROPS);
                        } else {
                            Boom::badRequest('Invalid JSON body')->toResponse();
                        }
                    }
                }

                // Handle URL-encoded input
                if (stripos(self::$contentType, 'application/x-www-form-urlencoded') !== false) {
                    $rawInput = file_get_contents('php://input');
                    if ($rawInput !== false && !empty($rawInput)) {
                        parse_str($rawInput, $parsedParams);
                        $params = new ArrayObject($parsedParams, ArrayObject::ARRAY_AS_PROPS);
                    } else {
                        $params = new ArrayObject($_POST, ArrayObject::ARRAY_AS_PROPS);
                    }
                }
                break;
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
                $decodedData = json_decode($data, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $_SESSION[$sessionKey] = $data;
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
                    $decodedData = json_decode($sessionData, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $localStorage = new ArrayObject($decodedData, ArrayObject::ARRAY_AS_PROPS);
                    }
                }
            }
        }

        return $localStorage;
    }

    /**
     * Get the protocol of the request.
     */
    private static function getProtocol(): string
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            ($_SERVER['SERVER_PORT'] == 443)
        ) ? "https://" : "http://";
    }

    /**
     * Get the Bearer token from the Authorization header.
     */
    public static function getBearerToken(): ?string
    {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $authHeader = $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
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
        if (ob_get_length()) {
            ob_clean();
        }

        ob_start();

        if (!self::$isWire && !self::$isAjax) {
            header("Location: $url", $replace, $responseCode);
        } else {
            echo "redirect_7F834=$url";
            ob_end_flush();
        }

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
