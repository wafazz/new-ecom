-- Member price groups (wholesale, VIP). A member in a group pays the group's price for a product when it is lower than
-- the public price. Everyone else sees the public price only.
CREATE TABLE member_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_member_group_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN member_group_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_users_member_group FOREIGN KEY (member_group_id) REFERENCES member_groups (id) ON DELETE SET NULL;

CREATE TABLE member_group_prices (
    group_id    INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    unit_price  DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (group_id, product_id),
    CONSTRAINT fk_mgp_group FOREIGN KEY (group_id) REFERENCES member_groups (id) ON DELETE CASCADE,
    CONSTRAINT fk_mgp_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
