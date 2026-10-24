ALTER TABLE categories
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER slug,
    ADD KEY idx_categories_parent (parent_id),
    ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL;
