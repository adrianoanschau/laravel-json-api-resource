<?php

namespace Anxis\LaravelJsonApiResource\Http\Resource\JsonApi;

use Illuminate\Http\Resources\Json\JsonResource as LaravelResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Resource extends LaravelResource
{
    protected $resourceName;
    protected $attributes = [];
    protected $mappable = [];
    protected $related = [];

    protected $includes = [];

    private $resources = [];

    public function toArray($request)
    {
        $data = [
            'id' => (string)$this->getKey(),
            'type' => $this->resolveResourceName(),
            'attributes' => $this->getResourceAttributes(),
            'relationships' => $this->when($this->hasRelationships(), $this->getRelationships()),
        ];

        return $data;
    }

    public function with($request)
    {
        $with = [];

        if ($this->hasIncludes()) {
            $with['included'] =  $this->getIncludedResources();
        }

        if (isset($this->route)) {
            $with['links'] = [
                'self' => $this->getSelfLink()
            ];
        }

        return $with;
    }

    public function resolveResourceName()
    {
        return ($this->resourceName) ? $this->resourceName : $this->resourceName = $this->getTable();
    }

    public function getResourceAttributes()
    {
        $attributes = [];

        foreach (array_keys($this->getAttributes()) as $key) {

            if ($key === "id") {
                continue;
            }

            $value = $this->resource->{$key};

            if (!in_array($key, $this->attributes)) {
                continue;
            }

            $method = "get" . Str::studly($key). "Attribute";
            if (method_exists($this, $method)) {
                $value = $this->{$method}($value);
            }

            if ($value instanceof Carbon) {
                $value = $value->format('c');
            }

            $attributes[$this->getMappedKeyName($key)] = $value;
        }

        return $attributes;
    }

    private function getMappedKeyName($key)
    {
        if (isset($this->mappable[$key])) {
            return $this->mappable[$key];
        }
        return $key;
    }

    public function include(array $relations)
    {
        $this->includes = $relations;

        return $this;
    }

    public function hasIncludes()
    {
        return (isset($this->includes) && !empty($this->includes));
    }

    public function getIncludedResources()
    {
        $result = [];

        foreach ($this->resources as $resource) {
            $result[$resource->resolveResourceName()][] = $resource;
        }

        return $result;
    }

    public function loadResourceRelationships()
    {
        $resources = [];

        $this->load(array_keys($this->related));

        $models = $this->getRelations();

        foreach ($models as $model) {
            if ($model == null) {
                continue;
            }

            $relationName = $model->getTable();

            $class = $this->related[$relationName];

            $resources[$relationName] = new $class($model);
        }

        return $this->resources = $resources;
    }

    public function hasRelationships()
    {
        return (isset($this->related) && !empty($this->related));
    }

    public function getRelationships()
    {
        // @TODO filter fields ...relationships are fields too

        $relationships = [];

        $relatedResources  = $this->loadResourceRelationships();

        // @TODO add self relationships to links
        foreach ($relatedResources as $relation) {
            $relationships[] = [
                'links' => [
                    'related' => $this->getSelfLink(),
                ],
                'data' => [
                    'type' => $relation->resolveResourceName(),
                    'id' => $relation->id,
                ]
            ];
        }

        return $relationships;
    }

    public function getSelfLink()
    {
        $routeParameters = [];

        $parameters = $this->getRouteParameters();

        foreach ($parameters as $resourceName) {
            // Register self
            if ($resourceName == $this->resolveResourceName()) {
                $routeParameters[$resourceName] = $this->id;
                continue;
            }

            if (isset($this->resources[$resourceName])) {
                $routeParameters[$resourceName] = $this->resources[$resourceName]->id;
            }
        }

        return route($this->route, $routeParameters);
    }

    public function getRouteParameters()
    {
        $parameters = [];

        $router = app('Illuminate\Routing\Router');

        $routes = $router->getRoutes();

        if ($routes->hasNamedRoute($this->route)) {
            $parameters = $routes->getByName($this->route)->parameterNames();
        }

        return $parameters;
    }
}
