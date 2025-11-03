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

        if ($this->model) {
            $type = $this->model;
        } else {
            $type = 'any';
        }

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
}
