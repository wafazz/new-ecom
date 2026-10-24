<?php
// Scheduled backups: which old files to delete. Pure rule, no files touched.
use App\Services\Backup;

$names = [
    'backup-20260101-020000.sql.gz', 'backup-20260101-020000-files.zip',
    'backup-20260102-020000.sql.gz', 'backup-20260102-020000-files.zip',
    'backup-20260103-020000.sql.gz',
    'backup-20260104-020000.sql.gz', 'backup-20260104-020000-files.zip',
    'backup-20260101-020000.sql.gz.tmp', 'notes.txt', 'backup-20260105-020000-files.zip',
];

$gone = Backup::pruneNames($names, 2);
sort($gone);
check('keeping 2 removes the two oldest dumps with their zips, and the orphan zip', $gone === [
    'backup-20260101-020000-files.zip', 'backup-20260101-020000.sql.gz',
    'backup-20260102-020000-files.zip', 'backup-20260102-020000.sql.gz',
    'backup-20260105-020000-files.zip',
]);
check('a file that is not a backup is never listed for removal', !in_array('notes.txt', $gone, true) && !in_array('backup-20260101-020000.sql.gz.tmp', $gone, true));
check('keeping more than exist removes only orphans', Backup::pruneNames($names, 50) === ['backup-20260105-020000-files.zip']);
check('keeping 1 keeps the newest dump with its zip', Backup::pruneNames(['backup-20260101-020000.sql', 'backup-20260102-020000.sql', 'backup-20260102-020000-files.zip'], 1) === ['backup-20260101-020000.sql']);
