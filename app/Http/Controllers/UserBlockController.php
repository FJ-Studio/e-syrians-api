<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserBlock;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->whereIn('id', UserBlock::query()->select('blocked_id')->where('blocker_id', $request->user()->id))
            ->orderBy('id')
            ->get(['uuid', 'name', 'surname']);

        return ApiService::success($users->map(static fn (User $user): array => [
            'uuid' => $user->uuid,
            'name' => trim($user->name.' '.$user->surname),
        ])->all());
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        $actor = $request->user();
        $target = User::query()->where('uuid', $uuid)->firstOrFail();
        if ($actor->is($target)) {
            return ApiService::error(422, 'cannot_block_yourself');
        }

        UserBlock::firstOrCreate(['blocker_id' => $actor->id, 'blocked_id' => $target->id]);

        return ApiService::success([]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        UserBlock::query()->where('blocker_id', $request->user()->id)
            ->whereIn('blocked_id', User::withTrashed()->select('id')->where('uuid', $uuid))
            ->delete();

        return ApiService::success([]);
    }
}
