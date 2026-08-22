<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests;

use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\FeatureRequest;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\FeatureRequests\Pages\EditFeatureRequest;
use App\Filament\Resources\FeatureRequests\Pages\ViewFeatureRequest;
use App\Filament\Resources\FeatureRequests\Pages\ListFeatureRequests;
use App\Filament\Resources\FeatureRequests\Schemas\FeatureRequestForm;
use App\Filament\Resources\FeatureRequests\Tables\FeatureRequestsTable;
use App\Filament\Resources\FeatureRequests\Schemas\FeatureRequestInfolist;

/**
 * Admin surface for feature requests.
 *
 * Deliberately no `create` page — feature requests are submitted by end
 * users through the mobile / web app. The admin can moderate them
 * (advance stage, soft-delete with reason, restore) but never create
 * one from scratch.
 */
class FeatureRequestResource extends Resource
{
    protected static ?string $model = FeatureRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return 'Feature request';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Feature requests';
    }

    public static function form(Schema $schema): Schema
    {
        return FeatureRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FeatureRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeatureRequestsTable::configure($table);
    }

    /**
     * Eager-load the submitter + up/down aggregates so the list page
     * doesn't fire N+1 for user names or count queries per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('user:id,uuid,name,surname,avatar')
            ->withCount([
                'ups as ups_aggregate_count',
                'downs as downs_aggregate_count',
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeatureRequests::route('/'),
            'view' => ViewFeatureRequest::route('/{record}'),
            'edit' => EditFeatureRequest::route('/{record}/edit'),
        ];
    }

    /**
     * Allow route binding to resolve trashed rows so admins can view /
     * restore soft-deleted feature requests from the list.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
