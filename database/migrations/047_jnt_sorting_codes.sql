-- J&T Express returns sorting codes with each waybill; the AWB shows them in the destination box.
ALTER TABLE shipments
    ADD COLUMN sorting_code VARCHAR(20) NULL AFTER tracking_number,
    ADD COLUMN third_sorting_code VARCHAR(10) NULL AFTER sorting_code;
