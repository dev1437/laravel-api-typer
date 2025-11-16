<?php

namespace Dev1437\LaravelApiTyper;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\VoidType;
use PHPStan\Type\TypeCombinator;

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
        // MethodReturnStatementsNode provides return statements with their proper scopes!
        $returnTypes = [];
        $hasErrorType = false;

        foreach ($node->getReturnStatements() as $returnStatement) {
            $returnNode = $returnStatement->getReturnNode();

            if ($returnNode->expr !== null) {
                // Use the scope from the return statement - this is the key!
                // This scope has all variable assignments up to this point
                $statementScope = $returnStatement->getScope();
                $type = $statementScope->getType($returnNode->expr);

                // Track if ErrorType is encountered
                if ($type instanceof ErrorType) {
                    $hasErrorType = true;
                    $type = new MixedType();
                }

                $returnTypes[] = $type;
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
        ];

        file_put_contents($this->outputFile, json_encode($data, JSON_PRETTY_PRINT));

        return [];
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
