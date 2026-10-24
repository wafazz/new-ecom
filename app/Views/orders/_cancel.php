<?php /** Expects: $cancelAction (path|null) */ ?>
<?php if (!empty($cancelAction)): ?>
    <form method="post" action="<?= e(url($cancelAction)) ?>" data-swal-confirm="Cancel this order?" data-swal-text="Items go back to stock. This cannot be undone." data-swal-button="Yes, cancel order" data-swal-danger="1">
        <?= csrf_field() ?><button class="btn secondary">Cancel order</button>
    </form>
<?php endif; ?>
