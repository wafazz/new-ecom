<?php
/** Expects $groups, $products, $prices (group, product, price). */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Member prices</h1>
            <p class="muted">A member in a group pays the group price where it is lower than the public price. Other shoppers never see group prices.</p>
        </div>
    </header>
    <form method="post" action="<?= e(url('/admin/member-prices/group')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>New group name <input name="name" maxlength="80" required placeholder="Wholesale"></label>
        <div class="msg-row"><span></span><button class="btn">Create group</button></div>
    </form>
    <form method="post" action="<?= e(url('/admin/member-prices/assign')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>Member email <input type="email" name="email" maxlength="190" required></label>
        <label>Group
            <select name="group_id"><option value="0">No group</option><?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?></select></label>
        <div class="msg-row"><span></span><button class="btn">Put member in group</button></div>
    </form>
    <form method="post" action="<?= e(url('/admin/member-prices/price')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>Group
            <select name="group_id"><?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?></select></label>
        <label>Product
            <select name="product_id"><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
        <label>Group price (RM)<input name="price" inputmode="decimal" placeholder="8.00"></label>
        <div class="msg-row"><span></span><button class="btn">Save group price</button></div>
    </form>
    <section class="msg-card">
        <h2>Groups</h2>
        <?php if ($groups === []): ?><p class="muted">No groups yet.</p>
        <?php else: ?>
            <ul class="perm-chips"><?php foreach ($groups as $g): ?><li><?= e($g['name']) ?> · <?= (int) $g['members'] ?> member(s)</li><?php endforeach; ?></ul>
            <div class="msg-table-wrap"><table class="msg-table"><thead><tr><th>Group</th><th>Product</th><th>Group price</th></tr></thead><tbody>
                <?php foreach ($prices as $pr): ?><tr><td><?= e($pr['grp']) ?></td><td><?= e($pr['name']) ?></td><td>RM<?= e(number_format((float) $pr['unit_price'], 2)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
