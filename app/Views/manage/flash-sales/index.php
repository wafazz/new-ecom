<?php
/** Expects $base ('/admin' or '/staff'), $sales (FlashSaleService::listing()). */
$tone = ['live' => ['Live now', 'ok'], 'scheduled' => ['Scheduled', 'info'], 'ended' => ['Ended', 'neu'], 'off' => ['Off', 'neu']];
$when = fn(?string $t) => $t ? date('d M Y, H:i', strtotime($t)) : '';
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Flash sales</h1>
            <p class="muted">Each sale has its own timeframe and a list of products taking a percentage off while it runs. Sales can be scheduled back to back or overlap; where they overlap, the bigger percentage applies. The countdown shows on the product page and in the storefront block.</p>
        </div>
        <div class="row-between">
            <span class="msg-pill ok"><?= count($sales) ?> sale<?= count($sales) === 1 ? '' : 's' ?></span>
            <a class="btn" href="<?= e(url($base . '/flash-sales/create')) ?>">New flash sale</a>
        </div>
    </header>

    <section class="msg-card">
        <h2>All flash sales</h2>
        <?php if ($sales === []): ?>
            <p class="msg-empty" style="margin-top:.6rem">No flash sales yet. Use <strong>New flash sale</strong> to schedule one.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table">
                <thead><tr><th>Name</th><th>Timeframe</th><th>Products</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($sales as $s): [$label, $tn] = $tone[$s['status']]; ?>
                    <tr>
                        <td><strong><?= e($s['name']) ?></strong></td>
                        <td><small><?= e($when($s['starts_at'])) ?> → <?= e($when($s['ends_at'])) ?></small></td>
                        <td><?= (int) $s['product_count'] ?></td>
                        <td><span class="msg-pill <?= e($tn) ?>"><?= e($label) ?></span></td>
                        <td class="num">
                            <a class="btn secondary" href="<?= e(url($base . '/flash-sales/' . (int) $s['id'] . '/edit')) ?>">Edit</a>
                            <?php if ($s['status'] !== 'ended'): ?>
                                <form method="post" action="<?= e(url($base . '/flash-sales/' . (int) $s['id'] . '/active')) ?>" class="inline">
                                    <?= csrf_field() ?><button class="btn secondary" type="submit"><?= $s['status'] === 'off' ? 'Switch on' : 'Switch off' ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url($base . '/flash-sales/' . (int) $s['id'] . '/delete')) ?>" class="inline" data-swal-confirm="Delete this flash sale?" data-swal-text="The products go back to their normal prices. This cannot be undone." data-swal-danger="1" data-swal-button="Yes, delete">
                                <?= csrf_field() ?><button class="btn secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>
