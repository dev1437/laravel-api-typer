<?php

namespace Dev1437\LaravelApiTyper;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Reflection\ParametersAcceptorSelector;

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
        $this->findReturnTypes($classMethod->stmts ?? [], $scope, $returnTypes);
        
        $unionType = empty($returnTypes) 
            ? new \PHPStan\Type\VoidType()
            : \PHPStan\Type\TypeCombinator::union(...$returnTypes);

        $data = json_decode(file_get_contents($this->outputFile), true) ?: [];

        $data[$className][$methodName] = [
            'file' => $scope->getFile(),
            'line' => $node->getStartLine(),
            'return_type' => $unionType->describe(\PHPStan\Type\VerbosityLevel::precise()),
            'return_type_object' => $unionType,
        ];

        file_put_contents($this->outputFile, json_encode($data, JSON_PRETTY_PRINT));
        
        return [];
    }

    private function findReturnTypes(array $stmts, Scope $scope, array &$returnTypes): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null) {
                $returnTypes[] = $scope->getType($stmt->expr);
            }
            
            // Recursively check nested statements
            foreach ($stmt->getSubNodeNames() as $subNodeName) {
                $subNode = $stmt->$subNodeName;
                if (is_array($subNode)) {
                    $this->findReturnTypes($subNode, $scope, $returnTypes);
                }
            }
        }
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