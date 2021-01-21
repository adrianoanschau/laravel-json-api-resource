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
    protected $route = null;

    public function toArray($request)
    {
        $data = [
            'id' => $this->resolveResourceKey(),
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

    private function resolveResourceKey()
    {
        $key = $this->resource->getKey();
        if (is_array($key)) {
            $key = join('-', $key);
        }
        return (string)$key;
    }

    public function resolveResourceName()
    {
        return ($this->resourceName) ? $this->resourceName : $this->resourceName = $this->resource->getTable();
    }

    public function getResourceAttributes()
    {
        $attributes = [];

        foreach ($this->attributes as $key) {
            $value = $this->resource->{$key};

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

        foreach ($this->includes as $include) {
            if (!isset($this->resources[$include])) {
                continue;
            }
            $resource = $this->resources[$include];
            if (get_parent_class($resource) === ResourceCollection::class) {
                foreach($resource->resource as $item) {
                    $result[] = $item;
                }
            } else {
                $result[] = $resource;
            }
        }

        return $result;
    }

    public function loadResourceRelationships()
    {
        $resources = [];

        foreach (array_keys($this->related) as $relationName) {
            $class = $this->related[$relationName];
            $model = $this->{$relationName};
            if (is_null($model)) {
                continue;
            }
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
        $relationships = [];

        $relatedResources = $this->loadResourceRelationships();

        foreach ($relatedResources as $relationName => $relation) {
            if (is_null($relation->resource)) {
                continue;
            }

            $relationship = [
                'data' => []
            ];

            if (get_parent_class($relation) === ResourceCollection::class) {
                $class = $relation->resolveResourceItemClass();
                $collection = $class::collection($relation->resource);
                foreach ($collection as $resource) {
                    array_push($relationship['data'], [
                        'id' => $resource->resolveResourceKey(),
                        'type' => $resource->resolveResourceName(),
                    ]);
                }
            } else {
                $relationship['data'] = [
                    'id' => $relation->resolveResourceKey(),
                    'type' => $relation->resolveResourceName(),
                ];
            }

            if (isset($this->route)) {
                $relationship['links'] = [
                    'related' => $this->getSelfLink(),
                ];
            }
            $relationships[$relationName] = $relationship;
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
                $routeParameters[$resourceName] = $this->resolveResourceKey();
                continue;
            }

            if (isset($this->resources[$resourceName])) {
                $routeParameters[$resourceName] = $this->resources[$resourceName]->id;
            }
        }

        return $this->getRoutePath(route($this->route, $routeParameters));
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

    private function getRoutePath(string $route)
    {
        $url = parse_url($route);
        if (!is_null($url) && is_array($url) && isset($url['path'])) {
            return $url['path'];
        }
    }
}
