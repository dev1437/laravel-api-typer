<?php

namespace Dev1437\LaravelApiTyper\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiReturnType
{
    public function __construct(
        public ?string $model = null,
        public bool $isCollection = false,
        public bool $isPaginated = false,
        public ?string $resource = null,
        public ?string $customType = null,
        public array $with = [],
        public ?string $description = null
    ) {}

    /**
     * Get the TypeScript type representation
     */
    public function getTypeScriptType(): string
    {
        if ($this->customType) {
            return $this->customType;
        }

        $type = $this->buildBaseType();

        if ($this->isPaginated) {
            return "ResourcePaginator<{$type}>";
        }

        if ($this->isCollection) {
            // If using a resource with collection, Laravel returns { data: [<model>, ...] }
            if ($this->resource) {
                return "{ data: {$type}[] }";
            }

            return "{$type}[]";
        }

        // If using a resource, wrap in data object since Laravel resources return { data: <model> }
        if ($this->resource) {
            return "{ data: {$type} }";
        }

        return $type;
    }

    /**
     * Build the base type, including with fields if present
     */
    private function buildBaseType(): string
    {
        if (empty($this->with)) {
            return $this->model ?: 'any';
        }

        // If we have 'with' fields, create an intersection type or extended type
        $baseType = $this->model ?: 'Record<string, any>';

        // Build additional fields type from 'with' array
        $withFields = $this->buildWithFieldsType();

        if ($withFields) {
            // Use intersection type to combine base model with additional fields
            return "{$baseType} & {$withFields}";
        }

        return $baseType;
    }

    /**
     * Build a TypeScript type from the 'with' fields
     */
    private function buildWithFieldsType(): ?string
    {
        if (empty($this->with)) {
            return null;
        }

        $fields = [];

        foreach ($this->with as $field) {
            // Skip spread operators and other special cases
            if (str_starts_with($field, '...') || str_starts_with($field, '$')) {
                // For fields starting with $, normalize the name
                if (str_starts_with($field, '$')) {
                    $normalizedField = lcfirst(substr($field, 1));
                    $fields[] = "  {$normalizedField}: any";
                }
                continue;
            }

            // Add regular fields as 'any' type since we don't have type information
            $fields[] = "  {$field}: any";
        }

        if (empty($fields)) {
            return null;
        }

        return "{\n" . implode(";\n", $fields) . ";\n}";
    }

    /**
     * Get the model name for interface generation
     */
    public function getModelName(): ?string
    {
        return $this->model;
    }

    /**
     * Check if this return type should generate an interface
     */
    public function shouldGenerateInterface(): bool
    {
        return !empty($this->model) && empty($this->customType);
    }

    /**
     * Get resource fields for documentation or type generation
     */
    public function getResourceFields(): array
    {
        return $this->with;
    }

    /**
     * Check if this return type uses a resource
     */
    public function hasResource(): bool
    {
        return !empty($this->resource);
    }

    /**
     * Get the resource class name (base name without namespace)
     */
    public function getResourceName(): ?string
    {
        return $this->resource;
    }
}
