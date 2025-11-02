<?php

namespace Dev1437\LaravelApiTyper;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * @implements Collector<Node\Stmt\ClassMethod, array<string, mixed>>
 */
class ControllerReturnTypeCollector implements Collector
{
    public function getNodeType(): string
    {
        return Node\Stmt\ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope)
    {
        // Only process controller methods
        if (!$scope->isInClass()) {
            return null;
        }

        $classReflection = $scope->getClassReflection();
        if (!$this->isController($classReflection)) {
            return null;
        }

        if (!$scope->getFunction() instanceof \PHPStan\Reflection\MethodReflection) {
            return null;
        }

        $method = $scope->getFunction();
        $returnType = $method->getVariants()[0]->getReturnType();

        return [
            'class' => $classReflection->getName(),
            'method' => $node->name->toString(),
            'return_type' => $returnType->describe(VerbosityLevel::typeOnly()),
            'return_type_precise' => $returnType->describe(VerbosityLevel::precise()),
            'file' => $scope->getFile(),
            'line' => $node->getLine(),
            'is_public' => $node->isPublic(),
            'has_explicit_return_type' => $node->returnType !== null,
        ];
    }

    private function isController(\PHPStan\Reflection\ClassReflection $class): bool
    {
        return $class->isSubclassOf('Illuminate\Routing\Controller')
            || str_ends_with($class->getName(), 'Controller');
    }
}
