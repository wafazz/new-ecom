-- Several modules can be paid in one go: their invoices share a group_ref and one Billplz bill.
ALTER TABLE module_invoices
    ADD COLUMN group_ref CHAR(16) NULL AFTER module_key,
    DROP INDEX uq_module_invoices_bill,
    ADD KEY idx_module_invoices_bill (billplz_bill_id);
