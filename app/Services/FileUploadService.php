<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Contracts\FileUploadServiceContract;

class FileUploadService implements FileUploadServiceContract
{
    public function upload(UploadedFile $file, string $directory, string $filename, string $disk = 's3'): string
    {
        return $file->storeAs($directory, $filename, $disk);
    }

    public function delete(string $path, string $disk = 's3'): bool
    {
        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        return false;
    }

    public function temporaryUrl(string $path, int $minutes = 60, string $disk = 's3'): string
    {
        return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($minutes));
    }

    public function url(string $path, string $disk = 's3'): string
    {
        return Storage::disk($disk)->url($path);
    }
}
