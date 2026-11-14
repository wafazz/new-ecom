-- Gift cards: issued by admins, redeemed at checkout. Amounts are in cents. The balance is kept on the card and every
-- movement is in the ledger. A card can be used on one order at a time; a cancelled order gives its amount back once.
CREATE TABLE gift_cards (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(20)  NOT NULL,
    initial_cents  INT UNSIGNED NOT NULL,
    balance_cents  INT UNSIGNED NOT NULL,
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gift_card_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gift_card_ledger (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id       INT UNSIGNED NOT NULL,
    kind          ENUM('issue','spend','restore') NOT NULL,
    amount_cents  INT NOT NULL,
    order_id      INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gift_order_kind (order_id, kind),
    KEY idx_gift_ledger_card (card_id),
    CONSTRAINT fk_gift_ledger_card FOREIGN KEY (card_id) REFERENCES gift_cards (id) ON DELETE CASCADE,
    CONSTRAINT fk_gift_ledger_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
