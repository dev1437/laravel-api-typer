<?php

namespace App\Console\Commands\Support;

use App\Attributes\ApiReturnType;
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
        try {
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

        } catch (\Exception $e) {
            return null;
        }
    }

    private function getApiReturnTypeFromPhpstanType(string $controllerClass, string $methodName): ?ApiReturnType
    {
        $returnTypeString = $this->returnTypes[$controllerClass][$methodName]['return_type'] ?? null;

        if (!$returnTypeString) {
            return null;
        }

        // Initialize flags
        $isCollection = false;
        $isPaginated = false;
        $model = null;

        // Check if it's a paginator type
        if (str_contains($returnTypeString, 'LengthAwarePaginator') ||
            str_contains($returnTypeString, 'Paginator') ||
            str_contains($returnTypeString, 'CursorPaginator')) {
            $isPaginated = true;
            $isCollection = true; // Paginators are also collections

            // Extract model from generic type like LengthAwarePaginator<App\Models\User>
            if (preg_match('/<([^>]+)>/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            }
        }

        // Check if it's a resource collection
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

        // Check if it's a regular collection
        if (!$isPaginated && str_contains($returnTypeString, 'Collection') &&
            !str_contains($returnTypeString, 'ResourceCollection')) {
            $isCollection = true;

            // Extract model from generic type like Collection<App\Models\User>
            if (preg_match('/Collection<([^>]+)>/', $returnTypeString, $matches)) {
                $model = $this->extractModelFromReturnType($matches[1]);
            }
        }

        // Check if it's an array type
        if (preg_match('/([^\[\]]+)\[\]/', $returnTypeString, $matches)) {
            $isCollection = true;
            $model = $this->extractModelFromReturnType($matches[1]);
        }

        // If no model extracted yet, try to infer from controller
        if (!$model) {
            $model = $this->inferModelFromController($controllerClass);
        }

        // If still no model, try to extract from the return type string directly
        if (!$model) {
            // Check if the return type itself is a model class
            // Handle full class names like App\Models\User
            if (preg_match('/App\\\\Models\\\\([^<\[\]>]+)/', $returnTypeString, $matches)) {
                $model = $this->normalizeModelName($matches[1]);
            } elseif (!str_contains($returnTypeString, '<') &&
                      !str_contains($returnTypeString, '[]') &&
                      !str_contains($returnTypeString, 'Collection') &&
                      !str_contains($returnTypeString, 'Paginator')) {
                // Direct model class name (without namespace in some cases)
                $model = $this->extractModelFromReturnType($returnTypeString);
            }
        }

        // Use method name patterns as hints if return type doesn't indicate collection/pagination
        if (!$isCollection && !$isPaginated) {
            $isCollection = $this->isCollectionMethod($methodName);
            $isPaginated = $this->isPaginatedMethod($methodName);
        }

        // Validate that the model exists in available models if we have one
        if ($model && !in_array($model, $this->availableModels)) {
            // Model not found in available models, but still return type info if it's a collection/paginated
            if ($isCollection || $isPaginated) {
                return new ApiReturnType(
                    model: null, // Don't set model if not in available models
                    isCollection: $isCollection,
                    isPaginated: $isPaginated
                );
            }
            return null;
        }

        // Return ApiReturnType if we have useful information
        if ($model || $isCollection || $isPaginated) {
            return new ApiReturnType(
                model: $model,
                isCollection: $isCollection,
                isPaginated: $isPaginated
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
     * Infer return type from method signature and route information
     */
    private function inferReturnType(ReflectionMethod $method, Route $route): ?ApiReturnType
    {
        $methodName = $method->getName();
        $returnType = $method->getReturnType();

        // Analyze method name patterns
        $isCollection = $this->isCollectionMethod($methodName);
        $isPaginated = $this->isPaginatedMethod($methodName);

        // Try to infer model from controller class name
        $model = $this->inferModelFromController($method->getDeclaringClass()->getName());

        // Try to infer from return type hint
        if ($returnType && !$returnType->isBuiltin()) {
            $returnTypeName = $returnType->getName();

            // Check if it's a resource collection
            if (is_subclass_of($returnTypeName, ResourceCollection::class)) {
                $isCollection = true;
                $isPaginated = true;
            }

            // Check if it's a paginator
            if (is_subclass_of($returnTypeName, LengthAwarePaginator::class)) {
                $isPaginated = true;
            }

            // Try to extract model from return type
            if (!$model) {
                $model = $this->extractModelFromReturnType($returnTypeName);
            }
        }

        // If we still don't have a model, try to infer from route parameters
        if (!$model) {
            $model = $this->inferModelFromRoute($route);
        }

        // Validate that the model exists in available models
        if ($model && !in_array($model, $this->availableModels)) {
            // Model not found in available models, skip this route
            return null;
        }

        if ($model || $isCollection || $isPaginated) {
            return new ApiReturnType(
                model: $model,
                isCollection: $isCollection,
                isPaginated: $isPaginated
            );
        }

        return null;
    }

    /**
     * Check if method name suggests it returns a collection
     */
    private function isCollectionMethod(string $methodName): bool
    {
        $collectionPatterns = ['index', 'search', 'query', 'list', 'all'];

        return in_array($methodName, $collectionPatterns);
    }

    /**
     * Check if method name suggests it returns paginated data
     */
    private function isPaginatedMethod(string $methodName): bool
    {
        $paginatedPatterns = ['index', 'search', 'query', 'list'];

        return in_array($methodName, $paginatedPatterns);
    }

    /**
     * Infer model name from controller class name
     */
    private function inferModelFromController(string $controllerClass): ?string
    {
        // Extract controller name (e.g., "UserController" -> "User")
        $controllerName = class_basename($controllerClass);

        if (str_ends_with($controllerName, 'Controller')) {
            $modelName = substr($controllerName, 0, -10); // Remove "Controller"

            // Handle special cases
            $modelName = $this->normalizeModelName($modelName);

            return $modelName;
        }

        return null;
    }

    /**
     * Extract model from return type
     */
    private function extractModelFromReturnType(string $returnType): ?string
    {
        // Handle generic types like Collection<User>
        if (preg_match('/Collection<([^>]+)>/', $returnType, $matches)) {
            return $this->normalizeModelName($matches[1]);
        }

        // Handle array types like User[]
        if (preg_match('/([^\[\]]+)\[\]/', $returnType, $matches)) {
            return $this->normalizeModelName($matches[1]);
        }

        // Direct model class
        if (class_exists($returnType)) {
            return $this->normalizeModelName(class_basename($returnType));
        }

        return null;
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

    /**
     * Normalize model name to match TypeScript interface names
     */
    private function normalizeModelName(string $modelName): string
    {
        // Handle special cases
        $normalizations = [
            'WbsCode' => 'WbsCode',
            'ProjectSupplier' => 'ProjectSupplier',
            'PackageTradeItem' => 'PackageTradeItem',
            'VariationOrder' => 'VariationOrder',
            'LabourDailyRecord' => 'LabourDailyRecord',
            'PlantDailyRecord' => 'PlantDailyRecord',
            'DailyQuantity' => 'DailyQuantity',
            'DailyQuantityLoads' => 'DailyQuantityLoads',
            'PlantCategory' => 'PlantCategory',
            'PlantHirePeriod' => 'PlantHirePeriod',
            'SurveyedQuantity' => 'SurveyedQuantity',
            'TradeItemCost' => 'TradeItemCost',
            'VariationOrderCost' => 'VariationOrderCost',
            'LumpSumCost' => 'LumpSumCost',
            'RatesCost' => 'RatesCost',
            'ReportSection' => 'ReportSection',
            'ReportSectionConfig' => 'ReportSectionConfig',
            'PackageClaim' => 'PackageClaim',
        ];

        return $normalizations[$modelName] ?? $modelName;
    }

    /**
     * Check if a model name is valid (exists in available models)
     */
    private function isValidModelName(string $modelName): bool
    {
        return in_array($modelName, $this->availableModels);
    }
}
