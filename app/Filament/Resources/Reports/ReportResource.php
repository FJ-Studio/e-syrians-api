<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports;

use BackedEnum;
use App\Models\Poll;
use App\Models\User;
use App\Models\Report;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Models\FeatureRequest;
use App\Enums\ReportStatusEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use App\Contracts\ReportServiceContract;
use Filament\Schemas\Components\Section;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\TextEntry;
use App\Filament\Resources\Polls\PollResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\FeatureRequests\FeatureRequestResource;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'reporter:id,uuid,name,surname',
            'reviewer:id,uuid,name,surname',
            'reportable',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('Report')->sortable(),
                TextColumn::make('reportable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('target')
                    ->state(fn (Report $record): string => static::targetTitle($record))
                    ->url(fn (Report $record): ?string => static::targetUrl($record))
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('reason')
                    ->state(fn (Report $record): string => $record->reason->label())
                    ->wrap(),
                TextColumn::make('reporter.name')->label('Reported by')->placeholder('Deleted account'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ReportStatusEnum $state): string => $state->label())
                    ->color(fn (ReportStatusEnum $state): string => $state->color()),
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed',
                ]),
                SelectFilter::make('reportable_type')->label('Type')->options([
                    User::class => 'User', Poll::class => 'Poll', FeatureRequest::class => 'Feature request',
                ]),
            ])
            ->recordActions([ViewAction::make(), ...static::reviewActions()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report')->schema([
                TextEntry::make('target')
                    ->state(fn (Report $record): string => static::targetTitle($record))
                    ->url(fn (Report $record): ?string => static::targetUrl($record)),
                TextEntry::make('reportable_type')->label('Type')->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextEntry::make('reason')->state(fn (Report $record): string => $record->reason->label()),
                TextEntry::make('reporter.name')->label('Reported by')->placeholder('Deleted account'),
                TextEntry::make('created_at')->label('Received')->dateTime(),
                TextEntry::make('details')->placeholder('No additional details')->columnSpanFull(),
            ])->columns(2),
            Section::make('Review')->schema([
                TextEntry::make('status')->state(fn (Report $record): string => $record->status->label())->badge(),
                TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('Not reviewed'),
                TextEntry::make('reviewed_at')->dateTime()->placeholder('Not reviewed'),
                TextEntry::make('review_notes')->placeholder('No review notes')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function reviewActions(): array
    {
        $actions = [];
        foreach ([ReportStatusEnum::Resolved, ReportStatusEnum::Dismissed] as $status) {
            $actions[] = Action::make($status->value)
                ->label($status === ReportStatusEnum::Resolved ? 'Resolve' : 'Dismiss')
                ->color($status->color())
                ->requiresConfirmation()
                ->modalDescription('Review the reported item using its link and take any necessary moderation action before closing this report. This action only records the review outcome.')
                ->schema([
                    Textarea::make('review_notes')->required()->minLength(3)->maxLength(2000)->rows(3),
                ])
                ->visible(fn (Report $record): bool => $record->status !== $status)
                ->authorize(fn (): bool => static::canViewAny())
                ->action(function (Report $record, array $data) use ($status): void {
                    resolve(ReportServiceContract::class)->review($record, auth()->user(), $status, $data['review_notes']);
                });
        }

        $actions[] = Action::make('reopen')
            ->label('Reopen')
            ->requiresConfirmation()
            ->visible(fn (Report $record): bool => $record->status !== ReportStatusEnum::Pending)
            ->authorize(fn (): bool => static::canViewAny())
            ->action(function (Report $record): void {
                resolve(ReportServiceContract::class)->review($record, auth()->user(), ReportStatusEnum::Pending, null);
            });

        return $actions;
    }

    public static function targetTitle(Report $report): string
    {
        $target = $report->reportable;

        return match (true) {
            $target instanceof User => trim($target->name.' '.$target->surname),
            $target instanceof Poll => $target->question,
            $target instanceof FeatureRequest => $target->title,
            default => 'Removed item #'.$report->reportable_id,
        };
    }

    public static function targetUrl(Report $report): ?string
    {
        $target = $report->reportable;
        $resource = match (true) {
            $target instanceof User => UserResource::class,
            $target instanceof Poll => PollResource::class,
            $target instanceof FeatureRequest => FeatureRequestResource::class,
            default => null,
        };

        return $resource === null ? null : $resource::getUrl('view', ['record' => $target]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'view' => ViewReport::route('/{record}'),
        ];
    }
}
