-- Create adherents table for tracking member adherent status history
-- This table maintains an audit trail of when and why members were marked as adherents

CREATE TABLE IF NOT EXISTS `adherents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `reason` text NOT NULL COMMENT 'Reason for marking member as adherent',
  `date_became_adherent` date NOT NULL COMMENT 'Date when member became adherent',
  `marked_by` int(11) NOT NULL COMMENT 'User ID who marked the member as adherent',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_marked_by` (`marked_by`),
  KEY `idx_date_became_adherent` (`date_became_adherent`),
  CONSTRAINT `fk_adherents_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adherents_marked_by` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks adherent status history for members';

-- Add index for efficient queries
CREATE INDEX `idx_adherents_member_date` ON `adherents` (`member_id`, `date_became_adherent` DESC);
