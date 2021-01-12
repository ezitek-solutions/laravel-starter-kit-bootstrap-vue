<?php

namespace App\Services;

use Schema;
use Carbon\Carbon;
use App\Models\Model;

abstract class Service
{
    /**
     * The model to be used for this service.
     *
     * @var \App\Models\Model
     */
    protected $model;

    /**
     * Show the resource with all its relations
     *
     * @var bool
     */
    protected $load_with_relations = true;


    /**
     * Default pagination to use for item listings
     *
     * @var bool
     */
    protected const PER_PAGE = 20;


    /**
     * Default ordering
     *
     * @var bool
     */
    protected $ranking = 'DESC';

    /**
     * Specifies service relations
     *
     * @var array
     */
    protected $relations = [];


    /**
     * Constructor: Initializes the relations array with model relations
     *
     * @return void
     */
    public function __construct()
    {
        $this->relations = $this->model() ? $this->model()->getRelations() : [];
    }

    /**
     * Get a listing of resource matching with query params appplied
     *
     * @param array $data Data coming in from request
     */
    public function all($data = [])
    {
        $params = $this->getQueryParams($data);

        /* New implementations of show with relations */
        if ($this->load_with_relations) {
            if (isset($data['exempted_relations'])) {
                $exempted = explode(',', $data['exempted_relations']);

                $this->relations = array_diff($this->relations, $exempted);
            } elseif (isset($data['relations'])) {
                $this->relations = explode(',', $data['relations']);
            }

            $query = $this->model()->with($this->relations);
        } else {
            $query = $this->model();
        }

        if (isset($params['where'])) {
            $where = $params['where'];

            foreach ($where as $field => $value) {
                $query = $query->where($field, $value);
            }
        }

        $query = $this->applyFilters($query, $data);

        if (isset($params['order'])) {
            $order = $params['order'];

            foreach ($order as $field => $ranking) {
                if (Schema::hasColumn($this->model()->getTable(), $field)) {
                    $query = $query->orderBy($this->model()->getTable() . '.' . $field, $ranking);
                } else {
                    // For custom fields, we expect a custom order-by function defined for it by the model
                    // The function name should be like sortByFieldName and it will handle requests to order by
                    // field_name
                    $sortFunction = 'sortBy' . join(array_map('ucfirst', explode('_', $field)));

                    if (method_exists($this->model(), $sortFunction)) {
                        $query = $this->model()->$sortFunction($query, $ranking);
                    }
                }
            }
        }

        // Add default ordering fields
        $defaultOrderings = [];

        if ($field = $this->model()->getPrimaryKey()) {
            $defaultOrderings[$field] = 'ASC';
        }

        $defaultOrderings['created_at'] = 'DESC';

        foreach ($defaultOrderings as $field => $rank) {
            if (!isset($params['order'][$field]) && Schema::hasColumn($this->model()->getTable(), $field)) {
                $query = $query->orderBy($this->model()->getTable() . '.' . $field, $rank);
            }
        }

        if (isset($params['view_by'])) {
            if ($params['view_by'] == 'all') {
                $query = $query->withTrashed();
            } elseif ($params['view_by'] == 'deleted') {
                $query = $query->onlyTrashed();
            }
        }

        if ($this->shouldPaginate($params['paginate'])) {
            $results = $query->paginate($params['paginate']);
        } else {
            $results = $query->get();
        }

        return $results;
    }

    /**
     * Tells if a model has a particular relation
     *
     * @param string $relation
     * @return bool
     */
    protected function hasRelation($relation): bool
    {
        return method_exists($this->model(), $relation);
    }

    /**
     * Store a new resource with the provided data.
     *
     * @param array $data
     * @return \App\Models\Model|null
     */
    public function store($data = []): Model
    {
        $data = $this->getPreparedSaveData($data);

        if (count($data) < 1) {
            return null;
        }

        $resource = $this->model()->fill($data);
        $resource->save();

        return $resource;
    }

