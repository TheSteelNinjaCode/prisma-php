<?php

declare(strict_types=1);

namespace PP;

use DateTime;
use Exception;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\Ulid;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use BackedEnum;
use Throwable;
use PP\Rule;

final class Validator
{
    // String Validation

    /**
     * Validate and sanitize a string.
     *
     * This function converts the input to a string, trims any leading or trailing
     * whitespace, and optionally converts special characters to HTML entities to
     * prevent XSS attacks. If the input is null, an empty string is returned.
     *
     * @param mixed $value The value to validate and sanitize. This can be of any type.
     * @param bool $escapeHtml Whether to escape special characters as HTML entities.
     * Defaults to true. Set to false when handling database
     * queries or other non-HTML contexts.
     * @return string The sanitized string. If the input is not a string or null,
     * it is converted to its string representation before sanitization.
     * If the input is null, an empty string is returned.
     */
    public static function string($value, bool $escapeHtml = true): string
    {
        // Handle DateTime objects by converting them to ISO 8601 format
        if ($value instanceof DateTime) {
            $stringValue = $value->format('Y-m-d H:i:s');
        } elseif ($value !== null) {
            // Convert the value to a string if it's not null
            $stringValue = (string)$value;
        } else {
            $stringValue = '';
        }

        // If escaping is enabled, apply htmlspecialchars; otherwise, just trim
        return $escapeHtml ? htmlspecialchars(trim($stringValue), ENT_QUOTES, 'UTF-8') : trim($stringValue);
    }

    /**
     * Validate an email address.
     *
     * @param mixed $value The value to validate.
     * @return string|null The valid email address or null if invalid.
     */
    public static function email($value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /**
     * Validate a URL.
     *
     * @param mixed $value The value to validate.
     * @return string|null The valid URL or null if invalid.
     */
    public static function url($value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }

    /**
     * Validate an IP address.
     *
     * @param mixed $value The value to validate.
     * @return string|null The valid IP address or null if invalid.
     */
    public static function ip($value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /**
     * Validate a UUID.
     *
     * @param mixed $value The value to validate.
     * @return string|null The valid UUID or null if invalid.
     */
    public static function uuid($value): ?string
    {
        return Uuid::isValid($value) ? $value : null;
    }

    /**
     * Validates if the given value is a valid ULID (Universally Unique Lexicographically Sortable Identifier).
     *
     * @param string $value The value to be validated.
     * @return string|null Returns the value if it is a valid ULID, otherwise returns null.
     */
    public static function ulid($value): ?string
    {
        return Ulid::isValid($value) ? $value : null;
    }

    /**
     * Validate a CUID.
     * 
     * @param mixed $value The value to validate.
     * @return string|null The valid CUID or null if invalid.
     */
    public static function cuid($value): ?string
    {
        // Ensure the value is a string
        if (!is_string($value)) {
            return null;
        }

        // Perform the CUID validation
        return preg_match('/^c[0-9a-z]{24}$/', $value) ? $value : null;
    }

    /**
     * Validate a CUID2.
     *
     * @param mixed $value The value to validate.
     * @return string|null The valid CUID2 or null if invalid.
     */
    public static function cuid2($value): ?string
    {
        // Ensure the value is a string
        if (!is_string($value)) {
            return null;
        }

        // Perform the CUID2 validation
        return preg_match('/^[0-9a-zA-Z_-]{21,}$/', $value) ? $value : null;
    }

    /**
     * Validate a size string (e.g., "10MB").
     *
     * @param mixed $value The value to validate.
     * @return string|null The valid size string or null if invalid.
     */
    public static function bytes($value): ?string
    {
        return preg_match('/^[0-9]+[kKmMgGtT]?[bB]?$/', $value) ? $value : null;
    }

    /**
     * Validate an XML string.
     *
     * @param mixed $value The value to validate.
     * @return string|null The valid XML string or null if invalid.
     */
    public static function xml($value): ?string
    {
        return preg_match('/^<\?xml/', $value) ? $value : null;
    }

    // Number Validation

    /**
     * Validate an integer value.
     *
     * @param mixed $value The value to validate.
     * @return int|null The integer value or null if invalid.
     */
    public static function int($value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : null;
    }

    /**
     * Validate a big integer value.
     *
     * @param mixed $value The value to validate.
     * @return BigInteger|null The big integer value or null if invalid.
     */
    public static function bigInt($value): ?BigInteger
    {
        try {
            return BigInteger::of($value);
        } catch (MathException) {
            return null;
        }
    }

    /**
     * Validate a float value.
     *
     * @param mixed $value The value to validate.
     * @return float|null The float value or null if invalid.
     */
    public static function float($value): ?float
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false ? (float)$value : null;
    }

    /**
     * Validate a decimal value.
     *
     * @param mixed $value The value to validate.
     * @param int $scale The number of decimal places (default is 30).
     * @return BigDecimal|null The decimal value or null if invalid.
     */
    public static function decimal($value, int $scale = 30): ?BigDecimal
    {
        try {
            return BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP);
        } catch (MathException) {
            return null;
        }
    }

