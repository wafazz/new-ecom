<?php
// Permissions an admin can grant to staff. Admin implicitly has all of them.
return [
    'products.manage' => 'Manage products (add / edit / delete)',
    'stock.manage'    => 'Stock Control: adjust stock quantities and see stock history',
    'orders.view'     => 'View orders',
    'orders.update'   => 'Update order status',
    'members.view'    => 'View members',
    'shipping.manage' => 'Manage courier (NinjaVan) credentials and pickup address',
    'payments.manage' => 'Manage payment gateway (Billplz) credentials',
];
