<?php
namespace App\Enums;
enum ReligiousAffiliation:string
{
    case Sunni = 'sunni';
    case Shia = 'shia';
    case Alawites = 'alawites';
    case Ismailis = 'ismailis';
    case Druze = 'druze';
    case GreekOrthodox = 'greek-orthodox';
    case GreekCatholic = 'greek-catholic';
    case Protestant = 'protestant';
    case SyriacOrthodox = 'syriac-orthodox';
    case SyriacCatholic = 'syriac-catholic';
    case ArmenianOrthodox = 'armenian-orthodox';
    case ArmenianCatholic = 'armenian-catholic';
    case AssyrianChurchOfTheEast = 'assyrian-church-of-the-east';
    case Maronites = 'maronites';
    case Jews = 'jews';
    case Yazidis = 'yazidis';
    case NonReligious = 'non-religious';
}
