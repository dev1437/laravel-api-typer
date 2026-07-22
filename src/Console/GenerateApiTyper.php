<?php

namespace Dev1437\LaravelApiTyper\Console;

use Dev1437\LaravelApiTyper\ApiRouteFinder;
use Dev1437\LaravelApiTyper\ControllerAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Process\Process;

class GenerateApiTyper extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'typer:generate {--output=resources/js/api-returns.d.ts : Output file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate API return types from your laravel controllers';

    private ?ApiRouteFinder $routeFinder = null;

    private ?ControllerAnalyzer $controllerAnalyzer = null;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Analyzing controllers with PHPStan...');

        // Get return types from PHPStan
        $types = $this->getReturnTypes();

        $this->info('Analyzing API routes and controllers...');

        $outputPath = base_path($this->option('output'));
        $modelsPath = resource_path('js/models');

        if (!File::exists($modelsPath)) {
            $this->error('Models directory not found: '.$modelsPath);

            return 1;
        }

        // Get all API routes
        $routeFinder = $this->getRouteFinder();
        $routes = $routeFinder->getApiRoutes();
        $this->info('Found '.count($routes).' API routes');

        // Get available models
        $availableModels = $this->getAvailableModels();
        $this->info('Found '.count($availableModels).' available models');

        // Set up controller analyzer with return types and models
        $controllerAnalyzer = $this->getControllerAnalyzer();
        $controllerAnalyzer->setAvailableModels($availableModels);
        $controllerAnalyzer->setReturnTypes($types);

        // Analyze routes and generate return types
        $apiReturns = [];
        $models = [];
        $processedModels = [];

        foreach ($routes as $routeName => $route) {
            $routeInfo = $routeFinder->getRouteInfo($routeName);

            if (!$routeInfo) {
                continue;
            }

            $returnType = $controllerAnalyzer->analyzeMethod(
                $routeInfo['controller'],
                $routeInfo['method'],
                $route
            );

            // remove the name space from the output, remove inference for paginators
            if ($returnType) {
                $apiReturns[$routeName] = $returnType->getTypeScriptType();

                // Collect models for interface generation
                if ($returnType->shouldGenerateInterface()) {
                    $modelName = $returnType->getModelName();
                    if ($modelName && !in_array($modelName, $processedModels)) {
                        $models[$modelName] = $this->extractModelType($modelName);
                        $processedModels[] = $modelName;
                    }
                }
            } else {
                $apiReturns[$routeName] = 'unknown';
                $this->warn("Could not determine return type for route: {$routeName}");
            }
        }

        // Generate the TypeScript file
        $this->generateTypeScriptFile($outputPath, $models, $apiReturns);

        // Count unknown types
        $unknownCount = count(array_filter($apiReturns, fn ($type) => $type === 'unknown'));
        $knownCount = count($apiReturns) - $unknownCount;

        $this->info('API return types generated successfully at: '.$outputPath);
        $this->info('Generated '.count($apiReturns).' route mappings ('.$knownCount.' known, '.$unknownCount.' unknown) and '.count($models).' model interfaces');

        return Command::SUCCESS;
    }

    private function getReturnTypes()
    {
        // Clear previous collected data
        $outputFile = sys_get_temp_dir().'/phpstan-controller-types.json';

        // Clear the file
        file_put_contents($outputFile, json_encode([]));

        // Run PHPStan via command line
        $process = new Process([
            base_path('vendor/bin/phpstan'),
            'analyse',
            '--configuration='.__DIR__.'/../phpstan.neon',
            '--error-format=table',
            '--no-progress',
            '--memory-limit=512M',
            '--debug',
            app_path('Http/Controllers'),
        ]);

        $process->run(function ($type, $buffer) {
            // You can output PHPStan's output if needed
            $this->line($buffer);
        });

        // PHPStan may return non-zero even if analysis works (just has errors)
        // So we check if our collector has data
        $types = json_decode(file_get_contents($outputFile), true) ?: [];

        return $types;
    }

    /**
     * Get ApiRouteFinder instance
     */
    private function getRouteFinder(): ApiRouteFinder
    {
        if (!$this->routeFinder) {
            $this->routeFinder = new ApiRouteFinder();
        }

        return $this->routeFinder;
    }

    /**
     * Get ControllerAnalyzer instance
     */
    private function getControllerAnalyzer(): ControllerAnalyzer
    {
        if (!$this->controllerAnalyzer) {
            $this->controllerAnalyzer = new ControllerAnalyzer();
        }

        return $this->controllerAnalyzer;
    }

    /**
     * Get list of available model names from Laravel models directory
     */
    private function getAvailableModels(): array
    {
        $models = collect(File::allFiles(app_path('Models')))
            ->map(function ($item) {
                $model = substr($item->getFilename(), 0, -4);

                return "App\\Models\\{$model}";
            })->filter(function ($class) {
                $valid = false;
                if (class_exists($class)) {
                    $reflection = new ReflectionClass($class);
                    $valid = $reflection->isSubclassOf(Model::class) && !$reflection->isAbstract();
                }

                return $valid;
            })->map(function ($class) {
                // Extract just the model name (e.g., "App\Models\User" -> "User")
                return class_basename($class);
            });

        return $models->values()->toArray();
    }

    /**
     * Check if model file exists and return the model name for import
     */
    private function extractModelType(string $modelName): ?string
    {
        $modelFile = resource_path("js/models/{$modelName}.ts");

        if (!File::exists($modelFile)) {
            $this->warn("Model file not found: {$modelFile}");

            return null;
        }

        // Just return the model name - we'll import it and use TypeScript utility types
        return $modelName;
    }

    /**
     * Generate the complete TypeScript file
     */
    private function generateTypeScriptFile(string $outputPath, array $models, array $apiReturns): void
    {
        $typeDefinitions = [];

        // Add header
        $typeDefinitions[] = '// Auto-generated API return types';
        // $typeDefinitions[] = '// Generated on: '.now()->toDateTimeString();
        $typeDefinitions[] = '// This file is automatically generated. Do not edit manually.';
        $typeDefinitions[] = '';

        // Add imports for Pinia ORM models
        if (!empty($models)) {
            $typeDefinitions[] = '// Import Pinia ORM models';
            $imports = [];
            foreach ($models as $modelName => $model) {
                if ($model) {
                    $imports[] = "import {$modelName} from './models/{$modelName}';";
                }
            }
            $typeDefinitions[] = implode("\n", $imports);
            $typeDefinitions[] = '';

            // Add TypeScript utility type to extract fields from Pinia ORM models
            $typeDefinitions[] = '// Utility type to extract fields from Pinia ORM models';
            $typeDefinitions[] = 'type ModelFields<T> = Omit<T, keyof import(\'pinia-orm\').Model>;';
            $typeDefinitions[] = '';

            // Create type aliases for each model with just the fields
            $typeDefinitions[] = '// Model field types (stripped of Pinia ORM methods)';
            foreach ($models as $modelName => $model) {
                if ($model) {
                    $typeDefinitions[] = "type {$modelName}Fields = ModelFields<{$modelName}>;";
                }
            }
            $typeDefinitions[] = '';
        }

        // Add API return type mappings
        $typeDefinitions[] = '// API Return Type Mappings';
        $typeDefinitions[] = 'declare global {';
        $typeDefinitions[] = '  interface ApiReturns {';

        // Sort routes for consistent output
        ksort($apiReturns);

        foreach ($apiReturns as $routeName => $returnType) {
            // Replace model names with their field types
            $processedReturnType = $this->processReturnType($returnType, $models);
            $typeDefinitions[] = "    '{$routeName}': {$processedReturnType};";
        }

        $typeDefinitions[] = '  }';
        $typeDefinitions[] = '}';
        $typeDefinitions[] = '';
        $typeDefinitions[] = 'export {};';

        // Ensure directory exists
        $directory = dirname($outputPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($outputPath, implode("\n", $typeDefinitions));
    }

    /**
     * Process return type to use field types instead of model names
     */
    private function processReturnType(string $returnType, array $models): string
    {
        foreach ($models as $modelName => $model) {
            if ($model) {
                // Replace model name with field type using word boundaries
                // This ensures we only match complete words
                $returnType = preg_replace(
                    '/\b' . preg_quote($modelName, '/') . '\b/',
                    $modelName . 'Fields',
                    $returnType
                );
            }
        }

        return $returnType;
    }

    private function displayResults(array $types): void
    {
        $this->table(
            ['Class', 'Method', 'Return Type', 'Explicit?'],
            collect($types)->flatMap(function ($methods, $class) {
                return collect($methods)->map(function ($data, $method) use ($class) {
                    return [
                        $class,
                        $method,
                        $data['return_type'],
                        $data['has_explicit_return_type'] ?? 'N/A',
                    ];
                });
            })->toArray()
        );
    }
}
