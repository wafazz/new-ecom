<?php
/** @var string $ends  End of the sale, SQL datetime in the shop's time zone. flash-sales.js counts it down from the server clock. */
$fmt = function (int $s): string {
    $s = max(0, $s);
    $d = intdiv($s, 86400);
    $clock = sprintf('%02d:%02d:%02d', intdiv($s % 86400, 3600), intdiv($s % 3600, 60), $s % 60);
    return $d > 0 ? $d . 'd ' . $clock : $clock;
};
$left = strtotime((string) $ends) - time();
?>
<span class="flash-timer" data-countdown data-ends="<?= e(date('c', strtotime((string) $ends))) ?>" data-now="<?= e(date('c')) ?>">
    <span class="flash-timer-label">Ends in</span>
    <strong class="flash-clock" data-cd-text><?= e($fmt($left)) ?></strong>
</span>
