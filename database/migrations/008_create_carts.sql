-- A cart belongs to a member (user_id) or to an anonymous browser (session_hash = sha256 of the cookie token).
CREATE TABLE carts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    session_hash CHAR(64) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_carts_user (user_id),
    UNIQUE KEY uq_carts_session (session_hash),
    KEY idx_carts_updated (updated_at),
    CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cart_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id     INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    variant_id  INT UNSIGNED NULL,
    variant_key INT UNSIGNED GENERATED ALWAYS AS (COALESCE(variant_id, 0)) STORED,
    qty         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_line (cart_id, product_id, variant_key),
    CONSTRAINT fk_items_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    KEY idx_items_variant (variant_id),
    -- No FK on variant_id: MySQL forbids cascading FKs on the base column of a generated column.
    -- Variants are only soft-deleted (cart summary treats them as unavailable), and a hard product
    -- delete cascades to its cart items through the product FK below.
    CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
