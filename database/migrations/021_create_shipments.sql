-- One ACTIVE shipment per order: `active` is 1 while live and NULL once cancelled, so UNIQUE(order_id, active)
-- allows any number of cancelled ones but only one live one.
CREATE TABLE shipments (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id                  INT UNSIGNED NOT NULL,
    carrier                   VARCHAR(20) NOT NULL DEFAULT 'ninjavan',
    tracking_number           VARCHAR(60) NOT NULL,
    requested_tracking_number VARCHAR(60) NULL,
    service_level             VARCHAR(20) NOT NULL,
    weight_kg                 DECIMAL(6,2) NOT NULL,
    cod_amount                DECIMAL(10,2) NULL,
    pickup_required           TINYINT(1) NOT NULL DEFAULT 0,
    pickup_date               DATE NULL,
    pickup_timeslot           VARCHAR(11) NULL,
    delivery_date             DATE NOT NULL,
    delivery_timeslot         VARCHAR(11) NOT NULL,
    status                    VARCHAR(60) NOT NULL DEFAULT 'Created',
    last_event_at             DATETIME NULL,
    active                    TINYINT NULL DEFAULT 1,
    created_by                INT UNSIGNED NULL,
    cancelled_at              DATETIME NULL,
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipments_tracking (tracking_number),
    UNIQUE KEY uq_shipments_active (order_id, active),
    CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_shipments_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Courier tracking events (webhook). event_uid dedupes retries.
CREATE TABLE shipment_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_id     INT UNSIGNED NOT NULL,
    event_uid       VARCHAR(64) NULL,
    status          VARCHAR(60) NOT NULL,
    previous_status VARCHAR(60) NULL,
    comments        VARCHAR(255) NULL,
    occurred_at     DATETIME NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipment_event_uid (shipment_id, event_uid),
    KEY idx_shipment_events (shipment_id, occurred_at),
    CONSTRAINT fk_shipment_events_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
