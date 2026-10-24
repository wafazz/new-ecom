-- When the AWB label of a courier booking was first printed (shows the PRINTED badge on the order list).
ALTER TABLE shipments ADD COLUMN awb_printed_at DATETIME NULL AFTER updated_at;