    /**
     * Show the specified resource. Load it with or without its relations
     * depending on the value of the load_with_relations variable.
     *
     * @param int $id
     * @return \App\Models\Model|null
     */
    public function show($id): ?Model
    {
        $resource = $this->find($id);

        if (!$resource) {
            return null;
        }

        return $this->load_with_relations() ? $resource->load($this->relations) : $resource;
    }

    /**
     * Update the specified resource with the specified data.
     * Returns null if the resource was not found or the data is not valid.
     *
     * @param int $id
     * @param array $data
     * @return \App\Models\Model|null
     */
    public function update($id, $data = []): Model
    {
        $resource = $this->find($id, $this->model::getPrimaryKey());
        $data = $this->getPreparedUpdateData($data, $resource);

        if (!$resource) {
            return null;
        }

        if (count($data)) {
            $resource->update($data);
        }

        $resource = $this->show($resource->id);

        return $resource;
    }

    /**
     * Delete the resource with the specified id.
     *
     * @param int $id
     * @return bool
     */
    public function delete($id): bool
    {
        $resource = $this->find($id, $this->model::getPrimaryKey());

        return $resource && $resource->delete();
    }

    /**
     * Restore a deleted resource
     *
     * @param int $id Id of resource
     * @return mixed Resource
     */
    public function restore($id): Model
    {
        $resource = $this->find($id, 'id', true);

        if ($resource && $resource->restore()) {
            $resource->load($this->model()->getRelations());
            return $resource;
        }

        return null;
    }

    /**
     * Find a resource in the model using the specified
     * value and column for defining the constraints.
     *
     * @param mixed $value
     * @param string $column
     * @return \App\Models\Model|null
     */
    public function find($value, $column = null, $withTrashed = false): Model
    {
        $column = $column ?? $this->model::getPrimaryKey();

        if ($this->tableHasColumn($column)) {
            $query = $this->model();

            if ($withTrashed) {
                $query = $query->withTrashed();
            }

            return $query->where($column, $value)->first();
        }

        return null;
    }

    /**
     * Get a new instance of the model used by this service.
     *
     * @return \App\Models\Model|null
     */
    public function model(): Model
    {
        return $this->model ? new $this->model : null;
    }

    /**
     * Get the valid data fields from the specified data array.
     * Do this by checking if the field exists in the table.
     *
     * @param array $data
     * @return array
     */
    protected function getValidData($data = []): array
    {
        $validData = [];

        if (count($data)) {
            foreach ($data as $key => $value) {
                if ($this->tableHasColumn($key)) {
                    $validData[$key] = $value;
                }
            }
        }

        return $validData;
    }

    /**
     * Is the service set to load resources with their relations?
     *
     * @return bool
     */
    public function loadWithRelations(): bool
    {
        return $this->load_with_relations;
    }

