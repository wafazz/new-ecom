<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Categories</h1>
            <p class="muted">How products are grouped. A category can sit under another one, so the shop can show a menu.</p>
        </div>
        <div class="msg-row">
            <span class="msg-pill"><?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?></span>
            <button type="button" class="btn" id="cat-add" hidden>Add category</button>
        </div>
    </header>

    <noscript>
    <section class="msg-card">
        <form method="post" action="<?= e(url($base . '/categories')) ?>" class="card-form inline-form">
            <?= csrf_field() ?>
            <label>New category <input name="name" value="<?= old('name') ?>" required><?= field_error('name') ?></label>
            <button class="btn">Add</button>
        </form>
    </section>
    </noscript>

    <section class="msg-card">
        <div id="cat-root" data-base="<?= e(url($base . '/categories')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>" data-parents="<?= e(json_encode($parents, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
        <?php if ($categories === []): ?>
            <p class="msg-empty">No categories yet. Add one to start grouping products.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Name</th><th>Parent</th><th>Slug</th><th class="num">Products</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><?= $c['depth'] ? '<span class="muted">└</span> ' : '' ?><strong><?= e($c['name']) ?></strong></td>
                    <td><small><?= e($c['parent_name'] ?? '—') ?></small></td>
                    <td><code><?= e($c['slug']) ?></code></td>
                    <td class="num"><?= (int) $c['product_count'] ?></td>
                    <td class="num">
                        <button type="button" class="msg-link" data-cat-edit="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-parent="<?= (int) ($c['parent_id'] ?? 0) ?>" data-children="<?= (int) $c['child_count'] ?>" hidden>Edit</button>
                        <form method="post" action="<?= e(url($base . '/categories/' . (int) $c['id'] . '/delete')) ?>" class="inline"
                              data-swal-confirm="Delete “<?= e($c['name']) ?>”?" data-swal-text="Its products stay, uncategorised<?= (int) $c['child_count'] > 0 ? '; its sub-categories become top-level' : '' ?>." data-swal-button="Yes, delete" data-swal-danger="1">
                            <?= csrf_field() ?><button class="msg-link danger">Delete</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        </div>
    </section>
</div>
