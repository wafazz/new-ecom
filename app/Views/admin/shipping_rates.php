<?php
/** Expects $zones, $fields, $rates (zone => field => stored value). */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Shipping rates</h1>
            <p class="muted">Postage by zone and weight. The total weight of the order is rounded up to whole kilos, and the first kilo is charged at the first-kilo rate, then each extra kilo at the next-kilo rate.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>How a delivery is priced</h2>
        <ul class="tips">
            <li><strong>Normal postage</strong> = first kilo rate + (kilos − 1) × next kilo rate. Weight is rounded up: 1.2 kg is charged as 2 kg.</li>
            <li><strong>Non-COD orders</strong> pay normal postage only.</li>
            <li><strong>COD orders</strong> pay normal postage plus a COD surcharge: a percent of the items' sales (after any discount, before shipping). Sales at or below the benchmark use the first percent; sales above it use the second.</li>
            <li>Zone 1 is Peninsular Malaysia. Zone 2 is Sabah, Sarawak and Labuan. Until both zones have every rate filled in, checkout is not available.</li>
        </ul>
    </section>

    <?php foreach ($zones as $zone => $label): ?>
    <section class="msg-card">
        <h2><?= e($label) ?></h2>
        <form method="post" action="<?= e(url('/admin/shipping-rates')) ?>" class="card-form" novalidate data-busy-form>
            <?= csrf_field() ?>
            <div class="grid2">
                <?php foreach ($fields as $k => $fieldLabel): ?>
                    <label><?= e($fieldLabel) ?>
                        <input name="<?= e($zone) ?>[<?= e($k) ?>]" inputmode="decimal" value="<?= old($zone . '.' . $k, $rates[$zone][$k] ?? '') ?>" placeholder="<?= str_starts_with($k, 'cod_') ? 'e.g. 5' : 'e.g. 8.00' ?>">
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="msg-row"><small class="muted">Leave a field blank to keep its saved value.</small><button class="btn">Save <?= e($label) ?></button></div>
        </form>
    </section>
    <?php endforeach; ?>
    <?php if (\App\Services\ShippingRates::rates('zone1') === null || \App\Services\ShippingRates::rates('zone2') === null): ?>
        <div class="alert bad">Checkout is not available yet: fill in every rate for both zones.</div>
    <?php endif; ?>
</div>
