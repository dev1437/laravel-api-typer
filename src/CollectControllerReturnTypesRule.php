<?php

namespace Dev1437\LaravelApiTyper;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\VoidType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\ObjectType;
use PHPStan\Reflection\ClassReflection;

/**
 * @implements Rule<\PHPStan\Node\MethodReturnStatementsNode>
 */
class CollectControllerReturnTypesRule implements Rule
{
    private string $outputFile;

    public function __construct()
    {
        $this->outputFile = sys_get_temp_dir() . '/phpstan-controller-types.json';

        // Initialize file
        if (!file_exists($this->outputFile)) {
            file_put_contents($this->outputFile, json_encode([]));
        }
    }

    private static array $collectedTypes = [];

    public function getNodeType(): string
    {
        return \PHPStan\Node\MethodReturnStatementsNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isController($scope)) {
            return [];
        }

        /** @var \PHPStan\Node\MethodReturnStatementsNode $node */
        $classReflection = $scope->getClassReflection();
        $methodReflection = $scope->getFunction();

        if (!$methodReflection instanceof \PHPStan\Reflection\MethodReflection) {
            return [];
        }

        $className = $classReflection->getName();
        $methodName = $methodReflection->getName();

        // Collect return types from the return statements
        $returnTypes = [];
        $hasErrorType = false;
        $resourceInfo = null;

        foreach ($node->getReturnStatements() as $returnStatement) {
            $returnNode = $returnStatement->getReturnNode();

            if ($returnNode->expr !== null) {
                $statementScope = $returnStatement->getScope();
                $type = $statementScope->getType($returnNode->expr);

                // Track if ErrorType is encountered
                if ($type instanceof ErrorType) {
                    $hasErrorType = true;
                    $type = new MixedType();
                }

                $returnTypes[] = $type;

                // Check if this return is a JsonResource
                if ($resourceInfo === null) {
                    $resourceInfo = $this->analyzeResourceReturn($returnNode->expr, $statementScope);
                }
            }
        }

        $unionType = empty($returnTypes)
            ? new VoidType()
            : TypeCombinator::union(...$returnTypes);

        // Check if the union type contains ErrorType or is ErrorType itself
        if (!$hasErrorType) {
            $hasErrorType = ($unionType instanceof ErrorType) || $this->typeContainsErrorType($unionType);
        }

        $data = json_decode(file_get_contents($this->outputFile), true) ?: [];

        $data[$className][$methodName] = [
            'file' => $scope->getFile(),
            'line' => $node->getStartLine(),
            'return_type' => $unionType->describe(\PHPStan\Type\VerbosityLevel::precise()),
            'return_type_object' => $unionType,
            'requires_attribute' => $hasErrorType,
            'returns_resource' => $resourceInfo,
        ];

        file_put_contents($this->outputFile, json_encode($data, JSON_PRETTY_PRINT));

