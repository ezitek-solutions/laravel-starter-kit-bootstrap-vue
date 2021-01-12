<?php

namespace App\Services;

use Schema;
use Carbon\Carbon;

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
    protected $showWithRelations = true;


    /**
     * Default pagination to use for item listings
     *
     * @var bool
     */
    protected $pagination = 20;


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
        if ($this->showWithRelations) {
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

        if ($field = $this->model()->name_field) {
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

        if (isset($params['paginate'])) {
            $results = $query->paginate($params['paginate']);
        } else {
            $results = $query->get();
        }

        return $results;
    }


    /**
     * Tells if a model has a particular relationship
     *
     * @param string $relationship
     * @return boolean
     */
    public function hasRelationship($relationship)
    {
        return method_exists($this->model(), $relationship);
    }

    /**
     * Store a new resource with the provided data.
     *
     * @param array $data
     * @return \App\Models\Model|null
     */
    public function store($data = [])
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
     * depending on the value of the showWithRelations variable.
     *
     * @param int $id
     * @return \App\Models\Model|null
     */
    public function show($id)
    {
        $resource = $this->find($id);

        if (!$resource) {
            return null;
        }

        return $this->showWithRelations() ? $resource->load($this->relations) : $resource;
    }

    /**
     * Update the specified resource with the specified data.
     * Returns null if the resource was not found or the data is not valid.
     *
     * @param int $id
     * @param array $data
     * @return \App\Models\Model|null
     */
    public function update($id, $data = [])
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
    public function delete($id)
    {
        $resource = $this->find($id, $this->model::getPrimaryKey());

        if ($resource && $resource->delete()) {
            return true;
        }

        return false;
    }

    /**
     * Restore a deleted resource
     *
     * @param int $id Id of resource
     * @return mixed Resource
     */
    public function restore($id)
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
    public function find($value, $column = null, $withTrashed = false)
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
    public function model()
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
    protected function getValidData($data = [])
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
    public function showWithRelations()
    {
        return $this->showWithRelations;
    }

    /**
     * Get the ranking to be used when ordering resources.
     * Either ascending or descending order.
     *      *
     * @return string
     */
    protected function getRanking($ranking)
    {
        return strtolower($ranking) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Check if the resources should be paginated.
     *
     * @param bool|string $paginate
     * @return bool
     */
    protected function shouldPaginate($paginate = false)
    {
        return (is_string($paginate) && strtolower($paginate) === 'false') || !$paginate
                ? false : true;
    }

    /**
     * Get the per page value from the specified value.
     *
     * @param int|string $per_page
     * @return int
     */
    protected function getPerPage($per_page = 0)
    {
        $per_page = intval($per_page);

        return is_int($per_page) ? $per_page : 20;
    }

    /**
     * Check if the model has the specified column in its
     * list of columns (fields).
     *
     * @param string $field
     * @return bool
     */
    protected function tableHasColumn($column)
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
            $pagination = (isset($requestData['per_page']) && $requestData['per_page']) ?
                $requestData['per_page'] : $this->pagination;
            $params['paginate'] = $pagination;
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
     * A filter for querying name with a search
     *
     * @param \Illuminate\Database\QueryBuilder $query Current query builder
     * @param $data Data from request
     * @return \Illuminate\Database\QueryBuilder $query Updated query
     */
    public function searchColumn($query, $keyword, $column)
    {
        $keyword = preg_replace('/\s+/', ' ', trim($keyword));
        $keywordParts = explode(" ", $keyword);

        $query = $query->where(function ($query) use ($column, $keywordParts) {
            if (count($keywordParts)) {
                $query = $query->orWhere(function ($query) use ($keywordParts, $column) {
                    foreach ($keywordParts as $part) {
                        $query = $query->orWhere($column, 'LIKE', '%'.$part.'%');
                    }
                });
            }
        });

        return $query;
    }

    /**
     * Filter resources by those created within given date range
     *
     * @param \Illuminate\Database\Query\Builder $query The current built query
     * @param string $start Start date to query from
     * @param string $end End date to query from
     * @return \Illuminate\Database\Query\Builder $query The updated query
     */
    public function filterByDate($query, $start, $end)
    {
        if ($start) {
            $start = Carbon::parse($start)->toDateString() . ' 00:00:00';
        } else {
            $start = null;
        }

        if ($end) {
            $end = Carbon::parse($end)->toDateString() . ' 23:59:00';
        } else {
            $end = null;
        }

        if ($start) {
            $query = $query->where('created_at', '>=', $start);
        }

        if ($end) {
            $query = $query->where('created_at', '<=', $end);
        }

        return $query;
    }

    /**
     * Do further querying on the current query object
     * Will be overriden by service classes with more complicated filtering requirements
     *
     * @param \Illuminate\Database\Builder $query
     * @param array $data Data for filtering query
     * @param \Illuminate\Database\Builder
     */
    public function applyFilters($query, $data)
    {
        // Add search filter if keyword is present
        if (isset($data['keyword']) && $data['keyword']) {
            $query = $this->search($query, $data['keyword']);
        }

        return $query;
    }

    /**
     * Handle upload of executive photos
     *
     * @param App\Models\Model Resource whose photo we are saving
     * @param array $data Data for adding a new executive
     * @param string $folder Folder to save image in
     */
    public function handlePhotoUpload($resource, $data, $folder)
    {
        $destination = "";

        if (isset($data['photo']) && is_file($data['photo'])) {
            $destination = $this->uploadImage($resource, $data['photo'], $folder);

            if ($destination) {
                $resource->photo = $destination;
                $resource->save();
            }
        }
    }

    /**
     * Upload executive's photo
     *
     * @param App\Models\Model $resource Resource whose photo we are saving
     * @param object $uploadedFile Uploaded file object
     * @param string $folder Folder to save image in
     */
    public function uploadImage($resource, $uploadedFile, $folder)
    {
        $file = new FileUploadUtility($uploadedFile);

        if ($resource->photo) {
            @unlink(storage_path('app/'. $resource->getOriginal('photo')));
        }

        $name = $folder . "_" . $resource->id . time();
        $destination = $file->move($folder, $name, 'jpg');

        return $destination;
    }

    /**
     * Can the resource be deleted.
     *
     * @param int $id
     * @return bool
     */
    public function canDelete($id)
    {
        $resource = $this->find($id);

        if (!$resource) {
            return null;
        }

        return $resource->canDelete();
    }


    /**
     * Enables the service to fetch data with relations
     *
     * @param array $relations
     * @return \App\Modules\Generic\Service\AbstractService
     */
    public function enableWithRelationships($relations = [])
    {
        if (count($relations)) {
            $this->relations = $relations;
        }

        $this->showWithRelations = true;

        return $this;
    }


    /**
     * Disables service to fetch resource with relations and resets the relations to model's relations
     *
     * @return void
     */
    public function disableWithRelationships()
    {
        $this->relations = $this->model() ? $this->model()->getRelations() : [];
        $this->showWithRelations = false;

        return $this;
    }

    /**
     * Set relations when
     *
     * @param array $relations Relations that can be loaded with model
     */
    public function setRelations($relations)
    {
        $this->relations = $relations;
    }

    /**
     * Get the current relations we are working with
     *
     * @return array
     */
    public function getRelations()
    {
        return $this->relations;
    }

    /**
     * A filter for querying name with a keyword on relationships
     *
     * @param \Illuminate\Database\QueryBuilder $query
     * @param string $keyword
     * @param string $relationship
     * @param array $fields
     * @return \Illuminate\Database\QueryBuilder
     */
    public function otherSearchOnRelationship($query, $keyword, $relationship, $fields = [])
    {
        $keyword = preg_replace('/\s+/', ' ', trim($keyword));
        $keywordParts = explode(" ", $keyword);

        if (count($keywordParts)) {
            $query->orWhereHas($relationship, function ($query) use ($fields, $keywordParts) {
                $query = $query->where(function ($query) use ($fields, $keywordParts) {
                    foreach ($fields as $field) {
                        $query = $query->orWhere(function ($query) use ($keywordParts, $field) {
                            foreach ($keywordParts as $part) {
                                $query = $query->where($field, 'LIKE', '%'.$part.'%');
                            }
                        });
                    }
                });
            });
        }

        return $query;
    }
}
