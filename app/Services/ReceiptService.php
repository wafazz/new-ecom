<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Proof of a bank transfer: bank name, date and time paid, and the receipt file.
 * Files live in storage/receipts (outside the web root, random names) and are only ever sent through
 * an authorised route; the original file name is kept for display only and never used on disk.
 */
final class ReceiptService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PER_ORDER = 5;
    public const WINDOW_DAYS = 30;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public static function dir(): string
    {
        return BASE_PATH . '/storage/receipts';
    }

    // ---- validation ------------------------------------------------------------------------------------------

    /**
     * Check what the customer typed and the file they attached, before anything is stored.
     * $post keys: proof_bank, proof_paid_at ("Y-m-d\TH:i" from a datetime-local input). $strict=false skips the
     * "really uploaded via HTTP" check (tests only).
     *
     * @return array{errors: array<string,string>, clean: ?array{bank_name:string, paid_at:string}, info: ?array{ext:string, mime:string}}
     */
    public static function prepare(array $post, ?array $file, bool $strict = true, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $errors = [];
        $bank = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', is_scalar($post['proof_bank'] ?? null) ? (string) $post['proof_bank'] : '') ?? '');
        if ($bank === '') {
            $errors['proof_bank'] = 'Choose the bank you paid from.';
        } elseif (mb_strlen($bank) > 80) {
            $errors['proof_bank'] = 'That bank name is too long (80 characters max).';
        }

        $raw = trim(is_scalar($post['proof_paid_at'] ?? null) ? (string) $post['proof_paid_at'] : '');
        $paid = null;
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            if ($d && $d->format($fmt) === $raw) {
                $paid = $d;
                break;
            }
        }
        if ($raw === '') {
            $errors['proof_paid_at'] = 'Enter the date and time you paid.';
        } elseif ($paid === null) {
            $errors['proof_paid_at'] = 'That date and time is not valid.';
        } elseif ($paid > $now->modify('+10 minutes')) {
            $errors['proof_paid_at'] = 'The payment time cannot be in the future.';
        } elseif ($paid < $now->modify('-' . self::WINDOW_DAYS . ' days')) {
            $errors['proof_paid_at'] = 'That is more than ' . self::WINDOW_DAYS . ' days ago. Please check the date.';
        }

        $info = null;
        try {
            $info = self::checkFile($file, $strict);
        } catch (\InvalidArgumentException $e) {
            $errors['receipt'] = $e->getMessage();
        }
        return [
            'errors' => $errors,
            'clean' => $errors === [] ? ['bank_name' => $bank, 'paid_at' => $paid->format('Y-m-d H:i:00')] : null,
            'info' => $errors === [] ? $info : null,
        ];
    }

    /** @return array{ext:string, mime:string} @throws \InvalidArgumentException with a customer-safe message */
    private static function checkFile(?array $file, bool $strict): array
    {
        if ($file === null || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('Please attach your payment receipt (a photo, screenshot or PDF).');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException('That file is too large (max 5 MB).');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || ($strict && !is_uploaded_file((string) $file['tmp_name']))) {
            throw new \InvalidArgumentException('The upload did not finish. Please attach the receipt again.');
        }
        if ((int) $file['size'] < 1) {
            throw new \InvalidArgumentException('That file is empty.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That file is too large (max 5 MB).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new \InvalidArgumentException('Attach a JPG, PNG, WebP or PDF file.');
        }
        if ($mime === 'application/pdf') {
            $fh = fopen((string) $file['tmp_name'], 'rb');
            $head = $fh ? (string) fread($fh, 5) : '';
            $fh && fclose($fh);
            if ($head !== '%PDF-') {
                throw new \InvalidArgumentException('That PDF looks damaged. Attach it again or use a screenshot.');
            }
        } elseif (@getimagesize((string) $file['tmp_name']) === false) {
            throw new \InvalidArgumentException('That image could not be read. Try a screenshot instead.');
        }
        return ['ext' => self::TYPES[$mime], 'mime' => $mime];
    }

    // ---- saving ----------------------------------------------------------------------------------------------

    /**
     * Store the file and record the proof for an order. Older proofs still waiting for review are marked superseded.
     * @param array{bank_name:string, paid_at:string} $clean from prepare()
     * @return int the new proof id
     */
    public static function commit(int $orderId, array $clean, array $info, array $file, ?int $actorId, bool $strict = true): int
    {
        if (self::count($orderId) >= self::MAX_PER_ORDER) {
            throw new \RuntimeException('This order already has ' . self::MAX_PER_ORDER . ' receipts. Please contact us instead.');
        }
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0750, true) && !is_dir(self::dir())) {
            throw new \RuntimeException('Cannot create the receipts folder.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $info['ext'];
        $to = self::dir() . '/' . $name;
        $ok = $strict ? move_uploaded_file((string) $file['tmp_name'], $to) : copy((string) $file['tmp_name'], $to);
        if (!$ok) {
            throw new \RuntimeException('Cannot store the receipt.');
        }
        @chmod($to, 0640);
        try {
            $orig = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', ' ', (string) ($file['name'] ?? 'receipt')) ?? 'receipt'), 0, 190) ?: 'receipt';
            $pdo = Database::connection();
            $pdo->beginTransaction();
            Database::query("UPDATE payment_proofs SET status = 'superseded' WHERE order_id = ? AND status = 'submitted'", [$orderId]);
            Database::query(
                'INSERT INTO payment_proofs (order_id, bank_name, paid_at, file_path, original_name, mime, size) VALUES (?,?,?,?,?,?,?)',
                [$orderId, $clean['bank_name'], $clean['paid_at'], $name, $orig, $info['mime'], (int) $file['size']]
            );
            $id = (int) $pdo->lastInsertId();
            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'receipt', 'Payment receipt uploaded (' . $clean['bank_name'] . ')', $actorId]);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($to); // never keep a file with no record
            throw $e;
        }
    }

    // ---- reading ---------------------------------------------------------------------------------------------

    public static function count(int $orderId): int
    {
        return (int) Database::query('SELECT COUNT(*) FROM payment_proofs WHERE order_id = ?', [$orderId])->fetchColumn();
    }

    /** Newest first; replaced (superseded) uploads are left out unless asked for. */
    public static function forOrder(int $orderId, bool $withSuperseded = false): array
    {
        return Database::query(
            'SELECT p.*, u.name AS reviewer FROM payment_proofs p LEFT JOIN users u ON u.id = p.reviewed_by WHERE p.order_id = ?' . ($withSuperseded ? '' : " AND p.status <> 'superseded'") . ' ORDER BY p.id DESC',
            [$orderId]
        )->fetchAll();
    }

    public static function find(int $orderId, int $proofId): ?array
    {
        return Database::query('SELECT * FROM payment_proofs WHERE id = ? AND order_id = ?', [$proofId, $orderId])->fetch() ?: null;
    }

    /** May the customer send (another) receipt for this order? */
    public static function canUpload(array $order): bool
    {
        return $order['payment_method'] === 'bank_transfer' && $order['payment_status'] === 'unpaid'
            && in_array($order['status'], ['pending', 'processing'], true) && self::count((int) $order['id']) < self::MAX_PER_ORDER;
    }

    /** Receipts waiting for a person to look at them (unpaid, not cancelled orders only). */
    public static function pendingCount(): int
    {
        return (int) Database::query(
            "SELECT COUNT(*) FROM payment_proofs p JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'submitted' AND o.payment_status = 'unpaid' AND o.status <> 'cancelled'"
        )->fetchColumn();
    }

    /** Order ids (from $ids) that have a receipt waiting for a person (unpaid, not cancelled). @return array<int,true> */
    public static function waitingAmong(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_fill_keys(array_map('intval', Database::query(
            "SELECT DISTINCT p.order_id FROM payment_proofs p JOIN orders o ON o.id = p.order_id
             WHERE p.status = 'submitted' AND o.payment_status = 'unpaid' AND o.status <> 'cancelled' AND p.order_id IN ({$in})",
            $ids
        )->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    /** The newest receipt of each order (from $ids) that is not superseded. @return array<int,array> order id => proof row */
    public static function latestAmong(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $latest = [];
        foreach (Database::query("SELECT * FROM payment_proofs WHERE status <> 'superseded' AND order_id IN ({$in}) ORDER BY id DESC", $ids)->fetchAll() as $p) {
            $latest[(int) $p['order_id']] ??= $p;
        }
        return $latest;
    }

    // ---- review ----------------------------------------------------------------------------------------------

    /** Accept the receipt and mark the order paid. @return string|null an error message, null on success */
    public static function approve(int $orderId, int $proofId, int $actorId): ?string
    {
        $proof = self::find($orderId, $proofId);
        $order = OrderService::find($orderId);
        if (!$proof || !$order) {
            return 'That receipt was not found.';
        }
        if ($proof['status'] !== 'submitted') {
            return 'That receipt has already been ' . $proof['status'] . '.';
        }
        if ($order['status'] === 'cancelled') {
            return 'A cancelled order cannot be marked paid.';
        }
        Database::query("UPDATE payment_proofs SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), note = NULL WHERE id = ?", [$actorId, $proofId]);
        $err = OrderService::markPaid($orderId, $actorId);
        if ($err === null) {
            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'receipt_approved', 'Receipt approved', $actorId]);
        }
        return $err;
    }

    public static function reject(int $orderId, int $proofId, int $actorId, string $reason): ?string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            return 'Tell the customer why (up to 255 characters).';
        }
        $proof = self::find($orderId, $proofId);
        if (!$proof) {
            return 'That receipt was not found.';
        }
        if ($proof['status'] !== 'submitted') {
            return 'That receipt has already been ' . $proof['status'] . '.';
        }
        Database::query("UPDATE payment_proofs SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), note = ? WHERE id = ?", [$actorId, $reason, $proofId]);
        Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'receipt_rejected', mb_substr('Receipt not accepted: ' . $reason, 0, 255), $actorId]);
        return null;
    }

    /** Stream the stored file (call only after the viewer has been authorised for this order). */
    public static function send(array $proof): never
    {
        $file = self::dir() . '/' . basename((string) $proof['file_path']);
        if (!is_file($file)) {
            http_response_code(404);
            echo 'Receipt file not found.';
            exit;
        }
        $ext = self::TYPES[(string) $proof['mime']] ?? 'bin';
        header('Content-Type: ' . $proof['mime']);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="receipt-' . (int) $proof['order_id'] . '-' . (int) $proof['id'] . '.' . $ext . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: no-referrer');
        readfile($file);
        exit;
    }
}
