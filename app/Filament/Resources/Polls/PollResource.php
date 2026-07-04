<?php


declare(strict_types=1);

namespace App\Filament\Resources\Polls;

use BackedEnum;
use App\Models\Poll;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Polls\Pages\EditPoll;
use App\Filament\Resources\Polls\Pages\ViewPoll;
use App\Filament\Resources\Polls\Pages\ListPolls;
use App\Filament\Resources\Polls\Pages\CreatePoll;
use App\Filament\Resources\Polls\Schemas\PollForm;
use App\Filament\Resources\Polls\Tables\PollsTable;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\Polls\Schemas\PollInfolist;

class PollResource extends Resource
{
    protected static ?string $model = Poll::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PollForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PollInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PollsTable::configure($table);
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
            'index' => ListPolls::route('/'),
            'create' => CreatePoll::route('/create'),
            'view' => ViewPoll::route('/{record}'),
            'edit' => EditPoll::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
