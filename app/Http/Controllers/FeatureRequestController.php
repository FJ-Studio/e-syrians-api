<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Exceptions\FeatureRequestException;
use App\Http\Resources\FeatureRequestResource;
use App\Contracts\FeatureRequestServiceContract;
use App\Http\Requests\FeatureRequests\StoreFeatureRequest;
use App\Http\Requests\FeatureRequests\DestroyFeatureRequest;
use App\Http\Requests\FeatureRequests\StoreFeatureRequestVote;
use App\Http\Requests\FeatureRequests\UpdateFeatureRequestTimeline;

class FeatureRequestController extends Controller
{
    public function __construct(
        private readonly FeatureRequestServiceContract $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User|null $authUser */
        $authUser = auth('sanctum')->user();
        $userId = $authUser?->id;

        $features = $this->service->getPaginatedFeatureRequests(
            (string) $request->input('sort', 'newest'),
            $request->input('status'),
            $userId,
        );

        return ApiService::success([
            'feature_requests' => FeatureRequestResource::collection($features->items()),
            'current_page' => $features->currentPage(),
            'last_page' => $features->lastPage(),
            'per_page' => $features->perPage(),
            'total' => $features->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        /** @var \App\Models\User|null $authUser */
        $authUser = auth('sanctum')->user();
        $userId = $authUser?->id;

        $feature = $this->service->getFeatureRequestById($id, $userId);

        return ApiService::success(new FeatureRequestResource($feature));
    }

    public function store(StoreFeatureRequest $request): JsonResponse
    {
        try {
            $feature = $this->service->createFeatureRequest(
                $request->validated(),
                $request->user()->id,
            );

            return ApiService::success(new FeatureRequestResource($feature->load('user')));
        } catch (Throwable $e) {
            Log::error('Feature request creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return ApiService::error(500);
        }
    }

    /**
     * Toggle the current user's vote on a feature request.
     * Same-direction clicks remove the vote; opposite-direction clicks switch it.
     */
    public function vote(StoreFeatureRequestVote $request): JsonResponse
    {
        try {
            $outcome = $this->service->vote(
                (int) $request->input('feature_request_id'),
                (string) $request->input('vote'),
                $request->user()->id,
            );

            return ApiService::success(['outcome' => $outcome]);
        } catch (FeatureRequestException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Remove the current user's vote on a feature request. Idempotent.
     */
    public function unvote(Request $request, int $id): JsonResponse
    {
        $this->service->unvote($id, $request->user()->id);

        return ApiService::success([]);
    }

    /**
     * Admin: set or clear timeline stamps (coded_at / tested_at / deployed_at).
     * Accepts a partial payload; passing `null` for a key clears that stamp.
     */
    public function timeline(UpdateFeatureRequestTimeline $request, int $id): JsonResponse
    {
        try {
            $feature = $this->service->setTimeline($id, $request->validated());

            return ApiService::success(new FeatureRequestResource($feature->load('user')));
        } catch (FeatureRequestException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Admin: soft-delete a feature request with a required deletion reason.
     */
    public function destroy(DestroyFeatureRequest $request, int $id): JsonResponse
    {
        try {
            $this->service->softDelete($id, (string) $request->validated('deletion_reason'));

            return ApiService::success([]);
        } catch (FeatureRequestException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Admin: restore a previously soft-deleted feature request.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        try {
            $this->service->restore($id);

            return ApiService::success([]);
        } catch (FeatureRequestException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }
}