    // Date Validation

    /**
     * Validate and format a date in a given format.
     *
     * This function attempts to parse the input value as a date according to the specified format.
     * If the value is valid, it returns the formatted date string. Otherwise, it returns null.
     *
     * @param mixed $value The value to validate. It can be a string or a DateTime object.
     * @param string $format The expected date format (default is 'Y-m-d').
     * @return string|null The formatted date string if valid, or null if invalid.
     */
    public static function date($value, string $format = 'Y-m-d'): ?string
    {
        try {
            if ($value instanceof DateTime) {
                $date = $value;
            } else {
                $date = DateTime::createFromFormat($format, (string)$value);
            }

            if ($date && $date->format($format) === (string)$value) {
                return $date->format($format);
            }
        } catch (Exception) {
            return null;
        }

        return null;
    }

    /**
     * Validates and formats a date-time value.
     *
     * This method attempts to create a DateTime object from the given value and formats it
     * according to the specified format. If the value is already a DateTime object, it uses
     * it directly. If the value cannot be parsed into a DateTime object, the method returns null.
     *
     * @param mixed $value The value to be validated and formatted. It can be a string or a DateTime object.
     * @param string $format The format to use for the output date-time string. Default is 'Y-m-d H:i:s.u'.
     * @return string|null The formatted date-time string, or null if the value could not be parsed.
     */
    public static function dateTime($value, string $format = 'Y-m-d H:i:s'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTime
                ? $value
                : new DateTime((string) $value);
        } catch (Throwable) {
            return null;
        }

