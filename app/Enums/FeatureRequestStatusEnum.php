<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\FeatureRequest;

/**
 * Feature-request stage. Derived from the timeline stamps — the
 * highest set of (`deployed_at`, `tested_at`, `coded_at`) wins; if
 * none are set, the request is still at `Idea`.
 *
 * The enum values (`idea`, `in_development`, `in_testing`, `shipped`)
 * are the public API contract — mobile and web both consume them, so
 * don't rename without a coordinated release.
 *
 * `stageColumn()` mirrors the frontend `StageKey` union
 * (`created_at | coded_at | tested_at | deployed_at`) so the enum can
 * be the single source of truth on both sides.
 */
enum FeatureRequestStatusEnum: string
{
    case Idea = 'idea';
    case InDevelopment = 'in_development';
    case InTesting = 'in_testing';
    case Shipped = 'shipped';

    /**
     * The timeline column whose presence marks this stage. `Idea`
     * maps to `created_at` because "no stage stamped yet" == "still
     * at the moment the row was created".
     */
    public function stageColumn(): string
    {
        return match ($this) {
            self::Idea => 'created_at',
            self::InDevelopment => 'coded_at',
            self::InTesting => 'tested_at',
            self::Shipped => 'deployed_at',
        };
    }

    /**
     * Derive the current stage from a FeatureRequest's timeline stamps.
     */
    public static function fromFeatureRequest(FeatureRequest $feature): self
    {
        if ($feature->deployed_at !== null) {
            return self::Shipped;
        }
        if ($feature->tested_at !== null) {
            return self::InTesting;
        }
        if ($feature->coded_at !== null) {
            return self::InDevelopment;
        }

        return self::Idea;
    }

    public function label(): string
    {
        return match ($this) {
            self::Idea => 'Idea',
            self::InDevelopment => 'In development',
            self::InTesting => 'In testing',
            self::Shipped => 'Shipped',
        };
    }

    /**
     * Filament badge color. Grouped so the enum owns the visual
     * mapping — one place to touch when we add a new stage.
     */
    public function color(): string
    {
        return match ($this) {
            self::Idea => 'gray',
            self::InDevelopment => 'warning',
            self::InTesting => 'info',
            self::Shipped => 'success',
        };
    }
}
