<?php

declare(strict_types=1);

namespace App\Enums;

enum HometownEnum: string
{
    case Aleppo = 'aleppo';
    case Suwayda = 'suwayda';
    case Damascus = 'damascus';
    case Daraa = 'daraa';
    case DeirEzzor = 'deir-ezzor';
    case Hama = 'hama';
    case Hasakah = 'hasakah';
    case Homs = 'homs';
    case Idlib = 'idlib';
    case Latakia = 'latakia';
    case Quneitra = 'quneitra';
    case Raqqa = 'raqqa';
    case RifDimashq = 'rif-dimashq';
    case Tartus = 'tartus';
}
