<?php
$qs = fn(array $o) => '?' . http_build_query(array_filter([...$filters, ...$o], fn($v) => $v !== '' && $v !== 1));
$tot = array_sum($counts);
$onReceipts = $filters['receipt'] === '1';
$receiptEye = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
$checkIcon = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
?>
<h1>Orders <small class="muted">(<?= (int) $total ?>)</small></h1>

<div id="order-edit-root" hidden data-token="<?= e(\App\Core\Csrf::token()) ?>" data-states="<?= e(json_encode(config('states'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"></div>

<nav class="ot-tabs" aria-label="Order status">
    <a href="<?= e(url($base . '/orders')) ?>" class="<?= $filters['status'] === '' && !$onReceipts ? 'on' : '' ?>">All <span class="ot-count"><?= (int) $tot ?></span></a>
    <?php foreach ($statuses as $s): ?>
        <a href="<?= e(url($base . '/orders?status=' . $s)) ?>" class="<?= $filters['status'] === $s ? 'on' : '' ?>"><?= e(ucfirst($s)) ?> <span class="ot-count"><?= (int) ($counts[$s] ?? 0) ?></span></a>
    <?php endforeach; ?>
    <?php if ($receiptCount > 0 || $onReceipts): ?>
        <a href="<?= e(url($base . '/orders?receipt=1')) ?>" class="ot-warn <?= $onReceipts ? 'on' : '' ?>">Receipts to check <span class="ot-count"><?= (int) $receiptCount ?></span></a>
    <?php endif; ?>
</nav>

<form method="get" class="ot-search" role="search">
    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search order no, name or email" aria-label="Search orders">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <?php if ($onReceipts): ?><input type="hidden" name="receipt" value="1"><?php endif; ?>
    <?php if ($filters['printed'] === '1'): ?><input type="hidden" name="printed" value="1"><?php endif; ?>
    <button class="btn">Search</button>
</form>

<?php if ($orders === []): ?>
    <p class="ot-empty">No orders here yet.</p>
