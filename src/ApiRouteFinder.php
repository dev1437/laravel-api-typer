<?php

namespace Dev1437\LaravelApiTyper;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

class ApiRouteFinder
{
    /**
     * Get all API routes
     */
    public function getApiRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if ($this->isApiRoute($route)) {
                $routeName = $route->getName();
                if ($routeName) {
                    $routes[$routeName] = $route;
                }
            }
        }

        return $routes;
    }

    /**
     * Check if a route is an API route
     */
    private function isApiRoute(Route $route): bool
    {
        $uri = $route->uri();
        $middleware = $route->gatherMiddleware();

        // Check if it's an API route by URI pattern
        if (str_starts_with($uri, 'api/')) {
            return true;
        }

        // Check if it has API middleware
        if (in_array('api', $middleware) || in_array('auth:sanctum', $middleware)) {
            return true;
        }

        return false;
    }

    /**
     * Get routes grouped by controller
     */
    public function getRoutesByController(): array
    {
        $routes = $this->getApiRoutes();
        $grouped = [];

        foreach ($routes as $routeName => $route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? null;

            if ($controller && is_string($controller)) {
                $controllerClass = explode('@', $controller)[0];
                $method = explode('@', $controller)[1] ?? null;

                if (!isset($grouped[$controllerClass])) {
                    $grouped[$controllerClass] = [];
                }

                $grouped[$controllerClass][] = [
                    'name' => $routeName,
                    'route' => $route,
                    'method' => $method,
                    'uri' => $route->uri(),
                    'methods' => $route->methods(),
                ];
            }
        }

        return $grouped;
    }

    /**
     * Get route information for a specific route name
     */
    public function getRouteInfo(string $routeName): ?array
    {
        $routes = $this->getApiRoutes();

        if (!isset($routes[$routeName])) {
            return null;
        }

        $route = $routes[$routeName];
        $action = $route->getAction();
        $controller = $action['controller'] ?? null;

        if ($controller && is_string($controller)) {
            $controllerClass = explode('@', $controller)[0];
            $method = explode('@', $controller)[1] ?? null;

            return [
                'name' => $routeName,
                'route' => $route,
                'controller' => $controllerClass,
                'method' => $method,
                'uri' => $route->uri(),
                'methods' => $route->methods(),
            ];
        }

        return null;
    }
}