        return [];
    }

    /**
     * Analyze if a return expression is a JsonResource and extract field information
     */
    private function analyzeResourceReturn(Node\Expr $expr, Scope $scope): ?array
    {
        // Check if it's a new resource instantiation: new SomeResource($model)
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $className = $expr->class->toString();

            // Resolve the full class name
            if (!class_exists($className)) {
                $resolvedName = $scope->resolveName($expr->class);
                $className = $resolvedName;
            }

            if (!class_exists($className)) {
                return null;
            }

            $reflection = new \ReflectionClass($className);

            // Check if it's a JsonResource
            if (!$reflection->isSubclassOf('Illuminate\Http\Resources\Json\JsonResource')) {
                return null;
            }

            // Get the model type passed to the resource
            $modelType = null;
            if (!empty($expr->args)) {
                $firstArg = $expr->args[0]->value;
                $argType = $scope->getType($firstArg);
                $modelType = $argType->describe(\PHPStan\Type\VerbosityLevel::precise());
            }

            // Extract fields from the resource's toArray method
            $fields = $this->extractResourceFields($className);

            return [
                'resource_class' => $className,
                'model_type' => $modelType,
                'fields' => $fields,
            ];
        }

        // Check if it's a variable that holds a resource
        $type = $scope->getType($expr);
        if ($type instanceof ObjectType) {
            $classReflection = $type->getClassReflection();
            if ($classReflection && $classReflection->isSubclassOf('Illuminate\Http\Resources\Json\JsonResource')) {
                $fields = $this->extractResourceFields($classReflection->getName());

                return [
                    'resource_class' => $classReflection->getName(),
                    'model_type' => null, // Can't determine from variable
                    'fields' => $fields,
                ];
            }
        }

        return null;
    }

    /**
     * Extract field names from a JsonResource's toArray method
     */
    private function extractResourceFields(string $className): array
    {
        try {
            $reflection = new \ReflectionClass($className);

            // Get the toArray method
            if (!$reflection->hasMethod('toArray')) {
                return [];
            }

            $method = $reflection->getMethod('toArray');
            $fileName = $method->getFileName();

            if (!$fileName || !file_exists($fileName)) {
                return [];
            }

            // Parse the file to extract array keys from toArray method
            $parserFactory = new \PhpParser\ParserFactory();
            $parser = $parserFactory->createForNewestSupportedVersion();

            $code = file_get_contents($fileName);
            $ast = $parser->parse($code);

            if (!$ast) {
                return [];
            }

            // Find the toArray method in the AST
            $fields = [];
            $this->findToArrayMethod($ast, $fields);

            return array_values(array_unique($fields));

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recursively find the toArray method and extract array keys
     */
    private function findToArrayMethod(array $nodes, array &$fields): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'toArray') {
                // Found the toArray method, extract array keys
                $this->extractArrayKeys($node->stmts ?? [], $fields);
                return;
            }

            // Recursively search in child nodes
            if ($node instanceof Node) {
                foreach ($node->getSubNodeNames() as $subNodeName) {
                    $subNode = $node->$subNodeName;
                    if (is_array($subNode)) {
                        $this->findToArrayMethod($subNode, $fields);
                    }
                }
            }
        }
    }

    /**
     * Extract array keys from return statements in toArray method
     */
    private function extractArrayKeys(array $stmts, array &$fields): void
    {
        foreach ($stmts as $stmt) {
            // Look for return statements
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null) {
                $this->extractKeysFromExpr($stmt->expr, $fields);
            }

            // Recursively check nested statements
            if ($stmt instanceof Node) {
                foreach ($stmt->getSubNodeNames() as $subNodeName) {
                    $subNode = $stmt->$subNodeName;
                    if (is_array($subNode)) {
                        $this->extractArrayKeys($subNode, $fields);
                    }
                }
            }
        }
    }

    /**
     * Extract keys from array expressions
     */
    private function extractKeysFromExpr(Node\Expr $expr, array &$fields): void
    {
        // Handle array expressions
        if ($expr instanceof Node\Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }

                // Get the key
                if ($item->key !== null) {
                    $key = $this->getStringFromNode($item->key);
                    if ($key !== null) {
                        $fields[] = $key;
                    }
                }

                // Handle spread operator: ...$this->attributesToArray()
                // if ($item->unpack) {
                //     // We can't determine keys from spread, but we could note it
                //     $fields[] = '...(spread)';
                // }
            }
        }

        // Handle array merge/spread in expressions
        if ($expr instanceof Node\Expr\FuncCall) {
            // Could be array_merge, etc.
            foreach ($expr->args as $arg) {
                $this->extractKeysFromExpr($arg->value, $fields);
            }
        }
    }

    /**
     * Try to get a string value from a node (for array keys)
     */
    private function getStringFromNode(Node\Expr $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        // Handle simple identifiers
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return '$' . $node->name;
        }

        // Handle property access like 'key' => $this->someProperty
        // We just want the key, not the value

        return null;
    }

    private function typeContainsErrorType(\PHPStan\Type\Type $type): bool
    {
        if ($type instanceof ErrorType) {
            return true;
        }

        // Check union types
        if ($type instanceof \PHPStan\Type\UnionType) {
            foreach ($type->getTypes() as $subType) {
                if ($this->typeContainsErrorType($subType)) {
                    return true;
                }
            }
        }

        // Check intersection types
        if ($type instanceof \PHPStan\Type\IntersectionType) {
            foreach ($type->getTypes() as $subType) {
                if ($this->typeContainsErrorType($subType)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isController(Scope $scope): bool
    {
        if (!$scope->isInClass()) {
            return false;
        }

        $classReflection = $scope->getClassReflection();

        // Check if extends Controller
        return $classReflection->isSubclassOf('App\Http\Controllers\Controller')
            || $classReflection->isSubclassOf('Illuminate\Routing\Controller');
    }

    public static function getCollectedTypes(): array
    {
        return self::$collectedTypes;
    }

    public static function clearCollectedTypes(): void
    {
        self::$collectedTypes = [];
    }
}
