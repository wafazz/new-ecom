<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Brands</h1>
            <p class="muted">Brand names and logos. Shown on product pages and in the storefront filters.</p>
        </div>
        <div class="msg-row">
            <span class="msg-pill"><?= count($brands) ?> brand<?= count($brands) === 1 ? '' : 's' ?></span>
            <button type="button" class="btn" id="brand-add" hidden>Add brand</button>
        </div>
    </header>

    <noscript>
    <section class="msg-card">
        <form method="post" action="<?= e(url($base . '/brands')) ?>" class="card-form inline-form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>New brand <input name="name" value="<?= old('name') ?>" required><?= field_error('name') ?></label>
            <label>Logo (optional) <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
            <button class="btn">Add</button>
        </form>
    </section>
    </noscript>

    <section class="msg-card">
        <div id="brand-root" data-base="<?= e(url($base . '/brands')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>">
        <?php if ($brands === []): ?>
            <p class="msg-empty">No brands yet. Add one to start grouping products.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Logo</th><th>Name</th><th>Slug</th><th class="num">Products</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($brands as $b): ?>
                <tr>
                    <td><?php if ($b['logo']): ?><img src="<?= e(url($b['logo'])) ?>" alt="<?= e($b['name']) ?> logo" class="brand-logo"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td><strong><?= e($b['name']) ?></strong></td>
                    <td><code><?= e($b['slug']) ?></code></td>
                    <td class="num"><?= (int) $b['product_count'] ?></td>
                    <td class="num">
                        <button type="button" class="msg-link" data-brand-edit="<?= (int) $b['id'] ?>" data-name="<?= e($b['name']) ?>" data-logo="<?= e($b['logo'] ? url($b['logo']) : '') ?>" hidden>Edit</button>
                        <form method="post" action="<?= e(url($base . '/brands/' . (int) $b['id'] . '/delete')) ?>" class="inline"
                              data-swal-confirm="Delete “<?= e($b['name']) ?>”?" data-swal-text="Its products stay, without a brand." data-swal-button="Yes, delete" data-swal-danger="1">
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