        return $date->format($format);
    }

    // Boolean Validation

    /**
     * Validate a boolean value.
     *
     * @param mixed $value The value to validate.
     * @return bool|null The boolean value or null if invalid.
     */
    public static function boolean($value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    // Other Validation

    /**
     * Validate a JSON string or convert an array to a JSON string.
     *
     * This function checks if the input is a valid JSON string. If it is, it returns the string.
     * If the input is an array, it converts it to a JSON string with specific options.
     * If the input is invalid, it returns an error message.
     *
     * @param mixed $value The value to validate or convert.
     * @return string The valid JSON string or an error message if invalid.
     */
    public static function json(mixed $value): string
    {
        if (is_string($value)) {
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return json_last_error_msg();
            }
            return $value;
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Validate an enum value against allowed values.
     *
     * @param mixed $value The value to validate.
     * @param array $allowedValues The allowed values.
     * @return bool True if value is allowed, false otherwise.
     */
    public static function enum($value, array $allowedValues): bool
    {
        return in_array($value, $allowedValues, true);
    }

    /**
     * Validates and casts a value (or array of values) of a native enum.
     *
     * @template T of BackedEnum
     * @param string|int|T|array<string|int|T> $value      String, integer, instance, or array.
     * @param class-string<T>                  $enumClass  Enum class name.
     * @return string|int|array<string|int>|null           Backed value(s) or null if any is invalid.
     * @throws InvalidArgumentException                   If the class is not an enum.
     */
    public static function enumClass(mixed $value, string $enumClass): string|int|array|null
    {
        if (!enum_exists($enumClass)) {
            throw new InvalidArgumentException("Enum '$enumClass' not found.");
        }

        $cast = static function ($v) use ($enumClass) {
            if (is_object($v) && $v instanceof $enumClass && property_exists($v, 'value')) {
                return $v->value;
            }
            $inst = $enumClass::tryFrom($v);
            return $inst?->value;
        };

        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $val = $cast($item);
                if ($val === null) {
                    return null;
                }
                $out[] = $val;
            }
            return $out;
        }

        return $cast($value);
    }

    /**
     * Converts emojis or special characters in the message content to appropriate HTML entities or format.
     *
     * @param string $content The content to process.
     * @return string The processed content.
     */
    public static function emojis($content): string
    {
        static $emojiMap = [
            ':)' => '😊',
            ':-)' => '😊',
            ':(' => '☹️',
            ':-(' => '☹️',
            ':D' => '😄',
            ':-D' => '😄',
            ':P' => '😛',
            ':-P' => '😛',
            ';)' => '😉',
            ';-)' => '😉',
            ':o' => '😮',
            ':-o' => '😮',
            ':O' => '😮',
            ':-O' => '😮',
            'B)' => '😎',
            'B-)' => '😎',
            ':|' => '😐',
            ':-|' => '😐',
            ':/' => '😕',
            ':-/' => '😕',
            ':\\' => '😕',
            ':-\\' => '😕',
            ':*' => '😘',
            ':-*' => '😘',
            '<3' => '❤️',
            '</3' => '💔',
            ':@' => '😡',
            ':-@' => '😡',
            ':S' => '😖',
            ':-S' => '😖',
            ':$' => '😳',
            ':-$' => '😳',
            ':X' => '🤐',
            ':-X' => '🤐',
            ':#' => '🤐',
            ':-#' => '🤐',
            ':^)' => '😊',
            ':v' => '😋',
            ':3' => '😺',
            'O:)' => '😇',
            'O:-)' => '😇',
            '>:)' => '😈',
            '>:-)' => '😈',
            'D:' => '😧',
            'D-:' => '😧',
            ':-o' => '😯',
            ':p' => '😋',
            ':-p' => '😋',
            ':b' => '😋',
            ':-b' => '😋',
            ':^/' => '😕',
            ':-^/' => '😕',
            '>_<' => '😣',
            '-_-' => '😑',
            '^_^' => '😊',
            'T_T' => '😢',
            'TT_TT' => '😭',
            'xD' => '😆',
            'XD' => '😆',
            'xP' => '😝',
            'XP' => '😝',
            ':wave:' => '👋',
            ':thumbsup:' => '👍',
            ':thumbsdown:' => '👎',
            ':clap:' => '👏',
            ':fire:' => '🔥',
            ':100:' => '💯',
            ':poop:' => '💩',
            ':smile:' => '😄',
            ':smirk:' => '😏',
            ':sob:' => '😭',
            ':heart:' => '❤️',
            ':broken_heart:' => '💔',
            ':grin:' => '😁',
            ':joy:' => '😂',
            ':cry:' => '😢',
            ':angry:' => '😠',
            ':sunglasses:' => '😎',
            ':kiss:' => '😘',
            ':thinking:' => '🤔',
            ':shocked:' => '😲',
            ':shhh:' => '🤫',
            ':nerd:' => '🤓',
            ':cool:' => '😎',
            ':scream:' => '😱',
            ':zzz:' => '💤',
            ':celebrate:' => '🎉',
            ':ok_hand:' => '👌',
            ':pray:' => '🙏',
            ':muscle:' => '💪',
            ':tada:' => '🎉',
            ':eyes:' => '👀',
            ':star:' => '⭐',
            ':bulb:' => '💡',
            ':chicken:' => '🐔',
            ':cow:' => '🐮',
            ':dog:' => '🐶',
            ':cat:' => '🐱',
            ':fox:' => '🦊',
            ':lion:' => '🦁',
            ':penguin:' => '🐧',
            ':pig:' => '🐷',
            ':rabbit:' => '🐰',
            ':tiger:' => '🐯',
            ':unicorn:' => '🦄',
            ':bear:' => '🐻',
            ':elephant:' => '🐘',
            ':monkey:' => '🐒',
            ':panda:' => '🐼',
        ];

        return strtr($content, $emojiMap);
    }

    /**
     * Validate a value against a set of rules.
     *
     * Supports:
     * - string pipe syntax: "required|min:2|max:50"
     * - Rule builder: Rule::required()->min(2)->max(50)
     * - array syntax: ['required', 'min:2', 'max:50']
     *
     * @param mixed $value
     * @param string|Rule|array<int,string> $rules
     * @param mixed $confirmationValue
     * @return bool|string|null
     */
    public static function withRules($value, string|Rule|array $rules, $confirmationValue = null)
    {
        $rulesArray = self::normalizeRules($rules);

        foreach ($rulesArray as $rule) {
            if ($rule === '') {
                continue;
            }

            // Split only first ":" so regex patterns with ":" are safer
            if (strpos($rule, ':') !== false) {
                [$ruleName, $parameter] = explode(':', $rule, 2);
                $result = self::applyRule($ruleName, $parameter, $value, $confirmationValue);
            } else {
                $result = self::applyRule($rule, null, $value, $confirmationValue);
            }

            if ($result !== true) {
                return $result;
            }
        }

        return true;
    }

    /**
     * @param string|Rule|array<int,string> $rules
     * @return array<int,string>
     */
    private static function normalizeRules(string|Rule|array $rules): array
    {
        if ($rules instanceof Rule) {
            return $rules->toArray();
        }

        if (is_array($rules)) {
            return array_values(array_filter(array_map('trim', $rules), static fn($r) => $r !== ''));
        }

        return array_values(array_filter(array_map('trim', explode('|', $rules)), static fn($r) => $r !== ''));
    }

    /**
     * Apply an individual rule to a value.
     *
     * @param string $rule The rule to apply.
     * @param mixed $parameter The parameter for the rule, if applicable.
     * @param mixed $value The value to validate.
     * @return bool|string True if the rule passes, or a string with an error message if it fails.
     */
    private static function applyRule(string $rule, $parameter, $value, $confirmationValue = null)
    {
        switch ($rule) {
            case 'required':
                if (empty($value) && $value !== '0') {
                    return "This field is required.";
                } else {
                    return true;
                }
                break;
            case 'min':
                if (strlen($value) < (int)$parameter) {
                    return "This field must be at least $parameter characters long.";
                } else {
                    return true;
                }
                break;
            case 'max':
                if (strlen($value) > (int)$parameter) {
                    return "This field must not exceed $parameter characters.";
                } else {
                    return true;
                }
                break;
            case 'startsWith':
                if (strpos($value, $parameter) !== 0) {
                    return "This field must start with $parameter.";
                } else {
                    return true;
                }
                break;
            case 'endsWith':
                if (substr($value, -strlen($parameter)) !== $parameter) {
                    return "This field must end with $parameter.";
                } else {
                    return true;
                }
                break;
            case 'confirmed':
                if ($confirmationValue !== $value) {
                    return "The $rule confirmation does not match.";
                } else {
                    return true;
                }
                break;
            case 'email':
                return self::email($value) ? true : "This field must be a valid email address.";
            case 'url':
                return self::url($value) ? true : "This field must be a valid URL.";
            case 'ip':
                return self::ip($value) ? true : "This field must be a valid IP address.";
            case 'uuid':
                return self::uuid($value) ? true : "This field must be a valid UUID.";
            case 'ulid':
                return self::ulid($value) ? true : "This field must be a valid ULID.";
            case 'cuid':
                return self::cuid($value) ? true : "This field must be a valid CUID.";
            case 'int':
                return self::int($value) !== null ? true : "This field must be an integer.";
            case 'float':
                return self::float($value) !== null ? true : "This field must be a float.";
            case 'boolean':
                return self::boolean($value) !== null ? true : "This field must be a boolean.";
            case 'in':
                if (!in_array($value, explode(',', $parameter), true)) {
                    return "The selected value is invalid.";
                } else {
                    return true;
                }
                break;
            case 'notIn':
                if (in_array($value, explode(',', $parameter), true)) {
                    return "The selected value is invalid.";
                } else {
                    return true;
                }
                break;
            case 'size':
                if (strlen($value) !== (int)$parameter) {
                    return "This field must be exactly $parameter characters long.";
                } else {
                    return true;
                }
                break;
            case 'between':
                [$min, $max] = explode(',', $parameter);
                if (strlen($value) < (int)$min || strlen($value) > (int)$max) {
                    return "This field must be between $min and $max characters long.";
                } else {
                    return true;
                }
                break;
            case 'date':
                return self::date($value, $parameter ?: 'Y-m-d') ? true : "This field must be a valid date.";
            case 'dateFormat':
                if (!DateTime::createFromFormat($parameter, $value)) {
                    return "This field must match the format $parameter.";
                } else {
                    return true;
                }
                break;
            case 'before':
                if (strtotime($value) >= strtotime($parameter)) {
                    return "This field must be a date before $parameter.";
                } else {
                    return true;
                }
                break;
            case 'after':
                if (strtotime($value) <= strtotime($parameter)) {
                    return "This field must be a date after $parameter.";
                } else {
                    return true;
                }
                break;
            case 'json':
                return self::json($value) ? true : "This field must be a valid JSON string.";
                break;
            case 'timezone':
                if (!in_array($value, timezone_identifiers_list())) {
                    return "This field must be a valid timezone.";
                } else {
                    return true;
                }
                break;
            case 'regex':
                if (!preg_match($parameter, $value)) {
                    return "This field format is invalid.";
                } else {
                    return true;
                }
                break;
            case 'digits':
                if (!ctype_digit($value) || strlen($value) != $parameter) {
                    return "This field must be $parameter digits.";
                } else {
                    return true;
                }
                break;
            case 'digitsBetween':
                [$min, $max] = explode(',', $parameter);
                if (!ctype_digit($value) || strlen($value) < (int)$min || strlen($value) > (int)$max) {
                    return "This field must be between $min and $max digits.";
                } else {
                    return true;
                }
                break;
            case 'extensions':
                $extensions = explode(',', $parameter);
                if (!self::isExtensionAllowed($value, $extensions)) {
                    return "The file must have one of the following extensions: " . implode(', ', $extensions) . ".";
                } else {
                    return true;
                }
                break;
            case 'mimes':
                $mimeTypes = explode(',', $parameter);
                if (!self::isMimeTypeAllowed($value, $mimeTypes)) {
                    return "The file must be of type: " . implode(', ', $mimeTypes) . ".";
                } else {
                    return true;
                }
                break;
            case 'file':
                if (!is_uploaded_file($value)) {
                    return "This field must be a valid file.";
                } else {
                    return true;
                }
                break;
            // Add additional rules as needed...
            default:
                return true;
        }
    }

    /**
     * Check if a file's extension is in the list of allowed extensions.
     *
     * @param string $file The path or filename of the file.
     * @param array $allowedExtensions The list of allowed extensions.
     * @return bool True if the extension is allowed, false otherwise.
     */
    private static function isExtensionAllowed($file, array $allowedExtensions): bool
    {
        // Extract the file extension
        $fileExtension = pathinfo($file, PATHINFO_EXTENSION);

        // Check if the extension is in the allowed list
        return in_array(strtolower($fileExtension), array_map('strtolower', $allowedExtensions), true);
    }

    /**
     * Check if a file's MIME type is in the list of allowed MIME types.
     *
     * @param string $file The path or filename of the file.
     * @param array $allowedMimeTypes The list of allowed MIME types.
     * @return bool True if the MIME type is allowed, false otherwise.
     */
    private static function isMimeTypeAllowed($file, array $allowedMimeTypes)
    {
        // Check if the file is a valid uploaded file
        if (!is_uploaded_file($file)) {
            return false;
        }

        // Get the MIME type of the file using PHP's finfo_file function
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file);

        // Check if the MIME type is in the list of allowed MIME types
        return in_array($mimeType, $allowedMimeTypes, true);
    }
}
