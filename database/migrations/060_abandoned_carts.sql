-- Snapshot of a signed-in member's cart while its items are held. Cart items are dropped when their hold lapses (15
-- minutes by default), so the reminder restores from this snapshot rather than from the live cart. restore_hash holds
-- a hash of the one-time link's token.
CREATE TABLE abandoned_carts (
    cart_id       INT UNSIGNED NOT NULL PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    items_json    TEXT         NOT NULL,
    last_seen_at  DATETIME     NOT NULL,
    reminded_at   DATETIME     NULL,
    restore_hash  CHAR(64)     NULL,
    KEY idx_abandoned_user (user_id),
    KEY idx_abandoned_due (reminded_at, last_seen_at),
    CONSTRAINT fk_abandoned_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
    CONSTRAINT fk_abandoned_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
