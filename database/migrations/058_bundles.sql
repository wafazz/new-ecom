-- Bundles: a product made of other (simple, single-option) products. The bundle's stock is worked out from its parts:
-- the number of bundles that can be made from what is on the shelf. A sale takes stock from each part.
CREATE TABLE bundle_items (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bundle_product_id  INT UNSIGNED NOT NULL,
    part_product_id    INT UNSIGNED NOT NULL,
    qty                SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_bundle_part (bundle_product_id, part_product_id),
    KEY idx_bundle_part_lookup (part_product_id),
    CONSTRAINT fk_bundle_items_bundle FOREIGN KEY (bundle_product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_bundle_items_part FOREIGN KEY (part_product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
