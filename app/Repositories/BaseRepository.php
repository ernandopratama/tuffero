<?php

namespace App\Repositories;

use App\Contracts\Repositories\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;

    /**
     * @var array<string>
     */
    protected array $with = [];

    public function __construct()
    {
        $this->model = $this->resolveModel();
    }

    /**
     * Specify Model class name.
     */
    abstract protected function getModelClass(): string;

    protected function resolveModel(): Model
    {
        return app($this->getModelClass());
    }

    protected function newQuery(): Builder
    {
        $query = $this->model->newQuery();

        if (! empty($this->with)) {
            $query->with($this->with);
        }

        return $query;
    }

    public function with(array|string $relations): static
    {
        $this->with = is_string($relations) ? func_get_args() : $relations;

        return $this;
    }

    public function all(array $columns = ['*'], array $relations = []): Collection
    {
        $query = $this->newQuery();

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->get($columns);
    }

    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator
    {
        $query = $this->newQuery();

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->paginate($perPage, $columns);
    }

    public function find(string|int $id, array $columns = ['*'], array $relations = []): ?Model
    {
        $query = $this->newQuery();

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id, $columns);
    }

    public function findOrFail(string|int $id, array $columns = ['*'], array $relations = []): Model
    {
        $query = $this->newQuery();

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->findOrFail($id, $columns);
    }

    public function create(array $data): Model
    {
        $model = $this->model->create($data);

        if (! empty($this->with)) {
            $model->load($this->with);
        }

        return $model;
    }

    public function update(Model|string|int $model, array $data): Model
    {
        if (! $model instanceof Model) {
            $model = $this->findOrFail($model);
        }

        $model->update($data);

        if (! empty($this->with)) {
            $model->load($this->with);
        }

        return $model;
    }

    public function delete(Model|string|int $model): bool
    {
        if (! $model instanceof Model) {
            $model = $this->findOrFail($model);
        }

        return (bool) $model->delete();
    }
}
