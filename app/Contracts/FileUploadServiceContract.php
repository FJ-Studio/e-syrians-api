<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface FileUploadServiceContract
{
    /**
     * Upload a file to the specified disk/path
     *
     * @return string The stored file path
     */
    public function upload(UploadedFile $file, string $directory, string $filename, string $disk = 's3'): string;

    /**
     * Delete a file from the specified disk
     */
    public function delete(string $path, string $disk = 's3'): bool;

    /**
     * Get a temporary URL for a file
     */
    public function temporaryUrl(string $path, int $minutes = 60, string $disk = 's3'): string;

    /**
     * Get the full URL for a file
     */
    public function url(string $path, string $disk = 's3'): string;
}
