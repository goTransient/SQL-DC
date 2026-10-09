-- SQL-DC schema for MySQL 5.7+ or MariaDB 10.2+.
-- Select an empty, dedicated SQL-DC database before running this file.
-- This schema-only script intentionally contains no IF NOT EXISTS or DROP statements:
-- existing table-name collisions must stop installation for review.

CREATE TABLE `households` (
    `household_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `address` TEXT NOT NULL,
    `group_number` INT NULL,
    `notes` TEXT NOT NULL,
    PRIMARY KEY (`household_id`),
    UNIQUE KEY `uq_households_code` (`code`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `residents` (
    `resident_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `household_id` INT UNSIGNED NOT NULL,
    `full_name` TEXT NOT NULL,
    `gender` VARCHAR(32) NOT NULL DEFAULT '',
    `dob` VARCHAR(10) NOT NULL DEFAULT '',
    `birth_year` INT NULL,
    `ethnicity` TEXT NOT NULL,
    `relation` VARCHAR(64) NOT NULL DEFAULT '',
    `cccd` TEXT NOT NULL,
    `phone` TEXT NOT NULL,
    `email` TEXT NOT NULL,
    `job` TEXT NOT NULL,
    `is_voter` TINYINT NOT NULL DEFAULT 0,
    `status` VARCHAR(64) NOT NULL DEFAULT 'Đang ở',
    `notes` TEXT NOT NULL,
    `source` TEXT NOT NULL,
    `check_note` TEXT NOT NULL,
    PRIMARY KEY (`resident_id`),
    UNIQUE KEY `uq_residents_code` (`code`),
    KEY `idx_residents_household` (`household_id`),
    CONSTRAINT `fk_residents_household`
        FOREIGN KEY (`household_id`) REFERENCES `households` (`household_id`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `associations` (
    `association_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` TEXT NOT NULL,
    `note` TEXT NOT NULL,
    PRIMARY KEY (`association_id`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `resident_associations` (
    `resident_id` INT UNSIGNED NOT NULL,
    `association_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`resident_id`, `association_id`),
    KEY `idx_resident_associations_association` (`association_id`),
    CONSTRAINT `fk_resident_associations_resident`
        FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_resident_associations_association`
        FOREIGN KEY (`association_id`) REFERENCES `associations` (`association_id`)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activities` (
    `activity_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` TEXT NOT NULL,
    `activity_date` VARCHAR(10) NOT NULL DEFAULT '',
    `end_date` VARCHAR(10) NULL,
    `amount_suggested` INT NOT NULL DEFAULT 0,
    `notes` TEXT NOT NULL,
    PRIMARY KEY (`activity_id`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `household_activities` (
    `household_id` INT UNSIGNED NOT NULL,
    `activity_id` INT UNSIGNED NOT NULL,
    `paid` TINYINT NOT NULL DEFAULT 0,
    `amount` INT NOT NULL DEFAULT 0,
    `notes` TEXT NOT NULL,
    PRIMARY KEY (`household_id`, `activity_id`),
    KEY `idx_household_activities_activity` (`activity_id`),
    CONSTRAINT `fk_household_activities_household`
        FOREIGN KEY (`household_id`) REFERENCES `households` (`household_id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_household_activities_activity`
        FOREIGN KEY (`activity_id`) REFERENCES `activities` (`activity_id`)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_users` (
    `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    `active` TINYINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login` DATETIME NULL,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_auth_users_email` (`email`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