<?php else: ?>
    <?php
    $printable = count(array_filter($orders, fn($o) => $o['status'] === 'processing' && isset($shipments[(int) $o['id']])));
    if ($filters['status'] === 'processing'): ?>
    <nav class="ot-chips" aria-label="Processing orders">
        <a href="<?= e(url($base . '/orders?status=processing')) ?>" class="<?= $filters['printed'] === '1' ? '' : 'on' ?>">All processing <span class="ot-count"><?= (int) ($counts['processing'] ?? 0) ?></span></a>
        <a href="<?= e(url($base . '/orders?status=processing&printed=1')) ?>" class="<?= $filters['printed'] === '1' ? 'on' : '' ?>">AWB printed <span class="ot-count"><?= (int) $printedCount ?></span></a>
    </nav>
    <?php endif; ?>
    <?php if ($filters['status'] === 'processing' && $printable > 0): ?>
    <div class="ot-bulk" data-bulk-bar data-bulk-url="<?= e(url($base . '/orders/awb/bulk')) ?>">
        <span><strong data-bulk-count>0</strong> of <?= (int) $printable ?> <?= $filters['printed'] === '1' ? 'printed' : 'ready to print' ?></span>
        <button type="button" class="btn secondary" data-bulk-all>Select all</button>
        <button type="button" class="btn secondary" data-bulk-none>Clear</button>
        <button type="button" class="btn secondary" data-bulk-print disabled>Print AWBs</button>
        <?php if ($filters['printed'] === '1'): ?>
        <form method="post" action="<?= e(url($base . '/orders/move-next')) ?>" class="inline" data-bulk-move
              data-swal-confirm="Move the selected orders on?" data-swal-button="Yes, move them">
            <?= csrf_field() ?><input type="hidden" name="ids" value="">
            <button type="submit" class="btn" data-bulk-move-btn disabled>Move to next</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php foreach ($orders as $o):
        $__id = (int) $o['id'];
        $__p = $proofs[$__id] ?? null;
        $__viewUrl = $base . '/orders/' . $__id;
        $__items = $items[$__id] ?? [];
        $__qty = array_sum(array_column($__items, 'qty'));
        // Contact, address and the delete: only while the courier has no booking for the order.
        $__open = $canUpdate && in_array($o['status'], ['pending', 'processing'], true) && !isset($booked[$__id]);
    ?>
    <article class="ot-card">
        <header class="ot-head">
            <div>
                <a class="ot-no" href="<?= e(url($__viewUrl)) ?>" data-order-details="<?= e(url($__viewUrl . '?popup=1')) ?>" data-order-no="<?= e($o['order_no']) ?>"><?= e($o['order_no']) ?></a>
                <span class="muted">· <?= e(date('d M Y, H:i', strtotime((string) $o['created_at']))) ?></span>
            </div>
            <span class="ot-status-wrap">
                <?php if ($o['status'] === 'processing' && !empty($shipments[$__id]['awb_printed_at'])): ?><span class="ot-printed">PRINTED</span><?php endif; ?>
                <span class="ot-status st-<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span>
            </span>
        </header>

        <div class="ot-body">
            <div class="ot-cust">
                <strong><?= e($o['customer_name']) ?></strong>
                <small class="muted"><?= e($o['customer_email']) ?><?= $o['user_id'] ? '' : ' · guest' ?></small>
                <small class="ot-ship">Ship to: <strong><?= e($o['ship_city']) ?>, <?= e($o['ship_state']) ?></strong></small>
            </div>
            <div class="ot-pay">
                <span class="badge <?= $o['payment_status'] === 'paid' ? 'active' : 'draft' ?>"><?= e($o['payment_status']) ?></span>
                <small class="muted"><?= e(\App\Services\Payment\Payments::label($o['payment_method'])) ?></small>
                <?php if (isset($waiting[$__id])): ?>
                    <a class="badge st-pending" href="<?= e(url($__viewUrl . '#receipt')) ?>">receipt to check</a>
                <?php endif; ?>
            </div>
            <div class="ot-total">
                <small class="muted">Order total</small>
                <strong><?= e(money($o['total'])) ?></strong>
            </div>
        </div>

        <ul class="ot-items" aria-label="Items in this order">
            <li class="ot-items-head"><span>Qty</span><span>Product</span><span>Amount</span></li>
            <?php foreach (array_slice($__items, 0, 5) as $it): ?>
                <li>
                    <span class="ot-qty">×<?= (int) $it['qty'] ?></span>
                    <span class="ot-iname"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' <small class="muted">· ' . e($it['variant_label']) . '</small>' : '' ?></span>
                    <span class="ot-line"><?= e(money($it['line_total'])) ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (count($__items) > 5): ?><li class="muted ot-more">+<?= count($__items) - 5 ?> more item(s) · <?= (int) $__qty ?> units in total</li><?php endif; ?>
            <?php if ($__items === []): ?><li class="muted">No items</li><?php endif; ?>
        </ul>

        <footer class="ot-foot">
            <?php if ($o['status'] === 'processing' && isset($shipments[$__id])): ?>
                <label class="ot-select"><input type="checkbox" data-awb-select value="<?= $__id ?>" data-cod="<?= $o['payment_method'] === 'cod' ? '1' : '0' ?>" data-paid="<?= $o['payment_status'] === 'paid' ? '1' : '0' ?>"> <?= $filters['printed'] === '1' ? 'Select' : 'Select to print' ?></label>
            <?php endif; ?>
            <?php if ($o['payment_method'] === 'bank_transfer'): ?>
                <?php if ($__p): ?>
                    <button type="button" class="icon-btn" title="View payment receipt" aria-label="View payment receipt"
                            data-proof-view="<?= e(url($base . '/orders/' . $__id . '/proofs/' . (int) $__p['id'])) ?>"
                            data-proof-mime="<?= e($__p['mime']) ?>"
                            data-proof-bank="<?= e($__p['bank_name']) ?>"
                            data-proof-paid="<?= e(date('d M Y, H:i', strtotime((string) $__p['paid_at']))) ?>"
                            data-proof-sent="<?= e(date('d M Y, H:i', strtotime((string) $__p['created_at']))) ?>"
                            data-proof-amount="<?= e(money($o['total'])) ?>"
                            data-proof-status="<?= e($__p['status']) ?>"><?= $receiptEye ?></button>
                <?php else: ?>
                    <button type="button" class="icon-btn" disabled title="No receipt sent yet" aria-label="No receipt sent yet"><?= $receiptEye ?></button>
                <?php endif; ?>
            <?php endif; ?>

            <div class="ot-actions">
                <?php if ($canUpdate && $o['status'] === 'pending'):
                    $__receipt = isset($waiting[$__id]) && $__p && $__p['status'] === 'submitted';
                    if ($__receipt) {
                        $__text = $o['order_no'] . ': the receipt is marked as paid and the order moves to Processing. Check that ' . money($o['total']) . ' reached your bank first.';
                    } elseif ($o['payment_status'] === 'paid') {
                        $__text = $o['order_no'] . ' will move to Processing.';
                    } else {
                        $__text = $o['order_no'] . ' will move to Processing. Payment is not confirmed yet (' . \App\Services\Payment\Payments::label($o['payment_method']) . ').';
                    }
                ?>
                    <form method="post" action="<?= e(url($base . '/orders/' . $__id . '/approve')) ?>" class="inline"
                          data-swal-confirm="Approve this order?" data-swal-text="<?= e($__text) ?>" data-swal-button="Yes, approve">
                        <?= csrf_field() ?><button class="icon-btn approve" title="Approve order" aria-label="Approve order"><?= $checkIcon ?></button></form>
                <?php endif; ?>
                <?php if ($__open): ?>
                    <button type="button" class="icon-btn" title="Edit details" aria-label="Edit details"
                            data-order-edit="<?= e(url($base . '/orders/' . $__id . '/details')) ?>"
                            data-order-no="<?= e($o['order_no']) ?>"
                            data-name="<?= e($o['customer_name']) ?>"
                            data-phone="<?= e($o['customer_phone']) ?>"
                            data-line1="<?= e($o['ship_line1']) ?>"
                            data-line2="<?= e((string) $o['ship_line2']) ?>"
                            data-city="<?= e($o['ship_city']) ?>"
                            data-state="<?= e($o['ship_state']) ?>"
                            data-postcode="<?= e($o['ship_postcode']) ?>"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>
                <?php endif; ?>
                <?php if ($__open): ?>
                    <form method="post" action="<?= e(url($base . '/orders/' . $__id . '/delete')) ?>" class="inline"
                          data-swal-confirm="Delete <?= e($o['order_no']) ?>?" data-swal-danger="1"
                          data-swal-text="The order moves to Cancelled and its stock goes back to the shelf. It stays in the Cancelled tab." data-swal-button="Yes, delete">
                        <?= csrf_field() ?><input type="hidden" name="from" value="<?= e($o['status']) ?>"><button class="icon-btn danger" title="Delete (moves the order to Cancelled)" aria-label="Delete (moves the order to Cancelled)"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h10l1-14"/><path d="M10 11v6M14 11v6"/></svg></button></form>
                <?php endif; ?>
                <?php if ($o['status'] === 'processing' && $canUpdate && !isset($shipments[$__id])): ?>
                    <a class="ot-view ot-push" href="<?= e(url($__viewUrl . '#shipping')) ?>" data-order-details="<?= e(url($__viewUrl . '?popup=1')) ?>" data-order-no="<?= e($o['order_no']) ?>" data-scroll-to="#shipping">Push to NinjaVan</a>
                <?php endif; ?>
                <?php if ($o['status'] === 'processing' && isset($shipments[$__id])): ?>
                    <a class="ot-view ot-print" href="<?= e(url($__viewUrl . '/shipment/awb?print=1')) ?>" target="_blank" rel="noopener">Print AWB</a>
                <?php endif; ?>
                <a class="ot-view" href="<?= e(url($__viewUrl)) ?>" data-order-details="<?= e(url($__viewUrl . '?popup=1')) ?>" data-order-no="<?= e($o['order_no']) ?>">View order</a>
            </div>
        </footer>
    </article>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
        <p class="pager">
            <?php if ($page > 1): ?><a href="<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
            Page <?= (int) $page ?> of <?= (int) $pages ?>
            <?php if ($page < $pages): ?><a href="<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>
