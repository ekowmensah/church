-- Add reversal tracking to payments and a log table
ALTER TABLE payments 
  ADD COLUMN reversal_requested_at DATETIME DEFAULT NULL,
  ADD COLUMN reversal_requested_by INT DEFAULT NULL,
  ADD COLUMN reversal_approved_at DATETIME DEFAULT NULL,
  ADD COLUMN reversal_approved_by INT DEFAULT NULL,
  ADD COLUMN reversal_undone_at DATETIME DEFAULT NULL,
  ADD COLUMN reversal_undone_by INT DEFAULT NULL;

CREATE TABLE IF NOT EXISTS payment_reversal_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  action ENUM('request','approve','undo') NOT NULL,
  actor_id INT NOT NULL,
  action_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (payment_id) REFERENCES payments(id),
  FOREIGN KEY (actor_id) REFERENCES users(id)
);
