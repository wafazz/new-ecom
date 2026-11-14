-- Subscriptions: a shopper's regular order of one product. Each renewal is a normal order, paid by hand (the shopper
-- pays the order). A cycle that is still unpaid pauses the subscription until it is paid. subscription_runs makes each
-- due date produce exactly one order, even if the job runs twice.
CREATE TABLE subscriptions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    address_id      INT UNSIGNED NULL,
    qty             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    cadence         ENUM('monthly','fortnightly') NOT NULL,
    payment_method  VARCHAR(30) NOT NULL,
    next_due        DATE NOT NULL,
    status          ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
    pause_reason    VARCHAR(190) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_subscriptions_due (status, next_due),
    KEY idx_subscriptions_user (user_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_address FOREIGN KEY (address_id) REFERENCES addresses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscription_runs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id  INT UNSIGNED NOT NULL,
    due_date         DATE NOT NULL,
    order_id         INT UNSIGNED NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subscription_run (subscription_id, due_date),
    CONSTRAINT fk_runs_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE,
    CONSTRAINT fk_runs_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
