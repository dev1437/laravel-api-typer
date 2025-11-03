<?php

namespace Dev1437\LaravelApiTyper;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\VoidType;
use PHPStan\Type\TypeCombinator;

/**
 * @implements Rule<Node\Stmt\ClassMethod>
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
        return \PHPStan\Node\InClassMethodNode::class;
    }
    
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isController($scope)) {
            return [];
        }
        
        $classMethod = $node->getOriginalNode();
        $methodName = $classMethod->name->toString();
        $className = $scope->getClassReflection()->getName();
        
        // Now the scope has the parameter types available
        $returnTypes = [];
        $hasErrorType = false;
        $this->findReturnTypes($classMethod->stmts ?? [], $scope, $returnTypes, $hasErrorType);
        
        

        $unionType = empty($returnTypes) 
            ? new \PHPStan\Type\VoidType()
            : \PHPStan\Type\TypeCombinator::union(...$returnTypes);

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

    private function findReturnTypes(array $stmts, Scope $scope, array &$returnTypes, bool &$hasErrorType): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null) {
                $type = $scope->getType($stmt->expr);
                
                // Track if ErrorType is encountered
                if ($type instanceof ErrorType) {
                    $hasErrorType = true;
                    $type = new MixedType();
                }
                
                $returnTypes[] = $type;
            }
            
            // Recursively check nested statements
            foreach ($stmt->getSubNodeNames() as $subNodeName) {
                $subNode = $stmt->$subNodeName;
                if (is_array($subNode)) {
                    $this->findReturnTypes($subNode, $scope, $returnTypes, $hasErrorType);
                }
            }
        }
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

    // private function findReturnTypes(Node $node, Scope $scope, array &$returnTypes): void
    // {
    //     if ($node instanceof Node\Stmt\Return_ && $node->expr !== null) {
    //         $returnTypes[] = $scope->getType($node->expr);
    //     }
        
    //     foreach ($node->getSubNodeNames() as $subNodeName) {
    //         $subNode = $node->$subNodeName;
            
    //         if ($subNode instanceof Node) {
    //             $this->findReturnTypes($subNode, $scope, $returnTypes);
    //         } elseif (is_array($subNode)) {
    //             foreach ($subNode as $item) {
    //                 if ($item instanceof Node) {
    //                     $this->findReturnTypes($item, $scope, $returnTypes);
    //                 }
    //             }
    //         }
    //     }
    // }
    
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