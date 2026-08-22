<?php

declare(strict_types=1);

namespace App\Contracts;

interface StatsServiceContract
{
    public function getDailyUsersStats(): array;

    public function getGenderStats(): array;

    public function getAgeStats(): array;

    public function getEthnicityStats(): array;

    public function getCountryStats(): array;

    public function getHometownStats(): array;

    public function getReligionStats(): array;
}
