<?php

declare(strict_types=1);

namespace PP;

use Bootstrap;
use PP\MainLayout;
use Throwable;
use PP\PHPX\Exceptions\ComponentValidationException;
use PP\PHPX\TemplateCompiler;

class ErrorHandler
{
    public static string $content = '';

    public static function registerHandlers(): void
    {
        self::registerExceptionHandler();
        self::registerShutdownFunction();
        self::registerErrorHandler();
    }

    private static function registerExceptionHandler(): void
    {
        set_exception_handler(function ($exception) {
            $errorContent = Bootstrap::isAjaxOrXFileRequestOrRouteFile()
                ? "Exception: " . $exception->getMessage()
                : "<div class='error'>Exception: " . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";

            self::modifyOutputLayoutForError($errorContent);
        });
    }

    private static function registerShutdownFunction(): void
    {
        register_shutdown_function(function () {
            $error = error_get_last();
            if (
                $error !== null &&
                in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)
            ) {
                $errorContent = Bootstrap::isAjaxOrXFileRequestOrRouteFile()
                    ? "Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']
                    : "<div class='error'>Fatal Error: " . htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8') .
                    " in " . htmlspecialchars($error['file'], ENT_QUOTES, 'UTF-8') .
                    " on line " . $error['line'] . "</div>";

                self::modifyOutputLayoutForError($errorContent);
            }
        });
    }

    private static function registerErrorHandler(): void
    {
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return;
            }
            $errorContent = Bootstrap::isAjaxOrXFileRequestOrRouteFile()
                ? "Error: {$severity} - {$message} in {$file} on line {$line}"
                : "<div class='error'>Error: {$message} in {$file} on line {$line}</div>";

            if ($severity === E_WARNING || $severity === E_NOTICE) {
                self::modifyOutputLayoutForError($errorContent);
            }
        });
    }

    public static function checkFatalError(): void
    {
        $error = error_get_last();
        if (
            $error !== null &&
            in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)
        ) {
            $errorContent = Bootstrap::isAjaxOrXFileRequestOrRouteFile()
                ? "Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']
                : "<div class='error'>Fatal Error: " . htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8') .
                " in " . htmlspecialchars($error['file'], ENT_QUOTES, 'UTF-8') .
                " on line " . $error['line'] . "</div>";

            self::modifyOutputLayoutForError($errorContent);
        }
    }

    public static function modifyOutputLayoutForError($contentToAdd): void
    {
        $errorFile = APP_PATH . '/error.php';
        $errorFileExists = file_exists($errorFile);

        if ($_ENV['SHOW_ERRORS'] === "false") {
            if ($errorFileExists) {
                $contentToAdd = Bootstrap::isAjaxOrXFileRequestOrRouteFile()
                    ? "An error occurred"
                    : "<div class='error'>An error occurred</div>";
            } else {
                exit;
            }
        }

        if ($errorFileExists) {
            // Clear ALL output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }

            self::$content = $contentToAdd;

            if (Bootstrap::isAjaxOrXFileRequestOrRouteFile()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => self::$content]);
                http_response_code(403);
            } else {
                $layoutFile = APP_PATH . '/layout.php';
                if (file_exists($layoutFile)) {
                    ob_start();
                    require_once $errorFile;
                    MainLayout::$children = ob_get_clean();

                    // Capture layout output
                    ob_start();
                    require $layoutFile;
                    $html = ob_get_clean();

                    // Compile and prepend DOCTYPE
                    $html = TemplateCompiler::compile($html);
                    $html = TemplateCompiler::injectDynamicContent($html);
                    $html = "<!DOCTYPE html>\n" . $html;

                    echo $html;
                } else {
                    echo self::$content;
                }
            }
        } else {
            // Clear ALL output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }

            if (Bootstrap::isAjaxOrXFileRequestOrRouteFile()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $contentToAdd]);
                http_response_code(403);
            } else {
                echo "<!DOCTYPE html>\n<html><body>" . $contentToAdd . "</body></html>";
            }
        }
        exit;
    }

    public static function formatExceptionForDisplay(Throwable $exception): string
    {
        // Handle specific exception types
        if ($exception instanceof ComponentValidationException) {
            return self::formatComponentValidationError($exception);
        }

        // Handle template compilation errors specifically
        if (strpos($exception->getMessage(), 'Invalid prop') !== false) {
            return self::formatTemplateCompilerError($exception);
        }

        // Generic exception formatting
        return self::formatGenericException($exception);
    }

    private static function formatComponentValidationError(ComponentValidationException $exception): string
    {
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $exception->getLine();

        // Get the details from the ComponentValidationException
        $propName = method_exists($exception, 'getPropName') ? $exception->getPropName() : 'unknown';
        $componentName = method_exists($exception, 'getComponentName') ? $exception->getComponentName() : 'unknown';
        $availableProps = method_exists($exception, 'getAvailableProps') ? $exception->getAvailableProps() : [];

        $availablePropsString = !empty($availableProps) ? implode(', ', $availableProps) : 'none defined';

        return <<<HTML
    <div class="error-container max-w-4xl mx-auto mt-8 bg-red-50 border border-red-200 rounded-lg shadow-lg">
        <div class="bg-red-100 px-6 py-4 border-b border-red-200">
            <h2 class="text-xl font-bold text-red-800 flex items-center">
                <svg class="w-6 h-6 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                Component Validation Error
            </h2>
        </div>
        
        <div class="p-6">
            <div class="bg-white border border-red-200 rounded-lg p-4 mb-4">
                <div class="mb-3">
                    <span class="inline-block bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-medium">
                        Component: {$componentName}
                    </span>
                    <span class="inline-block bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-medium ml-2">
                        Invalid Prop: {$propName}
                    </span>
                </div>
                <pre class="text-sm text-red-800 whitespace-pre-wrap font-mono">{$message}</pre>
            </div>
            
            <div class="text-sm text-gray-600 mb-4">
                <strong>File:</strong> <code class="bg-gray-100 px-2 py-1 rounded text-xs">{$file}</code><br />
                <strong>Line:</strong> <span class="bg-gray-100 px-2 py-1 rounded text-xs">{$line}</span>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                <h4 class="font-medium text-blue-800 mb-2">💡 Available Props:</h4>
                <p class="text-blue-700 text-sm">
                    <code class="bg-blue-100 px-2 py-1 rounded text-xs">{$availablePropsString}</code>
                </p>
            </div>
            
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                <h4 class="font-medium text-green-800 mb-2">🔧 Quick Fixes:</h4>
                <ul class="text-green-700 text-sm space-y-1">
                    <li>• Remove the '<code>{$propName}</code>' prop from your template</li>
                    <li>• Add '<code>public \${$propName};</code>' to your <code>{$componentName}</code> component class</li>
                    <li>• Use data attributes: '<code>data-{$propName}</code>' instead</li>
                </ul>
            </div>
            
            <details class="mt-4">
                <summary class="cursor-pointer text-red-600 font-medium hover:text-red-800 select-none">
                    Show Stack Trace
                </summary>
                <div class="mt-3 bg-gray-50 border border-gray-200 rounded p-4">
                    <pre class="text-xs text-gray-700 overflow-auto whitespace-pre-wrap max-h-96">{$exception->getTraceAsString()}</pre>
                </div>
            </details>
        </div>
    </div>
    HTML;
    }

    private static function formatTemplateCompilerError(Throwable $exception): string
    {
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $exception->getLine();

        // Extract the component validation error details
        if (preg_match("/Invalid prop '([^']+)' passed to component '([^']+)'/", $exception->getMessage(), $matches)) {
            $invalidProp = $matches[1];
            $componentName = $matches[2];

            return <<<HTML
        <div class="error-container max-w-4xl mx-auto mt-8 bg-red-50 border border-red-200 rounded-lg shadow-lg">
            <div class="bg-red-100 px-6 py-4 border-b border-red-200">
                <h2 class="text-xl font-bold text-red-800 flex items-center">
                    <svg class="w-6 h-6 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    Template Compilation Error
                </h2>
            </div>
            
            <div class="p-6">
                <div class="bg-white border border-red-200 rounded-lg p-4 mb-4">
                    <div class="mb-3">
                        <span class="inline-block bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-medium">
                            Component: {$componentName}
                        </span>
                        <span class="inline-block bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-medium ml-2">
                            Invalid Prop: {$invalidProp}
                        </span>
                    </div>
                    <p class="text-red-800 font-medium">{$message}</p>
                </div>
                
                <div class="text-sm text-gray-600 mb-4">
                    <strong>File:</strong> {$file}<br />
                    <strong>Line:</strong> {$line}
                </div>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <h4 class="font-medium text-blue-800 mb-2">💡 Quick Fix:</h4>
                    <p class="text-blue-700 text-sm">
                        Either remove the '<code>{$invalidProp}</code>' prop from your template, or add it as a public property to your <code>{$componentName}</code> component class.
                    </p>
                </div>
                
                <details class="mt-4">
                    <summary class="cursor-pointer text-red-600 font-medium hover:text-red-800 select-none">
                        Show Stack Trace
                    </summary>
                    <div class="mt-3 bg-gray-50 border border-gray-200 rounded p-4">
                        <pre class="text-xs text-gray-700 overflow-auto whitespace-pre-wrap">{$exception->getTraceAsString()}</pre>
                    </div>
                </details>
            </div>
        </div>
        HTML;
        }

        // Fallback to generic formatting
        return self::formatGenericException($exception);
    }

    private static function formatGenericException(Throwable $exception): string
    {
        $type = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $exception->getLine();

        return <<<HTML
    <div class="error-container max-w-4xl mx-auto mt-8 bg-red-50 border border-red-200 rounded-lg shadow-lg">
        <div class="bg-red-100 px-6 py-4 border-b border-red-200">
            <h2 class="text-xl font-bold text-red-800 flex items-center">
                <svg class="w-6 h-6 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                {$type}
            </h2>
        </div>
        
        <div class="p-6">
            <div class="bg-white border border-red-200 rounded-lg p-4 mb-4">
                <p class="text-red-800 font-medium wrap-break-word">{$message}</p>
            </div>
            
            <div class="text-sm text-gray-600 mb-4">
                <strong>File:</strong> <code class="bg-gray-100 px-2 py-1 rounded text-xs">{$file}</code><br />
                <strong>Line:</strong> <span class="bg-gray-100 px-2 py-1 rounded text-xs">{$line}</span>
            </div>
            
            <details class="mt-4">
                <summary class="cursor-pointer text-red-600 font-medium hover:text-red-800 select-none">
                    Show Stack Trace
                </summary>
                <div class="mt-3 bg-gray-50 border border-gray-200 rounded p-4">
                    <pre class="text-xs text-gray-700 overflow-auto whitespace-pre-wrap max-h-96">{$exception->getTraceAsString()}</pre>
                </div>
            </details>
        </div>
    </div>
    HTML;
    }
}
