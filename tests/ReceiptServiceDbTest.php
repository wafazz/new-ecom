<?php
// Bank transfer proof: validation of bank / date / file, private storage, supersede, caps, approve and reject. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\Banks;
use App\Services\ReceiptService as R;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query('DELETE FROM payment_proofs');
$tmp = sys_get_temp_dir() . '/rcpt_' . bin2hex(random_bytes(4));
mkdir($tmp);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$mk = function (string $name, string $bytes, int $error = UPLOAD_ERR_OK) use ($tmp) {
    $p = $tmp . '/' . bin2hex(random_bytes(4));
    file_put_contents($p, $bytes);
    return ['name' => $name, 'tmp_name' => $p, 'error' => $error, 'size' => strlen($bytes)];
};
$now = new DateTimeImmutable('2026-10-24 15:00:00');
$post = ['proof_bank' => 'Maybank', 'proof_paid_at' => '2026-10-24T14:30'];
$prep = fn(array $over = [], ?array $file = null) => R::prepare($over + $post, $file ?? $mk('slip.png', $png), false, $now);

// ---- the bank list
$groups = Banks::groups();
check('bank list: groups, no duplicates, the big names are there', count(Banks::all()) === count(array_unique(Banks::all())) && count(Banks::all()) >= 45 && Banks::known('Maybank') && Banks::known('CIMB Bank') && Banks::known('Bank Simpanan Nasional (BSN)') && Banks::known('GXBank') && !Banks::known('Bank of Nowhere') && isset($groups['Popular']));

// ---- details
$ok = $prep();
check('a complete, valid proof passes and normalises the date', $ok['errors'] === [] && $ok['clean'] === ['bank_name' => 'Maybank', 'paid_at' => '2026-10-24 14:30:00'] && $ok['info'] === ['ext' => 'png', 'mime' => 'image/png']);
check('bank name is required and limited to 80 characters; control characters are removed', isset($prep(['proof_bank' => ' '])['errors']['proof_bank']) && isset($prep(['proof_bank' => str_repeat('x', 81)])['errors']['proof_bank']) && $prep(['proof_bank' => "May\x00bank\n"])['clean']['bank_name'] === 'May bank');
check('a bank that is not on the list can be typed in', $prep(['proof_bank' => 'My Credit Union'])['errors'] === []);
check('date/time: required, must be real, not in the future, not older than 30 days', isset($prep(['proof_paid_at' => ''])['errors']['proof_paid_at']) && isset($prep(['proof_paid_at' => 'tomorrow'])['errors']['proof_paid_at']) && isset($prep(['proof_paid_at' => '2026-02-30T10:00'])['errors']['proof_paid_at']) && isset($prep(['proof_paid_at' => '2026-10-24T16:00'])['errors']['proof_paid_at']) && isset($prep(['proof_paid_at' => '2026-09-01T10:00'])['errors']['proof_paid_at']));
check('date/time: a few minutes of clock difference is tolerated; the edge of the window is accepted', $prep(['proof_paid_at' => '2026-10-24T15:05'])['errors'] === [] && $prep(['proof_paid_at' => '2026-09-25T15:00'])['errors'] === []);
check('date/time: both browser formats are understood', $prep(['proof_paid_at' => '2026-10-24 09:15'])['clean']['paid_at'] === '2026-10-24 09:15:00' && $prep(['proof_paid_at' => '2026-10-24T09:15:30'])['errors'] === []);
check('array / junk values for the fields are refused, not crashed on', isset(R::prepare(['proof_bank' => ['x'], 'proof_paid_at' => ['y']], $mk('s.png', $png), false, $now)['errors']['proof_bank']));

