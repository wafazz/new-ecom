-- Suppliers and purchase orders. Stock goes up only when a receipt is recorded, and a receipt cannot take a line past
-- the quantity ordered, so the same delivery cannot be counted twice.
CREATE TABLE suppliers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    contact     VARCHAR(190) NULL,
    lead_days   SMALLINT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_orders (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT UNSIGNED NOT NULL,
    status       ENUM('draft','sent','part_received','received','cancelled') NOT NULL DEFAULT 'draft',
    ordered_on   DATE NULL,
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_po_status (status),
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_order_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_id         INT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED NOT NULL,
    qty_ordered   INT UNSIGNED NOT NULL,
    qty_received  INT UNSIGNED NOT NULL DEFAULT 0,
    unit_cost     DECIMAL(10,2) NULL,
    KEY idx_po_items_po (po_id),
    CONSTRAINT fk_po_items_po FOREIGN KEY (po_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_po_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
