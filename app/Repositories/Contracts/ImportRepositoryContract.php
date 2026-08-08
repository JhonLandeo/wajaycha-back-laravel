<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ImportRepositoryContract
{
    /**
     * One page of the caller's imports, newest first.
     *
     * `$userId` is an authorization boundary, not a filter of convenience — this is a
     * listing, so an unscoped query returns every user's statements at once.
     *
     * @return LengthAwarePaginator<int, Import>
     */
    public function paginateForUser(int $userId, int $perPage, int $page): LengthAwarePaginator;

    /**
     * An Import belonging to $userId, or null.
     *
     * Route-model binding is deliberately not used for this: it resolves by primary key
     * alone, which exposed every other user's import — including the raw statement file
     * behind `download()`.
     */
    public function findOwned(int $id, int $userId): ?Import;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Import $import, array $data): bool;

    public function delete(Import $import): bool;

    /**
     * The code a financial entity is filed under, used to place the uploaded file.
     */
    public function financialEntityCode(int $financialEntityId): ?string;

    /**
     * Reference data the upload form is built from.
     *
     * Deliberately unscoped: these are catalogues, the same for every user. Saying so
     * here is the point — an unscoped read in a repository whose other methods all take
     * an owner should explain itself.
     *
     * @return Collection<int, FinancialEntity>
     */
    public function financialEntities(): Collection;

    /**
     * @return Collection<int, PaymentService>
     */
    public function paymentServices(): Collection;
}
