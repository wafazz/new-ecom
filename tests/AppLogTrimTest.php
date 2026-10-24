<?php
// app.log trimming: whole entries (with their stack traces) go once they are past the limit. No database needed.
use App\Services\Retention;

$file = sys_get_temp_dir() . '/applog-test-' . bin2hex(random_bytes(4)) . '.log';
$now = strtotime('2026-10-24 12:00:00');
file_put_contents($file, "header line\n"
    . "[2026-01-01T10:00:00+00:00] RuntimeException: old problem in x.php:1\n#0 trace of old\n"
    . "[2026-10-23T10:00:00+00:00] Exception: recent problem\n#0 trace of recent\n");

check('an entry past the limit is removed', Retention::trimAppLog($file, 90, $now) === 1);
$left = (string) file_get_contents($file);
check('its stack trace goes with it', !str_contains($left, 'trace of old') && !str_contains($left, 'old problem'));
check('recent entries and their traces stay, and so do lines before the first entry', str_contains($left, 'recent problem') && str_contains($left, 'trace of recent') && str_starts_with($left, 'header line'));
check('trimming again removes nothing', Retention::trimAppLog($file, 90, $now) === 0);
check('a missing file is nothing to trim', Retention::trimAppLog($file . '.missing', 90, $now) === 0);
unlink($file);
