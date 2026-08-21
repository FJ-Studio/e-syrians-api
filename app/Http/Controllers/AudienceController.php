<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use App\Services\ApiService;
use Illuminate\Http\Request;
use App\Exceptions\AudienceException;
use App\Http\Resources\AudienceResource;
use App\Contracts\AudienceServiceContract;
use App\Http\Requests\Audiences\StoreAudienceRequest;
use App\Http\Requests\Audiences\UpdateAudienceRequest;
use App\Http\Requests\Audiences\AddAudienceEntriesRequest;

class AudienceController extends Controller
{
    public function __construct(private readonly AudienceServiceContract $audiences)
    {
    }

    public function index(Request $request): mixed
    {
        // Clamp per_page to the same range the rest of the API uses
        // (min 1, max 100). Without this a caller could ask for
        // per_page=100000 and force the DB / entry-count aggregate
        // to load a huge working set, or per_page=-1 which paginate()
        // would happily accept and return an empty page for.
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $audiences = $this->audiences->list(
            userId: (int) $request->user()->id,
            perPage: $perPage,
        );

        return ApiService::success([
            'audiences' => AudienceResource::collection($audiences->items()),
            'pagination' => [
                'current_page' => $audiences->currentPage(),
                'last_page' => $audiences->lastPage(),
                'per_page' => $audiences->perPage(),
                'total' => $audiences->total(),
            ],
        ]);
    }

    public function store(StoreAudienceRequest $request): mixed
    {
        try {
            $audience = $this->audiences->create(
                userId: (int) $request->user()->id,
                attributes: [
                    'name' => (string) $request->input('name'),
                    'description' => $request->input('description'),
                ],
                rawEntries: (array) $request->input('entries', []),
            );

            return ApiService::success(new AudienceResource($audience), 'audience_created', 201);
        } catch (AudienceException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        } catch (Throwable) {
            return ApiService::error(500);
        }
    }

    public function show(Request $request, string $uuid): mixed
    {
        try {
            $audience = $this->audiences->show($uuid, (int) $request->user()->id);

            return ApiService::success(new AudienceResource($audience));
        } catch (AudienceException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }

    public function update(UpdateAudienceRequest $request, string $uuid): mixed
    {
        try {
            $audience = $this->audiences->update(
                uuid: $uuid,
                userId: (int) $request->user()->id,
                attributes: $request->only(['name', 'description']),
            );

            return ApiService::success(new AudienceResource($audience), 'audience_updated');
        } catch (AudienceException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }

    public function addEntries(AddAudienceEntriesRequest $request, string $uuid): mixed
    {
        try {
            $audience = $this->audiences->addEntries(
                uuid: $uuid,
                userId: (int) $request->user()->id,
                rawEntries: (array) $request->input('entries', []),
            );

            return ApiService::success(new AudienceResource($audience), 'audience_entries_added');
        } catch (AudienceException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }

    public function removeEntry(Request $request, string $uuid, int $entry): mixed
    {
        try {
            $this->audiences->removeEntry(
                uuid: $uuid,
                userId: (int) $request->user()->id,
                entryId: $entry,
            );

            return ApiService::success(null, 'audience_entry_removed');
        } catch (AudienceException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }

    public function refreshResolution(Request $request, string $uuid): mixed
    {
        try {
            $audience = $this->audiences->refreshResolution($uuid, (int) $request->user()->id);

            return ApiService::success(new AudienceResource($audience), 'audience_resolution_refreshed');
        } catch (AudienceException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }

    public function destroy(Request $request, string $uuid): mixed
    {
        try {
            $this->audiences->softDelete($uuid, (int) $request->user()->id);

            return ApiService::success(null, 'audience_deleted');
        } catch (AudienceException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }
}
