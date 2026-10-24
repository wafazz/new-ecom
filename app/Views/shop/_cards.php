<?php /** Product cards for one page of results, then a marker telling the script which page comes next (0 = none). */
foreach ($products as $p) { require BASE_PATH . '/app/Views/partials/product_card.php'; } ?>
<span hidden data-next="<?= $page < $pages ? $page + 1 : 0 ?>"></span>
