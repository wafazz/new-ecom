<?php
/** Expects $counts (with line totals) and $categories. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Stock counts</h1>
            <p class="muted">Make a count sheet for a category, or for everything. Counters write the numbers they see, without the system figure. Then review the differences and apply the ones that are right.</p>
        </div>
    </header>
    <form method="post" action="<?= e(url('/admin/stock-counts')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>Name <input name="title" maxlength="120" required placeholder="Shelf A, Tuesday"></label>
        <label>Category (blank = all products)
            <select name="category_id"><option value="0">All products</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
        <div class="msg-row"><span></span><button class="btn">Make count sheet</button></div>
    </form>
    <section class="msg-card">
        <h2>Counts</h2>
        <?php if ($counts === []): ?>
            <p class="muted">No counts yet.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Name</th><th>Scope</th><th>Counted</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($counts as $c): ?>
                            <tr>
                                <td><?= e($c['title']) ?></td>
                                <td><?= e($c['scope_label']) ?></td>
                                <td><?= (int) $c['counted'] ?> of <?= (int) $c['lines'] ?></td>
                                <td><?= e($c['status']) ?></td>
                                <td><a class="btn secondary sm" href="<?= e(url('/admin/stock-counts/' . (int) $c['id'])) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
