<?php


declare(strict_types=1);

namespace App\Filament\Resources\UserVerifications;

use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Models\UserVerification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\UserVerifications\Pages\EditUserVerification;
use App\Filament\Resources\UserVerifications\Pages\ViewUserVerification;
use App\Filament\Resources\UserVerifications\Pages\ListUserVerifications;
use App\Filament\Resources\UserVerifications\Pages\CreateUserVerification;
use App\Filament\Resources\UserVerifications\Schemas\UserVerificationForm;
use App\Filament\Resources\UserVerifications\Tables\UserVerificationsTable;
use App\Filament\Resources\UserVerifications\Schemas\UserVerificationInfolist;

class UserVerificationResource extends Resource
{
    protected static ?string $model = UserVerification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return UserVerificationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserVerificationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UserVerificationsTable::configure($table);
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
            'index' => ListUserVerifications::route('/'),
            'create' => CreateUserVerification::route('/create'),
            'view' => ViewUserVerification::route('/{record}'),
            'edit' => EditUserVerification::route('/{record}/edit'),
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
