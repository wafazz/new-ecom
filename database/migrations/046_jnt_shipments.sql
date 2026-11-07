-- J&T Express shipments have no NinjaVan service level or delivery window, so these columns may be empty.
-- carrier (already present) tells the two apart: 'ninjavan' or 'jnt'.
ALTER TABLE shipments
    MODIFY service_level VARCHAR(20) NULL,
    MODIFY delivery_date DATE NULL,
    MODIFY delivery_timeslot VARCHAR(11) NULL;
