<?php

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CandidateFiles
{
    public const DISK = 'candidate-private';

    /** @return list<string> */
    public static function photoRules(): array
    {
        return ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png', 'max:2048',
            'dimensions:min_width=300,min_height=400,max_width=3000,max_height=4000,ratio=3/4'];
    }

    /** @return list<string> */
    public static function documentRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:10240'];
    }

    /** Validate actual bytes independently of browser or temporary-upload metadata. */
    public function mime(UploadedFile $file, string $field): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowed = $field === 'photo' ? ['image/jpeg', 'image/png'] : ['application/pdf', 'image/jpeg', 'image/png'];
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'application/pdf' => ['pdf']];
        if (! in_array($mime, $allowed, true) || ! in_array(strtolower($file->getClientOriginalExtension()), $extensions[$mime], true)) {
            throw ValidationException::withMessages([$field => __('The file content must match its PDF, JPEG or PNG extension.')]);
        }

        return $mime;
    }

    public function store(UploadedFile $file, string $directory, string $field): string
    {
        $mime = $this->mime($file, $field);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'pdf',
        };
        $path = $directory.'/'.Str::uuid().'.'.$extension;
        try {
            $stored = Storage::disk(self::DISK)->putFileAs($directory, $file, basename($path), ['visibility' => 'private']);
            if ($stored === false) {
                throw new RuntimeException('Private upload could not be stored.');
            }
        } catch (Throwable $exception) {
            $this->remove($path);
            report($exception);
            throw ValidationException::withMessages([$field => __('The file could not be stored. Please try again.')]);
        }

        return $path;
    }

    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::substr(trim($name), 0, 255) ?: 'document';
    }

    public static function safePath(?string $path): bool
    {
        return $path !== null && preg_match('#\A(?:candidate-photos|candidate-documents)/[A-Za-z0-9/_\-.]+\z#D', $path) === 1
            && ! str_contains($path, '..');
    }

    public function remove(?string $path): bool
    {
        if ($path === null) {
            return true;
        }
        try {
            if (! self::safePath($path) || ! Storage::disk(self::DISK)->delete($path)) {
                throw new RuntimeException('Private file cleanup failed.');
            }

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
