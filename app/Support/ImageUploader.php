<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exception\ValidationException;

/**
 * Product image upload (PRD-01).
 *
 * Three things are deliberately NOT trusted:
 *  - the client-supplied MIME type ($_FILES['type']), which is attacker-controlled;
 *  - the original filename, which may contain path segments or a double extension;
 *  - the extension, which says nothing about the actual bytes.
 *
 * The type is therefore determined by inspecting the file's own content, and the
 * stored name is generated randomly so uploaded files cannot be guessed or
 * enumerated (the upload directory also has autoindex and PHP execution off).
 */
final class ImageUploader
{
    /** @param list<string> $allowedMime */
    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes,
        private readonly array $allowedMime,
    ) {
    }

    /**
     * @param array<string,mixed> $file a single entry from $_FILES
     * @return string|null the stored public path, or null when no file was submitted
     * @throws ValidationException
     */
    public function store(array $file, string $field = 'image'): ?string
    {
        $error = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException([$field => 'That image is larger than the server allows.']);
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException([$field => 'The image could not be uploaded. Please try again.']);
        }

        $temporary = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            // Guards against a path being injected in place of a real upload.
            throw new ValidationException([$field => 'The upload was not valid.']);
        }

        $size = is_int($file['size'] ?? null) ? $file['size'] : 0;
        if ($size > $this->maxBytes) {
            throw new ValidationException([
                $field => sprintf('The image must be %d KB or smaller.', intdiv($this->maxBytes, 1024)),
            ]);
        }

        $mime = $this->detectMime($temporary);
        if (!in_array($mime, $this->allowedMime, true)) {
            throw new ValidationException([$field => 'The image must be a JPEG, PNG or WebP file.']);
        }

        // Confirms the bytes really are a decodable image, not just a file whose
        // first bytes imitate one.
        if (getimagesize($temporary) === false) {
            throw new ValidationException([$field => 'That file is not a readable image.']);
        }

        $name = bin2hex(random_bytes(16)) . $this->extensionFor($mime);

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new ValidationException([$field => 'The image could not be saved.']);
        }

        if (!move_uploaded_file($temporary, $this->directory . '/' . $name)) {
            throw new ValidationException([$field => 'The image could not be saved.']);
        }

        return '/uploads/' . $name;
    }

    public function delete(?string $publicPath): void
    {
        if ($publicPath === null || !str_starts_with($publicPath, '/uploads/')) {
            return;
        }

        $name = basename($publicPath);
        $full = $this->directory . '/' . $name;
        if (is_file($full)) {
            unlink($full);
        }
    }

    private function detectMime(string $path): string
    {
        $info = finfo_open(FILEINFO_MIME_TYPE);
        if ($info === false) {
            return '';
        }

        $mime = finfo_file($info, $path);
        finfo_close($info);

        return is_string($mime) ? $mime : '';
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => '.jpg',
        };
    }
}
