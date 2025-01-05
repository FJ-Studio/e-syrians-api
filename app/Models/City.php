<?php

namespace App\Models;

use App\Traits\AutoUuid;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;
use Illuminate\Database\Eloquent\SoftDeletes;
class City extends Model
{

    use HasTranslations ,SoftDeletes,AutoUuid;


    public $translatable = ['name'];

    protected $fillable = [
        'name',
        'parent_id',
    ];
    protected $casts = [
        'name' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(City::class, 'parent_id');
    }
    public function children()
    {
        return $this->hasMany(City::class, 'parent_id');
    }
}
