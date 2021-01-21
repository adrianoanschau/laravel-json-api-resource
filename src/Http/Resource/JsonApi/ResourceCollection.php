<?php

namespace Anxis\LaravelJsonApiResource\Http\Resource\JsonApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection as JsonResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

class ResourceCollection extends JsonResourceCollection
{
    protected $resourceItemClass = null;
    protected $related = [];
    protected $includes = [];
    protected $paginate = false;
    protected $route = null;

    public function toArray($request)
    {
        if ($this->resource instanceof AbstractPaginator) {
            $this->paginate = true;
        }
        $data = [
            'data' => $this->getCollection(),
        ];

        return $data;
    }

    public function getCollection()
    {
        $class = $this->resolveResourceItemClass();
        $this->collection = $this->collection->map(function ($item) use ($class) {
            if (get_parent_class($item) === Model::class) {
                return new $class($item);
            }
            return $item;
        });
        return $class::collection($this->collection);
    }

    public function with($request)
    {
        $with = [];

        if ($this->hasIncludes()) {
            $with['included'] = $this->getIncludedResources();
        }

        if (isset($this->route)) {
            if ($this->paginate) {
                $paginator = $this->getPagination();
                $with['links'] = $paginator['links'];
                $with['meta'] = $paginator['meta'];
            } else {
                $with['links'] = [
                    'self' => $this->getSelfLink()
                ];
            }
        }

        return $with;
    }

    public function resolveResourceItemClass()
    {
        if ($this->resourceItemClass) {
            return $this->resourceItemClass;
        }

        //Let's guess
        $class = get_class($this);

        $class = substr_replace($class, '', -10);

        if (class_exists($class)) {
            return $class;
        }

        return Resource::class;
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

        $this->loadResourceRelationships();

        foreach ($this->includes as $include) {
            if (!isset($this->resources[$include])) {
                continue;
            }
            $resources = $this->resources[$include];
            foreach ($resources as $resource) {
                if (get_parent_class($resource) === ResourceCollection::class) {
                    foreach($resource->resource as $item) {
                        $result[] = $item;
                    }
                } else {
                    $result[] = $resource;
                }
            }
        }

        return $result;
    }

    public function loadResourceRelationships()
    {
        $resources = [];

        foreach ($this->collection as $item) {

            $relations = $item->loadResourceRelationships();

            foreach ($relations as $relationName => $relation) {
                if (is_null($relation)) {
                    continue;
                }
                if (!isset($resources[$relationName])) {
                    $resources[$relationName] = [];
                }
                $resources[$relationName][] = $relation;
            }
        }

        return $this->resources = $resources;
    }

    public function getSelfLink()
    {
        $routeParameters = [];

        $parameters = $this->getRouteParameters();

        foreach ($parameters as $resourceName) {
            // Register self
            if ($resourceName == $this->resolveResourceName()) {
                $routeParameters[$resourceName] = $this->resolveResourceName();
                continue;
            }

            if (isset($this->resources[$resourceName])) {
                $routeParameters[$resourceName] = $this->resources[$resourceName]->id;
            }
        }

        return $this->getRouteWithoutDomain(route($this->route, $routeParameters));
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

    public function getPagination()
    {
        return [
            'links' => [
                'self' => $this->getRouteWithoutDomain($this->resource->url($this->resource->currentPage())),
                'first' => $this->getRouteWithoutDomain($this->resource->url(1)),
                'prev' => $this->getRouteWithoutDomain($this->resource->previousPageUrl()),
                'next' => $this->getRouteWithoutDomain($this->resource->nextPageUrl()),
                'last' => $this->getRouteWithoutDomain($this->url($this->lastPage())),
            ],
            'meta' => [
                'current_page' => $this->resource->currentPage(),
                'from' => $this->resource->firstItem(),
                'last_page' => $this->resource->lastPage(),
                'path' => $this->getSelfLink(),
                'per_page' => $this->resource->perPage(),
                'to' => $this->resource->lastItem(),
                'total' => $this->resource->total(),
            ]
        ];
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toResponse($request)
    {
        return JsonResource::toResponse($request);
    }

    private function getRouteWithoutDomain(string $route = null)
    {
        if (!is_null($route)) {
            $url = parse_url($route);
            if (!is_null($url) && is_array($url)) {
                $result = '';
                if (isset($url['path'])) {
                    $result .= $url['path'];
                }
                if (isset($url['path'])) {
                    $result .= "?{$url['query']}";
                }
                return $result;
            }
        }
    }
}
