<h1>Brands</h1>
<p><button type="button" class="btn" id="brand-add" hidden>Add brand</button></p>
<noscript>
<form method="post" action="<?= e(url($base . '/brands')) ?>" class="card-form inline-form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>New brand <input name="name" value="<?= old('name') ?>" required><?= field_error('name') ?></label>
    <label>Logo (optional) <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
    <button class="btn">Add</button>
</form>
</noscript>
<div id="brand-root" data-base="<?= e(url($base . '/brands')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>">
<?php if ($brands === []): ?>
    <p class="muted">No brands yet.</p>
<?php else: ?>
<table>
    <thead><tr><th>Logo</th><th>Name</th><th>Slug</th><th class="num">Products</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($brands as $b): ?>
        <tr>
            <td><?php if ($b['logo']): ?><img src="<?= e(url($b['logo'])) ?>" alt="<?= e($b['name']) ?> logo" class="brand-logo"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?= e($b['name']) ?></td><td><?= e($b['slug']) ?></td><td class="num"><?= (int) $b['product_count'] ?></td>
            <td>
                <button type="button" class="link" data-brand-edit="<?= (int) $b['id'] ?>" data-name="<?= e($b['name']) ?>" data-logo="<?= e($b['logo'] ? url($b['logo']) : '') ?>" hidden>Edit</button>
                <form method="post" action="<?= e(url($base . '/brands/' . (int) $b['id'] . '/delete')) ?>" class="inline"
                      data-swal-confirm="Delete “<?= e($b['name']) ?>”?" data-swal-text="Its products stay, without a brand." data-swal-button="Yes, delete" data-swal-danger="1">
                    <?= csrf_field() ?><button class="link danger">Delete</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
