CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','staff','passenger') NOT NULL DEFAULT 'passenger',
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `email_verify_code` VARCHAR(10) DEFAULT NULL,
  `email_verify_expires` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `trips` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `departure` VARCHAR(150) NOT NULL,
  `destination` VARCHAR(150) NOT NULL,
  `departure_time` DATETIME NOT NULL,
  `arrival_time` DATETIME DEFAULT NULL,
  `status` ENUM('scheduled','active','ended','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `trip_id` INT NOT NULL,
  `passenger_id` INT NOT NULL,
  `sold_by` INT DEFAULT NULL,
  `ticket_code` VARCHAR(50) NOT NULL UNIQUE,
  `status` ENUM('valid','used','cancelled') NOT NULL DEFAULT 'valid',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`trip_id`) REFERENCES `trips`(`id`),
  FOREIGN KEY (`passenger_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`sold_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('site_name', 'KiviTickets'),
  ('tickets_per_trip', '50');
