<?php


declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\ForceDeleteBulkAction;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('middle_name')
                    ->searchable(),
                TextColumn::make('surname')
                    ->searchable(),
                TextColumn::make('national_id')
                    ->searchable(),
                TextColumn::make('national_id_hashed')
                    ->searchable(),
                TextColumn::make('gender')
                    ->badge(),
                TextColumn::make('birth_date')
                    ->searchable(),
                TextColumn::make('hometown')
                    ->badge(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('email_hashed')
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('phone_hashed')
                    ->searchable(),
                TextColumn::make('phone_verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('avatar')
                    ->searchable(),
                TextColumn::make('language')
                    ->badge(),
                TextColumn::make('google_id')
                    ->searchable(),
                TextColumn::make('apple_id')
                    ->searchable(),
                TextColumn::make('country')
                    ->badge(),
                TextColumn::make('province')
                    ->searchable(),
                IconColumn::make('shelter')
                    ->boolean(),
                TextColumn::make('education_level')
                    ->badge(),
                TextColumn::make('marital_status')
                    ->badge(),
                TextColumn::make('source_of_income')
                    ->badge(),
                TextColumn::make('estimated_monthly_income')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('number_of_dependents')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('health_status')
                    ->badge(),
                IconColumn::make('health_insurance')
                    ->boolean(),
                IconColumn::make('easy_access_to_healthcare_services')
                    ->boolean(),
                TextColumn::make('ethnicity')
                    ->badge(),
                TextColumn::make('religious_affiliation')
                    ->badge(),
                TextColumn::make('verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('verification_reason')
                    ->badge(),
                TextColumn::make('marked_as_fake_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('record_place')
                    ->searchable(),
                TextColumn::make('record_id')
                    ->searchable(),
                TextColumn::make('facebook_link')
                    ->searchable(),
                TextColumn::make('twitter_link')
                    ->searchable(),
                TextColumn::make('linkedin_link')
                    ->searchable(),
                TextColumn::make('github_link')
                    ->searchable(),
                TextColumn::make('instagram_link')
                    ->searchable(),
                TextColumn::make('snapchat_link')
                    ->searchable(),
                TextColumn::make('tiktok_link')
                    ->searchable(),
                TextColumn::make('youtube_link')
                    ->searchable(),
                TextColumn::make('pinterest_link')
                    ->searchable(),
                TextColumn::make('twitch_link')
                    ->searchable(),
                TextColumn::make('website')
                    ->searchable(),
                IconColumn::make('received_verification_email')
                    ->boolean(),
                IconColumn::make('account_verified_email')
                    ->boolean(),
                TextColumn::make('two_factor_secret')
                    ->searchable(),
                IconColumn::make('two_factor_enabled')
                    ->boolean(),
                TextColumn::make('two_factor_confirmed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('recovery_codes_total')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