    /**
     * Get the ranking to be used when ordering resources.
     * Either ascending or descending order.
     *      *
     * @return string
     */
    protected function getRanking($ranking): string
    {
        return strtolower($ranking) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Check if the resources should be paginated.
     *
     * @param bool|string $paginate
     * @return bool
     */
    protected function shouldPaginate($paginate = false): bool
    {
        return (is_string($paginate) && strtolower($paginate) === 'true') || $paginate === true;
    }

    /**
     * Get the per page value from the specified value.
     *
     * @param int|string $per_page
     * @return int
     */
    protected function getPerPage($per_page = 20): int
    {
        return is_int($per_page) ? $per_page : static::PER_PAGE;
    }

    /**
     * Check if the model has the specified column in its
     * list of columns (fields).
     *
     * @param string $field
     * @return bool
     */
    protected function tableHasColumn($column): bool
    {
        $table = $this->model()->getTable();

        return $column && Schema::hasColumn($table, $column);
    }

    /**
     * Get the final data that should be used in creating a new resource
     *
     * @param array $data The initial data from request
     */
    public function getPreparedSaveData($data)
    {
        return $this->getValidData($data);
    }

    /**
     * Get the final data that should be used in updating a resource
     *
     * @param array $data The initial data from request
     * @param array $resource The resource been updated
     */
    public function getPreparedUpdateData($data, $resource)
    {
        return $this->getValidData($data);
    }

    /**
     * Get base params for query purpose
     *
     * @param array $requestData Data from request
     * @return array $params Query params
     */
    protected function getQueryParams($requestData)
    {
        $params = [];
        $fields = $this->getValidQueryParams();

        // Set pagination options
        if (isset($requestData['paginate']) && ($requestData['paginate'] === true || $requestData['paginate'] === 'true')) {
            $params['paginate'] = $this->getPerPage(array_get($requestData, 'per_page'));
        }

        // Set ordering options
        if (isset($requestData['order_field']) && $requestData['order_field']) {
            $orderField = $requestData['order_field'];
            $ranking = (isset($requestData['ranking']) && $requestData['ranking']) ?
                $requestData['ranking'] : $this->ranking;

            $params['order'] = [$orderField => $ranking];
        }

        $params['where'] = [];

        // Set field-value pairs for use in an ANDed query for items
        foreach ($fields as $field) {
            if (isset($requestData[$field]) && $requestData[$field]) {
                $params['where'][$field] = $requestData[$field];
            }
        }

        // Instantiate extra filters option
        $params['filters'] = [];

        if (isset($requestData['view_by']) && $requestData['view_by']) {
            $params['view_by'] = $requestData['view_by'];
        }

        return $params;
    }

    /**
     * Get the keys whose values are expected to be extracted from query params
     *
     * @return array Array of fields to extract
     */
    public function getValidQueryParams()
    {
        return Schema::getColumnListing($this->model()->getTable());
    }

    /**
     * A filter for querying name with a search
     *
     * @param \Illuminate\Database\QueryBuilder $query Current query builder
     * @param $data Data from request
     * @return \Illuminate\Database\QueryBuilder $query Updated query
     */
    public function search($query, $keyword)
    {
        // Truncate contiguous spaces to only a single space for
        // explode to work desirably
        $keyword = preg_replace('/\s+/', ' ', trim($keyword));
        $keywordParts = explode(" ", $keyword);
        $fields = $this->model()->getSearchFields();

        $query = $query->where(function ($query) use ($fields, $keywordParts) {
            if (count($keywordParts)) {
                foreach ($fields as $field) {
                    $query = $query->orWhere(function ($query) use ($keywordParts, $field) {
                        // $query->where($field, 'LIKE', '%'.$keywordParts[0].'%');

                        foreach ($keywordParts as $part) {
                            $query = $query->where($field, 'LIKE', '%'.$part.'%');
                        }
                    });
                }
            }
        });

        return $query;
    }

    /**
     * Do further querying on the current query object
     * Will be overriden by service classes with more complicated filtering requirements
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $data Data for filtering query
     * @param \Illuminate\Database\Query\Builder
     */
    public function applyFilters($query, $data): \Illuminate\Database\Query\Builder
    {
        // Add search filter if keyword is present
        if (isset($data['keyword']) && $data['keyword']) {
            $query = $this->search($query, $data['keyword']);
        }

        return $query;
    }

    /**
     * Loads record(s) with relations
     *
     * @param array $relations
     * @return \App\Services\Service
     */
    public function withRelations($relations = []): Service
    {
        if ($relations) {
            $this->relations = $relations;
        }

        $this->load_with_relations = true;

        return $this;
    }

    /**
     * Load record(s) with no relations
     *
     * @return \App\Services\Service
     */
    public function withNoRelations(): Service
    {
        $this->load_with_relations = false;

        return $this;
    }

    /**
     * Get the current relations we are working with
     *
     * @return array
     */
    public function getRelations(): array
    {
        return $this->relations;
    }
}
