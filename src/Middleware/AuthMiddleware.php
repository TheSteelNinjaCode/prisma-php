<?php

declare(strict_types=1);

namespace Lib\Middleware;

use Lib\Auth\Auth;
use Lib\Auth\AuthConfig;
use Lib\Request;

final class AuthMiddleware
{
    public static function handle($requestPathname)
    {
        if (AuthConfig::IS_ALL_ROUTES_PRIVATE) {
            $isLogin = Auth::getInstance()->isAuthenticated();
            $isApiAuthRoute = stripos($requestPathname, AuthConfig::API_AUTH_PREFIX) === 0;
            $isPublicRoute = self::matches($requestPathname, AuthConfig::$publicRoutes);
            $isAuthRoute = self::matches($requestPathname, AuthConfig::$authRoutes);

            // Check if the user is authenticated and refresh the token if necessary
            if (AuthConfig::IS_TOKEN_AUTO_REFRESH) {
                $auth = Auth::getInstance();
                if (isset($_COOKIE[Auth::$cookieName])) {
                    $jwt = $_COOKIE[Auth::$cookieName];
                    $jwt = $auth->refreshToken($jwt);
                }
            }

            // Skip the middleware if the route is api auth route
            if ($isApiAuthRoute) {
                return;
            }

            // Redirect to the default sign in route if the user is already authenticated
            if ($isAuthRoute) {
                if ($isLogin) {
                    Request::redirect(AuthConfig::DEFAULT_SIGNIN_REDIRECT);
                }
                return;
            }

            // Redirect to the default home route if the user is already authenticated
            if (!$isLogin && !$isPublicRoute) {
                Request::redirect("/signin");
            }
        } else {
            // Skip the middleware if the route is public
            if (!self::matches($requestPathname, AuthConfig::$privateRoutes)) {
                return;
            }

            // Check if the user is authorized to access the route or redirect to login
            if (!self::isAuthorized()) {
                Request::redirect('/signin');
            }
        }

        // Check if the user has the required role to access the route or redirect to denied
        if (AuthConfig::IS_ROLE_BASE) {
            $matchValue = self::hasRequiredRole($requestPathname);
            if ($matchValue === "Route not in array") {
                // echo "No validation needed for this route.";
            } elseif ($matchValue === "Match") {
                // echo "You are authorized to access this route";
            } elseif ($matchValue === "Role mismatch") {
                // echo "You are not authorized to access this route";
                Request::redirect('/denied');
            } else {
                // echo "Unexpected error encountered";
            }
        }
    }

    protected static function matches(string $requestPathname, array $routes): bool
    {
        foreach ($routes ?? [] as $pattern) {
            $getUriRegexValue = self::getUriRegex($pattern, $requestPathname);
            if ($getUriRegexValue) {
                return true;
            }
        }
        return false;
    }

    protected static function isAuthorized(): bool
    {
        $auth = Auth::getInstance();
        if (!isset($_COOKIE[Auth::$cookieName])) {
            unset($_SESSION[Auth::PAYLOAD_SESSION_KEY]);
            return false;
        }

        $jwt = $_COOKIE[Auth::$cookieName];

        if (AuthConfig::IS_TOKEN_AUTO_REFRESH) {
            $jwt = $auth->refreshToken($jwt);
            $verifyToken = $auth->verifyToken($jwt);
        }

        $verifyToken = $auth->verifyToken($jwt);
        if ($verifyToken === false) {
            return false;
        }

        // Access the PAYLOAD_NAME property using the -> operator instead of array syntax
        if (isset($verifyToken->{Auth::PAYLOAD_NAME})) {
            return true;
        }

        return false;
    }

    protected static function hasRequiredRole(string $requestPathname): string
    {
        $auth = Auth::getInstance();
        $roleBasedRoutes = AuthConfig::$roleBasedRoutes ?? [];

        // Normalize the request path for matching
        $requestPathnameValue = trim($requestPathname, '/');

        foreach ($roleBasedRoutes as $pattern => $data) {
            $patternValue = trim($pattern, '/');
            if ($patternValue === $requestPathnameValue) {
                // Route is found in array, check permissions
                $userRole = Auth::ROLE_NAME ? $auth->getPayload()[Auth::ROLE_NAME] : $auth->getPayload();
                return ($userRole !== null && AuthConfig::checkAuthRole($userRole, $data[AuthConfig::ROLE_IDENTIFIER]))
                    ? "Match"
                    : "Role mismatch";
            }
        }

        // Route not found in role-based routes array
        return "Route not in array";
    }

    private static function getUriRegex(string $pattern, string $requestPathname): int|bool
    {
        // Normalize both the pattern and the request path
        $pattern = strtolower(trim($pattern, '/'));
        $requestPathname = strtolower(trim($requestPathname, '/'));

        // Handle the case where the requestPathname is empty, which means home or "/"
        if (empty($requestPathname)) {
            $requestPathname = '/';
        } else {
            $requestPathname = "/$requestPathname";
        }

        // Construct the regex pattern
        $regex = "#^/?" . preg_quote("/$pattern", '#') . "(/.*)?$#";
        return preg_match($regex, $requestPathname);
    }
}
