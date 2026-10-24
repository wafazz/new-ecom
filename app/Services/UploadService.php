<?php
declare(strict_types=1);

namespace App\Services;

final class UploadService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Re-shape PHP's $_FILES['x'] (single or multiple) into a flat list of file arrays. */
    public static function normalise(?array $files): array
    {
        if ($files === null) {
            return [];
        }
        if (!is_array($files['name'])) {
            return $files['error'] === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }
        $out = [];
        foreach (array_keys($files['name']) as $i) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        }
        return $out;
    }

    /**
     * Check one uploaded image without storing it. The extension comes from the detected MIME
     * type, never from the client's filename.
     *
     * @return string the extension to store it under (jpg|png|webp)
     * @throws \InvalidArgumentException with a user-safe message
     */
    public static function validate(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'Image is too large (max 2 MB).' : 'Upload failed. Please try again.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image is too large (max 2 MB).');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Upload failed. Please try again.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::TYPES[$mime]) || @getimagesize($file['tmp_name']) === false) {
            throw new \InvalidArgumentException('Only JPG, PNG or WebP images are allowed.');
        }
        return self::TYPES[$mime];
    }

    /**
     * Validate and store one image under public/uploads/{dir}.
     *
     * @return string path relative to public/, e.g. "uploads/products/ab12.jpg"
     * @throws \InvalidArgumentException with a user-safe message
     */
    public static function saveImage(array $file, string $dir = 'products'): string
    {
        $ext = self::validate($file);
        $folder = BASE_PATH . '/public/uploads/' . $dir;
        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new \RuntimeException('Cannot create upload folder.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $name)) {
            throw new \RuntimeException('Cannot store upload.');
        }
        return "uploads/{$dir}/{$name}";
    }

    public static function delete(string $relative): void
    {
        // Only ever delete inside public/uploads, whatever the DB says.
        if (!preg_match('#^uploads/[a-z]+/[a-f0-9]{32}\.(jpg|png|webp)$#', $relative)) {
            return;
        }
        $file = BASE_PATH . '/public/' . $relative;
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
