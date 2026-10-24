<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Product;

/**
 * The product form's image list: up to MAX images, kept in order (the first is the main image).
 * The form sends `image_order[]` tokens, "e{id}" for an image already saved and "n{i}" for the i-th
 * uploaded file in `images[]`. Without `image_order` (no JavaScript) every saved image is kept and
 * any uploaded files are appended.
 */
final class ProductImageService
{
    public const MAX = 5;

    /**
     * @param mixed      $order `image_order` from the form, or null when the field was not sent
     * @param array|null $files raw $_FILES['images']
     * @return array{plan: ?array, errors: array<string,string>} plan is null when nothing about the images changes
     */
    public static function plan(?int $productId, mixed $order, ?array $files): array
    {
        $files = UploadService::normalise($files);
        $existing = $productId ? Product::images($productId) : [];
        $ids = array_flip(array_map(fn($r) => (int) $r['id'], $existing));

        if ($order === null) {
            if ($files === []) {
                return ['plan' => null, 'errors' => []];
            }
            $tokens = [...array_map(fn($r) => 'e' . $r['id'], $existing), ...array_map(fn($i) => 'n' . $i, array_keys($files))];
        } else {
            $tokens = [];
            foreach (is_array($order) ? $order : [] as $t) {
                if (!is_string($t) || isset($tokens[$t])) {
                    continue;
                }
                if (preg_match('/^e(\d+)$/', $t, $m) && isset($ids[(int) $m[1]])) {
                    $tokens[$t] = $t;
                } elseif (preg_match('/^n(\d+)$/', $t, $m) && isset($files[(int) $m[1]])) {
                    $tokens[$t] = $t;
                }
            }
            $tokens = array_values($tokens);
        }

        $errors = [];
        if (count($tokens) > self::MAX) {
            $errors['images'] = 'A product can have at most ' . self::MAX . ' images. Remove ' . (count($tokens) - self::MAX) . ' to save.';
        } else {
            foreach ($tokens as $t) {
                if ($t[0] === 'n') {
                    try {
                        UploadService::validate($files[(int) substr($t, 1)]);
                    } catch (\InvalidArgumentException $e) {
                        $errors['images'] = $files[(int) substr($t, 1)]['name'] . ': ' . $e->getMessage();
                        break;
                    }
                }
            }
        }
        return ['plan' => ['tokens' => $tokens, 'files' => $files], 'errors' => $errors];
    }

    /**
     * Make the product's images match the plan. Call inside the product's transaction.
     *
     * @return array{unlink: string[], stored: string[]} unlink = files to delete once the transaction commits;
     *         stored = files written now, to delete if the transaction rolls back
     */
    public static function apply(int $productId, array $plan): array
    {
        $stored = [];
        $unlink = [];
        try {
            $keep = [];
            foreach ($plan['tokens'] as $t) {
                if ($t[0] === 'e') {
                    $keep[(int) substr($t, 1)] = true;
                }
            }
            foreach (Product::images($productId) as $img) {
                if (!isset($keep[(int) $img['id']])) {
                    Database::query('DELETE FROM product_images WHERE id = ?', [$img['id']]);
                    $unlink[] = $img['path'];
                }
            }
            foreach ($plan['tokens'] as $pos => $t) {
                $primary = $pos === 0 ? 1 : 0;
                if ($t[0] === 'e') {
                    Database::query('UPDATE product_images SET position = ?, is_primary = ? WHERE id = ? AND product_id = ?', [$pos, $primary, (int) substr($t, 1), $productId]);
                } else {
                    $path = UploadService::saveImage($plan['files'][(int) substr($t, 1)]);
                    $stored[] = $path;
                    Database::query('INSERT INTO product_images (product_id, path, is_primary, position) VALUES (?, ?, ?, ?)', [$productId, $path, $primary, $pos]);
                }
            }
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                UploadService::delete($path);
            }
            throw $e;
        }
        return ['unlink' => $unlink, 'stored' => $stored];
    }
}
