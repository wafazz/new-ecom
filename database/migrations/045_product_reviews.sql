-- Product reviews. A member can review a product once, and only after a completed order that contains it.
-- Reviews go live straight away; staff can hide or delete them. order_id is the completed order the review was made from.
CREATE TABLE product_reviews (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id  INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    order_id    INT UNSIGNED NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    title       VARCHAR(120) NULL,
    body        TEXT         NOT NULL,
    status      ENUM('approved','hidden') NOT NULL DEFAULT 'approved',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_user_product (user_id, product_id),
    KEY idx_reviews_product (product_id, status),
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
