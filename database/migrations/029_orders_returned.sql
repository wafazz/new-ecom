-- A shipped parcel the courier sent back to the seller. Returned is final, like cancelled.
ALTER TABLE orders MODIFY status ENUM('pending','processing','shipped','completed','cancelled','returned') NOT NULL DEFAULT 'pending';
