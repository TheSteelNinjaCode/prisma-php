<?php

declare(strict_types=1);

namespace PPHP\Auth;

use ArrayObject;

enum AuthRole: string
{
    case Admin = 'Admin';
    case User = 'User';

    public function equals($role)
    {
        return $this->value === $role;
    }
}

final class AuthConfig
{
    public const ROLE_IDENTIFIER = 'role';
    public const IS_ROLE_BASE = false;
    public const IS_TOKEN_AUTO_REFRESH = false;
    public const IS_ALL_ROUTES_PRIVATE = false;

    /**
     * This is the (default) option for authentication. If IS_ALL_ROUTES_PRIVATE is set to false, 
     * An array of private routes that are accessible to all authenticated users
     * without specific role-based access control. Routes should be listed as string paths.
     * Example: public static $privateRoutes = ['/']; // This makes the home page private
     * Example: public static $privateRoutes = ['/profile', '/dashboard/settings']; // These routes are private
     */
    public static array $privateRoutes = [];

    /**
     * This is the (default) option for authentication. If IS_ALL_ROUTES_PRIVATE is set to true,
     * An array of public routes that are accessible to all users, authenticated or not.
     */
    public const DEFAULT_SIGNIN_REDIRECT = '/dashboard'; // Default redirect route after sign in
    public const API_AUTH_PREFIX = '/api/auth'; // Prefix for third-party API authentication routes (github, google, etc.)

    /**
     * An array of public routes that are accessible to all users, authenticated or not.
     * Routes should be listed as string paths.
     * Example: public static $publicRoutes = ['/']; // This makes the home page public
     * Example: public static $publicRoutes = ['/about', '/contact']; // These routes are public
     */
    public static array $publicRoutes = ['/'];
    public static array $authRoutes = [
        '/signin',
        '/signup',
    ];

    /**
     * An associative array mapping specific routes to required user roles for access control.
     * Each route is a key with an array of roles that are allowed access.
     * Format:
     * 'route_path' => [self::ROLE_IDENTIFIER => [AuthRole::Role1, AuthRole::Role2, ...]],
     * Example:
     * public static $roleBasedRoutes = [
     *     'dashboard' => [self::ROLE_IDENTIFIER => [AuthRole::Admin, AuthRole::User]],
     *     'dashboard/users' => [self::ROLE_IDENTIFIER => [AuthRole::Admin]],
     *     'sales' => [self::ROLE_IDENTIFIER => [AuthRole::Admin, AuthRole::User]]
     * ];
     */
    public static array $roleBasedRoutes = [];

    /**
     * Checks if the given user role is authorized to access a set of roles.
     * 
     * @param ArrayObject|string $userRole The user's role to check.
     * @param array<AuthRole> $roles An array of AuthRole instances specifying allowed roles.
     * @return bool Returns true if the user's role matches any of the allowed roles, false otherwise.
     */
    public static function checkAuthRole(ArrayObject|string $userRole, array $roles): bool
    {
        if ($userRole instanceof ArrayObject) {
            $userRole = $userRole[Auth::ROLE_NAME] ?? '';
        }

        foreach ($roles as $role) {
            if ($userRole === $role->value) {
                return true;
            }
        }
        return false;
    }
}
