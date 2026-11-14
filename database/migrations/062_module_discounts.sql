-- Discounts on module prices. scope is 'global' (all priced modules) or one module key. A module's own discount
-- overrides the global one while both are active. Each discount ends at ends_at; expired rows are ignored.
CREATE TABLE module_discounts (
    scope       VARCHAR(40)   NOT NULL PRIMARY KEY,
    percent     DECIMAL(5,2)  NOT NULL,
    ends_at     DATETIME      NOT NULL,
    created_by  INT UNSIGNED  NULL,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