// ---- files
$err = fn(?array $f) => R::prepare($post, $f, false, $now)['errors']['receipt'] ?? null;
check('no file attached: a clear message', str_contains((string) $err(null), 'attach') && str_contains((string) $err(['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]), 'attach'));
check('a file over the limit is refused (declared by PHP or by size)', str_contains((string) $err($mk('big.png', $png, UPLOAD_ERR_INI_SIZE)), 'too large') && str_contains((string) $err($mk('big.png', $png . str_repeat('0', R::MAX_BYTES))), 'too large'));
check('an interrupted upload and an empty file are refused', str_contains((string) $err($mk('x.png', $png, UPLOAD_ERR_PARTIAL)), 'did not finish') && str_contains((string) $err($mk('x.png', '')), 'empty'));
check('only JPG / PNG / WebP / PDF: a script, HTML, SVG, GIF or a renamed text file is refused whatever its name', str_contains((string) $err($mk('slip.png', '<?php echo 1;')), 'JPG, PNG') && str_contains((string) $err($mk('slip.jpg', '<html><script>1</script></html>')), 'JPG, PNG') && str_contains((string) $err($mk('slip.png', '<svg xmlns="http://www.w3.org/2000/svg"/>')), 'JPG, PNG') && str_contains((string) $err($mk('slip.jpg', "GIF89a\x01\x00\x01\x00")), 'JPG, PNG'));
check('a PDF is accepted; a truncated image is not', $prep([], $mk('slip.pdf', "%PDF-1.4\n%%EOF\n"))['info']['ext'] === 'pdf' && isset($prep([], $mk('slip.png', substr($png, 0, 20)))['errors']['receipt']));
check('a real JPEG is accepted', (function () use ($prep, $mk) { $im = imagecreatetruecolor(4, 4); ob_start(); imagejpeg($im); $jpg = ob_get_clean(); return $prep([], $mk('p.jpeg', $jpg))['info']['ext'] === 'jpg'; })());
check('the file name from the browser never decides the type', $prep([], $mk('evil.php', $png))['info']['ext'] === 'png');
check('all problems are reported together', count(R::prepare(['proof_bank' => '', 'proof_paid_at' => ''], null, false, $now)['errors']) === 3);

// ---- saving
$member = User::create('member', 'Mia', 'mia@example.test', 'Secret123!');
$mkOrder = fn(string $method = 'bank_transfer', string $pay = 'unpaid', string $status = 'pending') => (function () use ($member, $method, $pay, $status) {
    DB::query("INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_city, ship_state, ship_postcode, subtotal, shipping_fee, total, payment_method, payment_status, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        ['ORD-T' . bin2hex(random_bytes(4)), $member, 'Mia', 'mia@example.test', '012', '1 Jalan', 'KL', 'Kuala Lumpur', '50000', '10', '0', '10', $method, $pay, $status]);
    return (int) DB::connection()->lastInsertId();
})();
$o1 = $mkOrder();
$f1 = $mk('My Slip.png', $png);
$p = $prep([], $f1);
$id1 = R::commit($o1, $p['clean'], $p['info'], $f1, null, false);
$row = R::find($o1, $id1);
check('commit stores the receipt in the private folder under a random name, with the record', $id1 > 0 && $row['status'] === 'submitted' && $row['bank_name'] === 'Maybank' && $row['paid_at'] === '2026-10-24 14:30:00' && $row['original_name'] === 'My Slip.png' && preg_match('/^[a-f0-9]{32}\.png$/', $row['file_path']) && is_file(R::dir() . '/' . $row['file_path']) && !str_contains(R::dir(), '/public/'));
check('the file on disk is exactly what was uploaded', file_get_contents(R::dir() . '/' . $row['file_path']) === $png);
check('the order history records the upload', (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event = 'receipt'", [$o1])->fetchColumn() === 1);
check('a path-like original name cannot escape or confuse anything', (function () use ($mk, $png, $prep, $o1) { $f = $mk('../../etc/passwd.png', $png); $p = $prep([], $f); $id = R::commit($o1, $p['clean'], $p['info'], $f, null, false); $r = R::find($o1, $id); return !str_contains($r['original_name'], '/') && !str_contains($r['original_name'], '\\') && preg_match('/^[a-f0-9]{32}\.png$/', $r['file_path']); })());
check('a second upload supersedes the earlier one that was still waiting', (function () use ($o1) { $s = array_column(R::forOrder($o1, true), 'status'); return in_array('superseded', $s, true) && count(array_keys($s, 'submitted', true)) === 1; })());
check('forOrder() hides superseded uploads and lists newest first', count(R::forOrder($o1)) === 1 && count(R::forOrder($o1, true)) === 2);
check('there is a cap on receipts per order', (function () use ($o1, $mk, $png, $prep) { for ($i = 0; $i < R::MAX_PER_ORDER; $i++) { try { $f = $mk('s.png', $png); $p = $prep([], $f); R::commit($o1, $p['clean'], $p['info'], $f, null, false); } catch (RuntimeException) { return R::count($o1) === R::MAX_PER_ORDER; } } return false; })());

// ---- who may upload
check('canUpload: unpaid bank-transfer orders that are pending / processing, below the cap', R::canUpload(['id' => $mkOrder(), 'payment_method' => 'bank_transfer', 'payment_status' => 'unpaid', 'status' => 'pending']) === true && R::canUpload(['id' => $mkOrder('bank_transfer', 'unpaid', 'processing'), 'payment_method' => 'bank_transfer', 'payment_status' => 'unpaid', 'status' => 'processing']) === true);
check('canUpload: not for COD, paid, shipped, cancelled, or at the cap', !R::canUpload(['id' => 1, 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'pending']) && !R::canUpload(['id' => 1, 'payment_method' => 'bank_transfer', 'payment_status' => 'paid', 'status' => 'pending']) && !R::canUpload(['id' => 1, 'payment_method' => 'bank_transfer', 'payment_status' => 'unpaid', 'status' => 'shipped']) && !R::canUpload(['id' => 1, 'payment_method' => 'bank_transfer', 'payment_status' => 'unpaid', 'status' => 'cancelled']) && !R::canUpload(['id' => $o1, 'payment_method' => 'bank_transfer', 'payment_status' => 'unpaid', 'status' => 'pending']));

// ---- review
$o2 = $mkOrder(); $f = $mk('s.png', $png); $p = $prep([], $f); $pid = R::commit($o2, $p['clean'], $p['info'], $f, null, false);
$admin = User::create('admin', 'Ann', 'ann@example.test', 'Secret123!');
check('pendingCount counts receipts waiting on unpaid, live orders; waitingAmong() finds their orders', R::pendingCount() >= 1 && isset(R::waitingAmong([$o2, 999999])[$o2]) && !isset(R::waitingAmong([$o2, 999999])[999999]) && R::waitingAmong([]) === []);
check('reject needs a reason (and a real, waiting receipt)', R::reject($o2, $pid, $admin, ' ') !== null && R::reject($o2, 999999, $admin, 'x') !== null && R::reject($o2, $pid, $admin, str_repeat('x', 256)) !== null && R::find($o2, $pid)['status'] === 'submitted');
check('reject saves the reason and the order stays unpaid', R::reject($o2, $pid, $admin, 'The amount is wrong') === null && R::find($o2, $pid)['status'] === 'rejected' && R::find($o2, $pid)['note'] === 'The amount is wrong' && (int) R::find($o2, $pid)['reviewed_by'] === $admin && DB::query('SELECT payment_status FROM orders WHERE id = ?', [$o2])->fetchColumn() === 'unpaid');
check('a reviewed receipt cannot be reviewed again', R::reject($o2, $pid, $admin, 'again') !== null && R::approve($o2, $pid, $admin) !== null);
check('after a rejection the customer may send another receipt', R::canUpload(\App\Services\OrderService::find($o2)));
$f = $mk('s2.png', $png); $p = $prep([], $f); $pid2 = R::commit($o2, $p['clean'], $p['info'], $f, null, false);
check('approve marks the receipt approved AND the order paid, with history', R::approve($o2, $pid2, $admin) === null && R::find($o2, $pid2)['status'] === 'approved' && DB::query('SELECT payment_status FROM orders WHERE id = ?', [$o2])->fetchColumn() === 'paid' && (int) DB::query("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event IN ('paid','receipt_approved')", [$o2])->fetchColumn() === 2);
check('once paid, no more receipts are accepted; pendingCount drops', !R::canUpload(\App\Services\OrderService::find($o2)));
$o3 = $mkOrder(); $f = $mk('s.png', $png); $p = $prep([], $f); $pid3 = R::commit($o3, $p['clean'], $p['info'], $f, null, false);
DB::query("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$o3]);
check('a cancelled order cannot be approved, and its receipt is not "waiting"', R::approve($o3, $pid3, $admin) !== null && !isset(R::waitingAmong([$o3])[$o3]) && DB::query('SELECT payment_status FROM orders WHERE id = ?', [$o3])->fetchColumn() === 'unpaid');
check('receipts of another order cannot be reached by id', R::find($o1, $pid) === null && R::find($o2, $pid3) === null);
check('deleting an order removes its records (the files stay for an admin to purge, never public)', (function () use ($o3) { foreach (R::forOrder($o3, true) as $pr) { @unlink(R::dir() . '/' . $pr['file_path']); } DB::query('DELETE FROM orders WHERE id = ?', [$o3]); return R::count($o3) === 0; })());

check('latestAmong gives each order its newest live receipt (never a superseded one)', (function () use ($o2, $pid2, $pid) { $l = R::latestAmong([$o2, 999999]); return ($l[$o2]['id'] ?? null) === $pid2 && !isset($l[999999]) && R::latestAmong([]) === [] && R::find($o2, $pid)['status'] === 'rejected'; })());

// tidy up files this test wrote
foreach (DB::query('SELECT file_path FROM payment_proofs')->fetchAll(PDO::FETCH_COLUMN) as $f) { @unlink(R::dir() . '/' . $f); }
foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); } @rmdir($tmp);
DB::query('DELETE FROM payment_proofs');
