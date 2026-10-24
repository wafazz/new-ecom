<h1>Categories</h1>
<p><button type="button" class="btn" id="cat-add" hidden>Add category</button></p>
<noscript>
<form method="post" action="<?= e(url($base . '/categories')) ?>" class="card-form inline-form">
    <?= csrf_field() ?>
    <label>New category <input name="name" value="<?= old('name') ?>" required><?= field_error('name') ?></label>
    <button class="btn">Add</button>
</form>
</noscript>
<div id="cat-root" data-base="<?= e(url($base . '/categories')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>" data-parents="<?= e(json_encode($parents, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
<?php if ($categories === []): ?>
    <p class="muted">No categories yet.</p>
<?php else: ?>
<table>
    <thead><tr><th>Name</th><th>Parent</th><th>Slug</th><th class="num">Products</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
        <tr>
            <td><?= $c['depth'] ? '<span class="muted">└</span> ' : '' ?><?= e($c['name']) ?></td><td><?= e($c['parent_name'] ?? '—') ?></td><td><?= e($c['slug']) ?></td><td class="num"><?= (int) $c['product_count'] ?></td>
            <td>
                <button type="button" class="link" data-cat-edit="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-parent="<?= (int) ($c['parent_id'] ?? 0) ?>" data-children="<?= (int) $c['child_count'] ?>" hidden>Edit</button>
                <form method="post" action="<?= e(url($base . '/categories/' . (int) $c['id'] . '/delete')) ?>" class="inline"
                      data-swal-confirm="Delete “<?= e($c['name']) ?>”?" data-swal-text="Its products stay, uncategorised<?= (int) $c['child_count'] > 0 ? '; its sub-categories become top-level' : '' ?>." data-swal-button="Yes, delete" data-swal-danger="1">
                    <?= csrf_field() ?><button class="link danger">Delete</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
