<?php

namespace Dev1437\LaravelApiTyper;

use Dev1437\LaravelApiTyper\Attributes\ApiReturnType;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;

class ControllerAnalyzer
{
    private array $availableModels = [];

    private array $returnTypes = [];

    /**
     * Set the list of available models
     */
    public function setAvailableModels(array $models): void
    {
        $this->availableModels = $models;
    }

    public function setReturnTypes(array $returnTypes): void
    {
        $this->returnTypes = $returnTypes;
    }

    /**
     * Analyze a controller method and infer its return type
     */
    public function analyzeMethod(string $controllerClass, string $methodName, Route $route): ?ApiReturnType
    {
        $reflectionClass = new ReflectionClass($controllerClass);

        if (!$reflectionClass->hasMethod($methodName)) {
            return null;
        }

        $method = $reflectionClass->getMethod($methodName);

        // First, check for ApiReturnType attribute
        $attribute = $this->getApiReturnTypeAttribute($method);
        if ($attribute) {
            return $attribute;
        }

        // If no attribute, try to infer from method signature and name
        return $this->getApiReturnTypeFromPhpstanType($controllerClass, $methodName);
    }

    private function getApiReturnTypeFromPhpstanType(string $controllerClass, string $methodName): ?ApiReturnType
    {
        $methodData = $this->returnTypes[$controllerClass][$methodName] ?? null;

        if (!$methodData) {
            return null;
        }

        // If requires_attribute is true, return null to indicate attribute should be added
        if ($methodData['requires_attribute'] ?? false) {
            return null;
        }

        $returnTypeString = $methodData['return_type'] ?? null;
        $resourceInfo = $methodData['returns_resource'] ?? null;

        if (!$returnTypeString) {
            return null;
        }

        // Initialize flags
        $isCollection = false;
        $isPaginated = false;
        $model = null;
        $resource = null;
        $with = [];

        // Handle resource returns
        if ($resourceInfo !== null && is_array($resourceInfo)) {
            $resource = $resourceInfo['resource_class'] ?? null;
            $modelType = $resourceInfo['model_type'] ?? null;
            $with = $resourceInfo['fields'] ?? [];

            // Extract model from model_type
            if ($modelType) {
                $model = $this->extractModelFromReturnType($modelType);
            }

            // Check if resource is a ResourceCollection
            if ($resource && (str_contains($resource, 'ResourceCollection') ||
                str_ends_with($resource, 'Collection'))) {
                $isCollection = true;
                $isPaginated = true;
            }

            // If we found a resource, return early with resource info
            if ($resource) {
                return new ApiReturnType(
                    model: $model,
                    isCollection: $isCollection,
                    isPaginated: $isPaginated,
                    resource: class_basename($resource),
                    with: $with
                );
            }
        }

        // Check if it's a paginator type
        if (str_contains($returnTypeString, 'LengthAwarePaginator') ||
            str_contains($returnTypeString, 'Paginator') ||
            str_contains($returnTypeString, 'CursorPaginator')) {
            $isPaginated = true;
            $isCollection = true; // Paginators are also collections

            // Extract model from generic type like LengthAwarePaginator<App\Models\User>
            // or from Collection<int, App\Models\Car> pattern in paginator
            if (preg_match('/<[^>]*,\s*([^>]+)>/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            } elseif (preg_match('/<([^>]+)>/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            }
        }

        // Check if it's a resource collection (fallback if not caught by resourceInfo)
        if (str_contains($returnTypeString, 'ResourceCollection')) {
            $isCollection = true;
            $isPaginated = true; // Resource collections in Laravel are typically paginated

            // Extract model from generic type like ResourceCollection<App\Http\Resources\UserResource>
            if (preg_match('/<([^>]+)>/', $returnTypeString, $matches)) {
                // Resource classes might have "Resource" suffix, try to infer model
                $resourceClass = $matches[1];
                $model = $this->extractModelFromReturnType($resourceClass);

                // If extraction didn't work, try removing "Resource" suffix from class name
                if (!$model && str_ends_with($resourceClass, 'Resource')) {
                    $baseName = class_basename($resourceClass);
                    $modelName = substr($baseName, 0, -8); // Remove "Resource"
                    $model = $this->normalizeModelName($modelName);
                }
            }
        }

        // Check if it's a regular Collection
        if (!$isPaginated && str_contains($returnTypeString, 'Collection') &&
            !str_contains($returnTypeString, 'ResourceCollection')) {
            $isCollection = true;

            // Extract model from generic type like Collection<int, App\Models\Car>
            if (preg_match('/Collection<[^>]*,\s*([^>]+)>/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            } elseif (preg_match('/Collection<([^>]+)>/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            }
        }

        // Check if it's an array type like array{App\Models\Car} or array<int, App\Models\Car>
        if (preg_match('/^array/', $returnTypeString)) {
            $isCollection = true;

            // Extract model from array type like array{App\Models\Car} or array<int, App\Models\Car>
            if (preg_match('/array\{([^}]+)\}/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            } elseif (preg_match('/array<[^>]*,\s*([^>]+)>/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            }
        }

        // If no model extracted yet, try to extract from the return type string directly
        if (!$model) {
            // Check if the return type itself is a model class
            // Handle full class names like App\Models\User
            if (preg_match('/App\\\\Models\\\\([^<\[\]>]+)/', $returnTypeString, $matches)) {
                $model = $this->normalizeModelName($matches[1]);
            } elseif (!str_contains($returnTypeString, '<') &&
                      !str_contains($returnTypeString, '{') &&
                      !str_contains($returnTypeString, 'Collection') &&
                      !str_contains($returnTypeString, 'Paginator')) {
                // Direct model class name (without namespace in some cases)
                $model = $this->extractModelFromReturnType($returnTypeString);
            }
        }

        // Validate that the model exists in available models if we have one
        if ($model && !in_array($model, $this->availableModels)) {
            // Model not found in available models, but still return type info if it's a collection/paginated
            if ($isCollection || $isPaginated) {
                return new ApiReturnType(
                    model: null, // Don't set model if not in available models
                    isCollection: $isCollection,
                    isPaginated: $isPaginated,
                    resource: $resource ? class_basename($resource) : null,
                    with: $with
                );
            }
            return null;
        }

        // Return ApiReturnType if we have useful information
        if ($model || $isCollection || $isPaginated || $resource) {
            return new ApiReturnType(
                model: $model,
                isCollection: $isCollection,
                isPaginated: $isPaginated,
                resource: $resource ? class_basename($resource) : null,
                with: $with
            );
        }

        return null;
    }

    /**
     * Get ApiReturnType attribute from method
     */
    private function getApiReturnTypeAttribute(ReflectionMethod $method): ?ApiReturnType
    {
        $attributes = $method->getAttributes(ApiReturnType::class);

        if (!empty($attributes)) {
            return $attributes[0]->newInstance();
        }

        return null;
    }

    /**
     * Extract model from return type
     */
    private function extractModelFromReturnType(string $returnType): ?string
    {
        // Handle full class names like App\Models\User
        if (preg_match('/App\\\\Models\\\\([^<\[\]>]+)/', $returnType, $matches)) {
            return $this->normalizeModelName($matches[1]);
        }

        // Handle simple class names (assumed to be models)
        if (class_exists($returnType) && str_starts_with($returnType, 'App\\Models\\')) {
            return $this->normalizeModelName(class_basename($returnType));
        }

        // If it's a simple name without namespace, try to normalize it
        if (!str_contains($returnType, '\\')) {
            return $this->normalizeModelName($returnType);
        }

        return null;
    }

    /**
     * Normalize model name to match available models
     */
    private function normalizeModelName(string $modelName): string
    {
        // Handle special cases if needed
        // For now, just return as-is, but this can be extended
        return $modelName;
    }

    /**
     * Check if a model name is valid (exists in available models)
     */
    private function isValidModelName(string $modelName): bool
    {
        return in_array($modelName, $this->availableModels);
    }

    /**
     * Infer model from route parameters
     */
    private function inferModelFromRoute(Route $route): ?string
    {
        $uri = $route->uri();

        // Extract model from route parameters like {user}, {project}, etc.
        if (preg_match('/\{(\w+)\}/', $uri, $matches)) {
            $paramName = $matches[1];

            // Skip common non-model parameters
            $skipParams = ['id', 'uuid', 'slug', 'token'];

            if (!in_array($paramName, $skipParams)) {
                return $this->normalizeModelName(ucfirst($paramName));
            }
        }

        // Extract from route segments
        $segments = explode('/', $uri);
        foreach ($segments as $segment) {
            if (!str_starts_with($segment, '{') && !str_ends_with($segment, '}') && $segment !== 'api' && $segment !== 'v1') {
                $modelName = $this->normalizeModelName(ucfirst($segment));
                if ($this->isValidModelName($modelName)) {
                    return $modelName;
                }
            }
        }

        return null;
    }
}
