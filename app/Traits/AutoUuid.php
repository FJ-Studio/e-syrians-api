<?php
namespace App\Traits;
use Illuminate\Support\Str;
trait AutoUuid
{
    protected static function bootAutoUuid() {
        static::creating(function($model) {
            $model->uuid = strtolower(Str::uuid());
        });
    }

    public function getKeyType() {
        return 'string';
    }
}
