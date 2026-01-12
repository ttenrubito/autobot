-- Migration: Create customer_profiles table
-- Date: 2026-01-12
-- Purpose: Store customer profile data from chat platforms (Facebook, LINE, etc.)

CREATE TABLE IF NOT EXISTS `customer_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` enum('facebook','line','web','instagram','manual') NOT NULL DEFAULT 'manual',
  `platform_user_id` varchar(255) DEFAULT NULL COMMENT 'FB PSID or LINE userId',
  `display_name` varchar(255) DEFAULT NULL,
  `avatar_url` text DEFAULT NULL,
  `profile_pic_url` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `first_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_active_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional platform-specific data',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_platform_user` (`platform`,`platform_user_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_platform` (`platform`),
  KEY `idx_last_active` (`last_active_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
