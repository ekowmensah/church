CREATE TABLE member_feedback_thread (
  id INT AUTO_INCREMENT PRIMARY KEY,
  feedback_id INT,
  recipient_type ENUM('member','user') NOT NULL,
  recipient_id INT NOT NULL,
  sender_type ENUM('member','user') NOT NULL,
  sender_id INT NOT NULL,
  message TEXT NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (feedback_id) REFERENCES member_feedback(id)
);

-- Optionally, migrate existing feedback.response as the first admin message in the thread.
