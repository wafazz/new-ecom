<?php
/** One AWB label. Expects $order, $ship, $sender (array|null), $items (array|null: null = do not print items). */
use App\Services\Shipping\NinjaVanGateway as NV;

$tn = (string) $ship['tracking_number'];
$addr = fn(string ...$parts) => implode(', ', array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
$senderAddr = $sender ? $addr($sender['sender_address1'], (string) $sender['sender_address2'], $sender['sender_city'], $sender['sender_state']) : '';
$recipientAddr = $addr($order['ship_line1'], (string) $order['ship_line2'], $order['ship_city'], $order['ship_state']);
$shipBy = $ship['pickup_required'] && $ship['pickup_date'] ? date('d-m-Y', strtotime((string) $ship['pickup_date'])) : '';
// J&T Express waybills follow J&T's own layout (sorting codes in the destination box, Pengirim/Penerima headings).
$isJnt = ($ship['carrier'] ?? '') === 'jnt';
?>
<main class="label">
    <header class="top">
        <?php if ($isJnt): ?>
        <div class="brand jnt"><span>J&amp;T EXPRESS</span></div>
        <?php else: ?>
        <div class="brand"><?php $__shop = \App\Services\SiteLogo::url(); if ($__shop): ?><img class="brand-shop" src="<?= e($__shop) ?>" alt=""><?php endif; ?><span>NinjaVan</span></div>
        <?php endif; ?>
        <svg class="top-bc" data-barcode="<?= e($tn) ?>" data-stretch="1" aria-label="Barcode for <?= e($tn) ?>"></svg>
        <div class="tn"><?= e($tn) ?></div>
    </header>

    <div class="main">
        <div class="left">
            <section class="box">
                <div class="box-h">Order Details</div>
                <div class="box-b">
                    <div class="row"><b>Ship by:</b><span><?= e($shipBy !== '' ? $shipBy : '—') ?></span></div>
                    <div class="row"><b>Weight:</b><span><?= e(number_format((float) $ship['weight_kg'], 2)) ?> kg</span></div>
                    <div class="row"><b>Order ID:</b><span><strong><?= e($order['order_no']) ?></strong></span></div>
                    <?php if ($items !== null): ?>
                        <div class="items">
                            <div class="muted">Items:</div>
                            <?php foreach (array_slice($items, 0, 6) as $it): ?>
                                <div class="item"><strong><?= (int) $it['qty'] ?> ×</strong> <?= e($it['product_name']) ?><?= $it['variant_label'] ? ' (' . e($it['variant_label']) . ')' : '' ?></div>
                            <?php endforeach; ?>
                            <?php if (count($items) > 6): ?><div class="muted">+<?= count($items) - 6 ?> more</div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="box">
                <div class="box-h"><?= $isJnt ? 'Sender Details (Pengirim)' : 'Sender Details' ?></div>
                <div class="box-b">
                    <?php if ($sender): ?>
                        <div class="row"><b>Name:</b><span><?= e($sender['sender_name']) ?></span></div>
                        <div class="row"><b>Phone:</b><span><?= e($sender['sender_phone']) ?></span></div>
                        <div class="row"><b>Address:</b><span><?= e($senderAddr) ?></span></div>
                        <div class="row"><b>Postcode:</b><span><?= e($sender['sender_postcode']) ?></span></div>
                    <?php else: ?>
                        <p class="muted">Set the sender details in the NinjaVan settings.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="box">
                <div class="box-h"><?= $isJnt ? 'Recipient Details (Penerima)' : 'Recipient Details' ?></div>
                <div class="box-b">
                    <div class="row"><b>Name:</b><span><?= e($order['customer_name']) ?></span></div>
                    <div class="row"><b>Phone:</b><span><?= e($order['customer_phone']) ?></span></div>
                    <div class="row"><b>Address:</b><span><?= e($recipientAddr) ?></span></div>
                    <div class="row"><b>Postcode:</b><span><?= e($order['ship_postcode']) ?></span></div>
                </div>
            </section>

            <section class="box pod">
                <div class="box-h">POD</div>
                <div class="box-b">
                    <div class="row"><b>Name:</b><span></span></div>
                    <div class="row"><b>I.C.:</b><span></span></div>
                    <div class="row"><b>Signature:</b><span></span></div>
                </div>
            </section>
        </div>

        <div class="right">
            <section class="box">
                <div class="box-h">Order Details (Courier)</div>
                <div class="box-b">
                    <?php if ($isJnt): ?><div class="row"><b>Parcel:</b><span>1 of 1</span></div><?php endif; ?>
                    <?php if (!empty($ship['service_level'])): ?><div class="row"><b>Service:</b><span><?= e(NV::LEVELS[$ship['service_level']] ?? $ship['service_level']) ?></span></div><?php endif; ?>
                    <div class="row"><b>COD:</b><span><?= $ship['cod_amount'] !== null ? e(money($ship['cod_amount'])) : 'None' ?></span></div>
                </div>
            </section>

            <section class="box dest<?= $isJnt ? ' jnt' : '' ?>">
                <?php if ($isJnt): ?>
                    <div class="code"><?= e($ship['sorting_code'] ?? '—') ?></div>
                    <div class="code2"><?= e($ship['third_sorting_code'] ?? '') ?></div>
                    <div class="postcode"><?= e($order['ship_postcode']) ?></div>
                <?php else: ?>
                    <div class="state"><?= e(strtoupper((string) $order['ship_state'])) ?></div>
                    <div class="postcode"><?= e($order['ship_postcode']) ?></div>
                <?php endif; ?>
                <div class="kind">HOME</div>
            </section>

            <section class="box qr">
                <div class="awb-qr" data-qr="<?= e($tn) ?>" aria-label="QR code for <?= e($tn) ?>"></div>
            </section>

            <section class="box bc">
                <svg data-barcode="<?= e($tn) ?>" aria-label="Barcode for <?= e($tn) ?>"></svg>
                <div class="tn"><?= e($tn) ?></div>
            </section>
        </div>
    </div>
</main>
