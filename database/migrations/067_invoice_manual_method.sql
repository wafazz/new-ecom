-- Manual grants by a superadmin are recorded with the method 'manual'.
ALTER TABLE module_invoices MODIFY method ENUM('billplz','bank_receipt','manual') NULL;
