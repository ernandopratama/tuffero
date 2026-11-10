<?php

namespace App\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    /**
     * Retrieve all records with optional eager loaded relationships.
     */
    public function all(array $columns = ['*'], array $relations = []): Collection;

    /**
     * Retrieve paginated records with optional eager loaded relationships.
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator;

    /**
     * Find a record by ID with optional eager loaded relationships.
     */
    public function find(string|int $id, array $columns = ['*'], array $relations = []): ?Model;

    /**
     * Find a record by ID or throw ModelNotFoundException.
     */
    public function findOrFail(string|int $id, array $columns = ['*'], array $relations = []): Model;

    /**
     * Create a new record in the database.
     */
    public function create(array $data): Model;

    /**
     * Update an existing record in the database.
     */
    public function update(Model|string|int $model, array $data): Model;

    /**
     * Delete a record from the database.
     */
    public function delete(Model|string|int $model): bool;

    /**
     * Set relationships to be eager loaded on the next query.
     */
    public function with(array|string $relations): static;
}
