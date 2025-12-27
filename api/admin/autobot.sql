-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Dec 14, 2025 at 11:16 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `autobot`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `resource_type` varchar(50) DEFAULT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `resource_type`, `resource_id`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'login', NULL, NULL, NULL, '127.0.0.1', 'curl/7.68.0', '2025-12-10 01:30:40'),
(2, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 01:32:38'),
(3, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 01:48:11'),
(4, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 02:01:18'),
(5, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 02:07:06'),
(6, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 02:11:52'),
(7, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 02:12:28'),
(8, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 02:26:44'),
(9, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 02:54:04'),
(10, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 03:09:00'),
(11, 1, 'login', NULL, NULL, NULL, '127.0.0.1', 'curl/7.68.0', '2025-12-10 05:25:44'),
(12, 1, 'login', NULL, NULL, NULL, '127.0.0.1', 'curl/7.68.0', '2025-12-10 05:25:44'),
(13, 2, 'login', NULL, NULL, NULL, '127.0.0.1', 'curl/7.68.0', '2025-12-10 05:39:04'),
(14, 2, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 05:39:32'),
(15, 2, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 05:48:55'),
(16, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 05:49:27'),
(17, 2, 'login', NULL, NULL, NULL, '127.0.0.1', 'curl/7.68.0', '2025-12-10 07:40:47'),
(18, 1, 'login', NULL, NULL, NULL, '127.0.0.1', 'curl/7.68.0', '2025-12-10 11:27:05'),
(19, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-10 13:07:42'),
(20, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 02:46:47'),
(21, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 12:37:43'),
(22, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 14:24:59'),
(23, 1, 'add_payment_method', 'payment_method', 2, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 14:25:38'),
(24, 1, 'add_payment_method', 'payment_method', 3, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 14:26:26'),
(25, 1, 'add_payment_method', 'payment_method', 4, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 14:41:31'),
(26, 1, 'set_default_payment', 'payment_method', 3, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 14:54:59'),
(27, 1, 'remove_payment_method', 'payment_method', 2, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 14:56:51'),
(28, 1, 'remove_payment_method', 'payment_method', 4, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 14:56:58'),
(29, 1, 'remove_payment_method', 'payment_method', 3, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 15:17:17'),
(30, 1, 'add_payment_method', 'payment_method', 5, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-11 16:39:43'),
(31, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 03:13:32'),
(32, 1, 'add_payment_method', 'payment_method', 6, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 03:26:30'),
(33, 1, 'remove_payment_method', 'payment_method', 6, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 03:26:38'),
(34, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 13:24:24'),
(35, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 14:48:46'),
(36, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 15:05:16'),
(37, 1, 'remove_payment_method', 'payment_method', 5, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 15:07:26'),
(38, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 15:25:37'),
(39, 1, 'add_payment_method', 'payment_method', 7, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 15:34:30'),
(40, 3, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 16:19:19'),
(41, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 16:46:43'),
(42, 3, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 16:48:05'),
(43, 3, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 16:51:24'),
(44, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-12 23:51:55'),
(45, 3, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-13 00:04:13'),
(46, 3, 'add_payment_method', 'payment_method', 8, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-13 00:04:50'),
(47, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-13 00:06:26'),
(48, 1, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-13 13:55:11'),
(49, 3, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-13 14:14:51'),
(50, 3, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-14 00:28:37'),
(51, 3, 'login', NULL, NULL, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-14 02:38:17'),
(52, 3, 'set_default_payment', 'payment_method', 8, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-14 02:41:06'),
(53, 3, 'remove_payment_method', 'payment_method', 8, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-14 03:48:13'),
(54, 3, 'add_payment_method', 'payment_method', 9, NULL, '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36', '2025-12-14 03:48:33');

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `role` enum('super_admin','admin','support') DEFAULT 'admin',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password_hash`, `full_name`, `email`, `role`, `is_active`, `created_at`, `last_login`) VALUES
(1, 'admin', '$2y$10$Bdyv4jXFlNxjYrQLMGw5v.o2b/nmJLMcWC7cmWJcRQpDGs7eX4vjW', 'System Administrator', 'admin@aiautomation.com', 'super_admin', 1, '2025-12-10 03:13:04', '2025-12-14 04:10:56'),
(2, 'testadmin', '$2y$10$nJn8.XDIxSUXZ/MEDRSn2edlh66efZpUB5hDaWz5aPqWiqdr.mCKW', 'Test Admin', 'testadmin@example.com', 'admin', 1, '2025-12-10 05:36:58', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `name` varchar(255) DEFAULT 'Default API Key',
  `is_active` tinyint(1) DEFAULT 1,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `api_keys`
--

INSERT INTO `api_keys` (`id`, `user_id`, `api_key`, `name`, `is_active`, `last_used_at`, `created_at`, `expires_at`) VALUES
(1, 1, 'ak_db070bf99d1762c5dc4cdabeb453554b', 'n8n Integration Key', 1, NULL, '2025-12-10 03:13:04', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `api_service_config`
--

CREATE TABLE `api_service_config` (
  `id` int(11) NOT NULL,
  `service_code` varchar(50) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `rate_limit_per_minute` int(11) DEFAULT 60,
  `rate_limit_per_day` int(11) DEFAULT 10000,
  `cost_per_request` decimal(10,4) DEFAULT 0.0010,
  `google_api_endpoint` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `api_service_config`
--

INSERT INTO `api_service_config` (`id`, `service_code`, `service_name`, `description`, `is_enabled`, `rate_limit_per_minute`, `rate_limit_per_day`, `cost_per_request`, `google_api_endpoint`, `created_at`, `updated_at`) VALUES
(1, 'google_vision_labels', 'Google Vision - Label Detection', 'Detect and extract labels/tags from images', 1, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate', '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(2, 'google_vision_text', 'Google Vision - Text Detection (OCR)', 'Extract text from images using OCR', 1, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate', '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(3, 'google_vision_faces', 'Google Vision - Face Detection', 'Detect faces in images', 1, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate', '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(4, 'google_vision_objects', 'Google Vision - Object Detection', 'Detect and localize objects in images', 1, 60, 5000, 0.0015, 'https://vision.googleapis.com/v1/images:annotate', '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(5, 'google_nl_sentiment', 'Google Natural Language - Sentiment Analysis', 'Analyze sentiment of text', 1, 60, 10000, 0.0010, 'https://language.googleapis.com/v1/documents:analyzeSentiment', '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(6, 'google_nl_entities', 'Google Natural Language - Entity Extraction', 'Extract entities from text', 1, 60, 10000, 0.0010, 'https://language.googleapis.com/v1/documents:analyzeEntities', '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(7, 'google_nl_syntax', 'Google Natural Language - Syntax Analysis', 'Analyze syntax and parts of speech', 1, 60, 10000, 0.0010, 'https://language.googleapis.com/v1/documents:analyzeSyntax', '2025-12-10 03:13:04', '2025-12-10 03:13:04');

-- --------------------------------------------------------

--
-- Table structure for table `api_usage_logs`
--

CREATE TABLE `api_usage_logs` (
  `id` bigint(20) NOT NULL,
  `customer_service_id` int(11) NOT NULL,
  `api_type` varchar(50) NOT NULL COMMENT 'google_vision, google_nl',
  `endpoint` varchar(255) NOT NULL,
  `request_count` int(11) DEFAULT 1,
  `request_size` int(11) DEFAULT NULL COMMENT 'bytes',
  `response_time` int(11) DEFAULT NULL COMMENT 'milliseconds',
  `status_code` int(11) DEFAULT NULL,
  `cost` decimal(10,4) DEFAULT NULL COMMENT 'Cost per request',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `api_usage_logs`
--

INSERT INTO `api_usage_logs` (`id`, `customer_service_id`, `api_type`, `endpoint`, `request_count`, `request_size`, `response_time`, `status_code`, `cost`, `metadata`, `created_at`) VALUES
(1, 3, 'google_vision', 'labels', 15, NULL, 250, 200, 7.5000, NULL, '2025-12-10 02:34:34'),
(2, 3, 'google_vision', 'text_detection', 8, NULL, 320, 200, 4.0000, NULL, '2025-12-10 02:34:34'),
(3, 3, 'google_vision', 'face_detection', 5, NULL, 280, 200, 2.5000, NULL, '2025-12-10 02:34:34'),
(4, 3, 'google_vision', 'labels', 12, NULL, 240, 200, 6.0000, NULL, '2025-12-09 02:34:34'),
(5, 3, 'google_vision', 'text_detection', 10, NULL, 310, 200, 5.0000, NULL, '2025-12-09 02:34:34'),
(6, 3, 'google_vision', 'labels', 18, NULL, 260, 200, 9.0000, NULL, '2025-12-08 02:34:34'),
(7, 3, 'google_vision', 'face_detection', 7, NULL, 290, 200, 3.5000, NULL, '2025-12-08 02:34:34'),
(8, 3, 'google_vision', 'labels', 20, NULL, 245, 200, 10.0000, NULL, '2025-12-07 02:34:34'),
(9, 3, 'google_vision', 'text_detection', 6, NULL, 300, 200, 3.0000, NULL, '2025-12-07 02:34:34'),
(10, 3, 'google_vision', 'labels', 14, NULL, 255, 200, 7.0000, NULL, '2025-12-06 02:34:34'),
(11, 3, 'google_vision', 'text_detection', 9, NULL, 315, 200, 4.5000, NULL, '2025-12-05 02:34:34'),
(12, 3, 'google_vision', 'face_detection', 4, NULL, 275, 200, 2.0000, NULL, '2025-12-05 02:34:34'),
(13, 3, 'google_vision', 'labels', 11, NULL, 250, 200, 5.5000, NULL, '2025-12-04 02:34:34'),
(14, 4, 'google_nl', 'sentiment', 25, NULL, 180, 200, 7.5000, NULL, '2025-12-10 02:34:34'),
(15, 4, 'google_nl', 'entities', 12, NULL, 200, 200, 3.6000, NULL, '2025-12-10 02:34:34'),
(16, 4, 'google_nl', 'sentiment', 30, NULL, 175, 200, 9.0000, NULL, '2025-12-09 02:34:34'),
(17, 4, 'google_nl', 'entities', 15, NULL, 195, 200, 4.5000, NULL, '2025-12-09 02:34:34'),
(18, 4, 'google_nl', 'sentiment', 28, NULL, 185, 200, 8.4000, NULL, '2025-12-08 02:34:34'),
(19, 4, 'google_nl', 'entities', 10, NULL, 210, 200, 3.0000, NULL, '2025-12-08 02:34:34'),
(20, 4, 'google_nl', 'sentiment', 22, NULL, 190, 200, 6.6000, NULL, '2025-12-07 02:34:34'),
(21, 4, 'google_nl', 'sentiment', 35, NULL, 170, 200, 10.5000, NULL, '2025-12-06 02:34:34'),
(22, 4, 'google_nl', 'entities', 18, NULL, 205, 200, 5.4000, NULL, '2025-12-06 02:34:34'),
(23, 4, 'google_nl', 'sentiment', 27, NULL, 180, 200, 8.1000, NULL, '2025-12-05 02:34:34'),
(24, 4, 'google_nl', 'sentiment', 20, NULL, 185, 200, 6.0000, NULL, '2025-12-04 02:34:34'),
(25, 4, 'google_nl', 'entities', 8, NULL, 200, 200, 2.4000, NULL, '2025-12-04 02:34:34');

-- --------------------------------------------------------

--
-- Table structure for table `bot_chat_logs`
--

CREATE TABLE `bot_chat_logs` (
  `id` bigint(20) NOT NULL,
  `customer_service_id` int(11) NOT NULL,
  `platform_user_id` varchar(255) NOT NULL,
  `direction` enum('incoming','outgoing') NOT NULL,
  `message_type` varchar(50) NOT NULL COMMENT 'text, image, video, etc.',
  `message_content` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bot_chat_logs`
--

INSERT INTO `bot_chat_logs` (`id`, `customer_service_id`, `platform_user_id`, `direction`, `message_type`, `message_content`, `metadata`, `created_at`) VALUES
(27, 1, 'user_001', 'incoming', 'text', 'สินค้ามีสีอะไรบ้าง', NULL, '2025-12-09 04:55:29'),
(28, 1, 'user_001', 'outgoing', 'text', 'เรามีสีดำ สีขาว สีเทา ค่ะ', NULL, '2025-12-09 04:55:29'),
(29, 1, 'user_002', 'incoming', 'text', 'ราคาเท่าไหร่', NULL, '2025-12-08 04:55:29'),
(30, 1, 'user_002', 'outgoing', 'text', 'ราคา 1,990 บาท ค่ะ', NULL, '2025-12-08 04:55:29'),
(31, 1, 'user_003', 'incoming', 'text', 'มีของพร้อมส่งไหม', NULL, '2025-12-07 04:55:29'),
(32, 1, 'user_003', 'outgoing', 'text', 'มีพร้อมส่งค่ะ', NULL, '2025-12-07 04:55:29'),
(33, 1, 'user_004', 'incoming', 'text', 'ส่งฟรีไหม', NULL, '2025-12-06 04:55:29'),
(34, 1, 'user_004', 'outgoing', 'text', 'ส่งฟรีทั่วประเทศค่ะ', NULL, '2025-12-06 04:55:29'),
(35, 2, 'line_001', 'incoming', 'text', 'ติดตามพัสดุ', NULL, '2025-12-09 04:55:29'),
(36, 2, 'line_001', 'outgoing', 'text', 'กรุณาส่งเลขพัสดุค่ะ', NULL, '2025-12-09 04:55:29'),
(37, 2, 'line_002', 'incoming', 'text', 'เปลี่ยนที่อยู่', NULL, '2025-12-08 04:55:29'),
(38, 2, 'line_002', 'outgoing', 'text', 'กรุณาส่งที่อยู่ใหม่ค่ะ', NULL, '2025-12-08 04:55:29'),
(39, 2, 'line_003', 'incoming', 'text', 'สินค้าชำรุด', NULL, '2025-12-07 04:55:29'),
(40, 2, 'line_003', 'outgoing', 'text', 'ขออภัยค่ะ รบกวนส่งรูปค่ะ', NULL, '2025-12-07 04:55:29'),
(41, 2, 'line_004', 'incoming', 'text', 'ขอใบกำกับภาษี', NULL, '2025-12-06 04:55:29'),
(42, 2, 'line_004', 'outgoing', 'text', 'ส่งทาง email ค่ะ', NULL, '2025-12-06 04:55:29');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('user','assistant','system') NOT NULL,
  `text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_sessions`
--

CREATE TABLE `chat_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED NOT NULL,
  `external_user_id` varchar(255) NOT NULL,
  `last_intent` varchar(100) DEFAULT NULL,
  `last_slots_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`last_slots_json`)),
  `summary` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_api_access`
--

CREATE TABLE `customer_api_access` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_code` varchar(50) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `daily_limit` int(11) DEFAULT NULL COMMENT 'NULL means no limit',
  `monthly_limit` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_api_access`
--

INSERT INTO `customer_api_access` (`id`, `user_id`, `service_code`, `is_enabled`, `daily_limit`, `monthly_limit`, `created_at`, `updated_at`) VALUES
(1, 1, 'google_vision_labels', 1, 1000, 30000, '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(2, 1, 'google_vision_text', 1, 1000, 30000, '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(3, 1, 'google_vision_faces', 1, 500, 15000, '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(4, 1, 'google_vision_objects', 1, 500, 15000, '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(5, 1, 'google_nl_sentiment', 1, 2000, 60000, '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(6, 1, 'google_nl_entities', 1, 2000, 60000, '2025-12-10 03:13:04', '2025-12-10 03:13:04'),
(7, 1, 'google_nl_syntax', 1, 1000, 30000, '2025-12-10 03:13:04', '2025-12-10 03:13:04');

-- --------------------------------------------------------

--
-- Table structure for table `customer_bot_profiles`
--

CREATE TABLE `customer_bot_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `handler_key` varchar(100) NOT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_bot_profiles`
--

INSERT INTO `customer_bot_profiles` (`id`, `user_id`, `name`, `handler_key`, `config`, `is_default`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
(1, 3, 'ขายสินค้าแบรนด์แนมมือสอง', 'router_v1', '{\"routing_policy\":{\"rules\":[{\"when_any\":[\"\\u0e21\\u0e35\\u0e02\\u0e2d\\u0e07\\u0e44\\u0e2b\\u0e21\",\"\\u0e02\\u0e2d\\u0e07\\u0e21\\u0e35\\u0e44\\u0e2b\\u0e21\",\"\\u0e2a\\u0e15\\u0e47\\u0e2d\\u0e01\",\"\\u0e1e\\u0e23\\u0e49\\u0e2d\\u0e21\\u0e2a\\u0e48\\u0e07\\u0e44\\u0e2b\\u0e21\"],\"route_to\":\"product_availability\"},{\"when_any\":[\"\\u0e1c\\u0e48\\u0e2d\\u0e19\",\"0%\",\"\\u0e41\\u0e1a\\u0e48\\u0e07\\u0e08\\u0e48\\u0e32\\u0e22\"],\"route_to\":\"installment_calc\"},{\"when_any\":[\"\\u0e08\\u0e2d\\u0e07\\u0e04\\u0e34\\u0e27\",\"\\u0e19\\u0e31\\u0e14\\u0e23\\u0e31\\u0e1a\\u0e2b\\u0e19\\u0e49\\u0e32\\u0e23\\u0e49\\u0e32\\u0e19\"],\"route_to\":\"booking\"}]},\"response_templates\":{\"greeting\":\"\\u0e2a\\u0e27\\u0e31\\u0e2a\\u0e14\\u0e35\\u0e04\\u0e23\\u0e31\\u0e1a \\u0e04\\u0e38\\u0e13\\u0e25\\u0e39\\u0e01\\u0e04\\u0e49\\u0e32 \\u0e2a\\u0e19\\u0e43\\u0e08\\u0e2a\\u0e34\\u0e19\\u0e04\\u0e49\\u0e32\\u0e0a\\u0e34\\u0e49\\u0e19\\u0e44\\u0e2b\\u0e19\\u0e04\\u0e48\\u0e30\",\"fallback\":\"\\u0e22\\u0e31\\u0e07\\u0e44\\u0e07\\u0e19\\u0e30\\u0e04\\u0e30\"}}', 1, 1, 0, '2025-12-13 03:23:25', '2025-12-13 03:54:30');

-- --------------------------------------------------------

--
-- Table structure for table `customer_channels`
--

CREATE TABLE `customer_channels` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(50) NOT NULL,
  `inbound_api_key` varchar(64) NOT NULL,
  `bot_profile_id` int(11) DEFAULT NULL,
  `status` enum('active','paused','disabled') DEFAULT 'active',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_channels`
--

INSERT INTO `customer_channels` (`id`, `user_id`, `name`, `type`, `inbound_api_key`, `bot_profile_id`, `status`, `config`, `is_deleted`, `created_at`, `updated_at`) VALUES
(1, 3, 'ทดสอบ facebook 1', 'facebook', 'ch_vpxp6tj2mj3lbfco', NULL, 'active', NULL, 0, '2025-12-13 01:01:39', '2025-12-13 01:01:39');

-- --------------------------------------------------------

--
-- Table structure for table `customer_integrations`
--

CREATE TABLE `customer_integrations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `api_key` text DEFAULT NULL,
  `credentials` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`credentials`)),
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `is_active` tinyint(1) DEFAULT 1,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_integrations`
--

INSERT INTO `customer_integrations` (`id`, `user_id`, `provider`, `name`, `api_key`, `credentials`, `config`, `is_active`, `is_deleted`, `created_at`, `updated_at`) VALUES
(1, 3, 'google_nlp', '', 'sxxxxxxxxxxx', NULL, '{\"language\":\"th\"}', 1, 0, '2025-12-13 02:16:26', '2025-12-13 02:16:37'),
(2, 3, 'custom', '', NULL, NULL, '{\"type\":\"accounting_ocr_intake\",\"endpoint\":\"https:\\/\\/boxdesign.in.th\\/postaccountfile.php\",\"method\":\"POST\",\"timeout\":10,\"payload_map\":{\"inputA\":\"ocr_text\",\"inputB\":\"file_url\"}}', 1, 0, '2025-12-13 02:59:46', '2025-12-13 02:59:46');

-- --------------------------------------------------------

--
-- Table structure for table `customer_services`
--

CREATE TABLE `customer_services` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_type_id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL COMMENT 'Custom name given by customer',
  `platform` varchar(50) DEFAULT NULL COMMENT 'facebook, line, etc.',
  `api_key` varchar(255) DEFAULT NULL,
  `webhook_url` text DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Service-specific configuration' CHECK (json_valid(`config`)),
  `status` enum('active','paused','error') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_services`
--

INSERT INTO `customer_services` (`id`, `user_id`, `service_type_id`, `service_name`, `platform`, `api_key`, `webhook_url`, `config`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Facebook Bot - สนับสนุนลูกค้า', 'facebook', 'fb_e5b3d5152f336fc694249c3fe9241611', NULL, NULL, 'active', '2025-12-10 01:29:16', '2025-12-10 01:29:16'),
(2, 1, 2, 'LINE Bot - แจ้งข่าวสาร', 'line', 'line_81c9c5531689eb5cd5319cc1d4409cb0', NULL, NULL, 'active', '2025-12-10 01:29:16', '2025-12-10 01:29:16'),
(3, 1, 3, 'Vision API - ตรวจสอบภาพ', NULL, 'gv_c28928207c409772dbed98518e7066ef', NULL, NULL, 'active', '2025-12-10 01:29:16', '2025-12-10 01:29:16'),
(4, 1, 4, 'NL API - วิเคราะห์ความคิดเห็น', NULL, 'gnl_aad9c43f90cfd8f335ebf649098307dc', NULL, NULL, 'active', '2025-12-10 01:29:16', '2025-12-10 01:29:16');

-- --------------------------------------------------------

--
-- Table structure for table `gateway_message_events`
--

CREATE TABLE `gateway_message_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED NOT NULL,
  `external_event_id` varchar(191) NOT NULL,
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload`)),
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gateway_message_events`
--

INSERT INTO `gateway_message_events` (`id`, `channel_id`, `external_event_id`, `response_payload`, `created_at`) VALUES
(1, 1, 'test-event-001', '{\"reply_text\":\"\\u0e22\\u0e31\\u0e07\\u0e44\\u0e07\\u0e19\\u0e30\\u0e04\\u0e30\",\"actions\":[],\"meta\":{\"handler\":\"router_v1\",\"route\":null,\"nlp\":{\"error\":\"missing_api_key\"},\"reason\":\"fallback_with_google_nlp\"}}', '2025-12-13 10:44:46');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'THB',
  `status` enum('pending','paid','failed','cancelled') DEFAULT 'pending',
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `invoice_number`, `user_id`, `subscription_id`, `amount`, `tax`, `total`, `currency`, `status`, `billing_period_start`, `billing_period_end`, `due_date`, `paid_at`, `created_at`) VALUES
(1, 'INV-202510-001', 1, NULL, 990.00, 69.30, 1059.30, 'THB', 'paid', NULL, NULL, '2025-10-10', '2025-10-11 04:55:29', '2025-10-10 04:55:29'),
(5, 'INV-20251214-00003-8', 3, 8, 2500.00, 0.00, 2500.00, 'THB', 'paid', '2025-12-13', '2025-12-14', '2025-12-21', '2025-12-14 04:09:50', '2025-12-14 04:09:48');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`id`, `invoice_id`, `description`, `quantity`, `unit_price`, `amount`) VALUES
(1, 1, 'Pro Plan - Monthly', 1, 990.0000, 990.00);

-- --------------------------------------------------------

--
-- Table structure for table `message_buffers`
--

CREATE TABLE `message_buffers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED NOT NULL,
  `external_user_id` varchar(191) NOT NULL,
  `buffer_text` text NOT NULL,
  `first_message_at` datetime NOT NULL,
  `last_message_at` datetime NOT NULL,
  `status` enum('pending','flushed') NOT NULL DEFAULT 'pending',
  `last_event_id` varchar(191) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `message_buffers`
--

INSERT INTO `message_buffers` (`id`, `channel_id`, `external_user_id`, `buffer_text`, `first_message_at`, `last_message_at`, `status`, `last_event_id`) VALUES
(1, 1, 'test-user-1', 'สอบถามสินค้า iphone ครับ\nสอบถามสินค้า iphone ครับ', '2025-12-13 10:42:27', '2025-12-13 10:44:45', 'flushed', 'test-event-001');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `omise_customer_id` varchar(255) DEFAULT NULL,
  `omise_card_id` varchar(255) DEFAULT NULL,
  `card_brand` varchar(50) DEFAULT NULL,
  `card_last4` varchar(4) DEFAULT NULL,
  `card_expiry_month` int(11) DEFAULT NULL,
  `card_expiry_year` int(11) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `user_id`, `omise_customer_id`, `omise_card_id`, `card_brand`, `card_last4`, `card_expiry_month`, `card_expiry_year`, `is_default`, `created_at`, `updated_at`) VALUES
(1, 2, 'cust_test_65zqo5s92srvzr1qe20', 'card_test_65zqo5owuj308p0hin7', 'Visa', '4242', 12, 2025, 1, '2025-12-10 07:55:41', '2025-12-10 07:55:41'),
(7, 1, 'cust_test_660nbheduft90rzaigd', 'card_test_660nbh9upk0sqfv50cs', 'Visa', '4242', 12, 2030, 0, '2025-12-12 15:34:30', '2025-12-12 15:34:30'),
(9, 3, 'cust_test_6618kscwt3jlh10y2ni', 'card_test_6618ks9a70iksw8kxuj', 'Visa', '4242', 12, 2030, 1, '2025-12-14 03:48:33', '2025-12-14 03:48:33');

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `identifier` varchar(255) NOT NULL COMMENT 'IP address, username, or API key',
  `action` varchar(50) NOT NULL COMMENT 'login, register, api_call, etc.',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional context' CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `identifier`, `action`, `metadata`, `created_at`) VALUES
(1, '::1', 'admin_login', '{\"username\":\"admin\",\"success\":false}', '2025-12-10 09:05:01'),
(2, '::1', 'admin_login', '{\"username\":\"admin\",\"success\":false}', '2025-12-10 09:05:07'),
(3, '::1', 'admin_login', '{\"username\":\"admin\",\"success\":false}', '2025-12-10 09:05:10');

-- --------------------------------------------------------

--
-- Table structure for table `request_metrics`
--

CREATE TABLE `request_metrics` (
  `id` bigint(20) NOT NULL,
  `request_id` varchar(64) NOT NULL,
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(10) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `api_key_id` int(11) DEFAULT NULL,
  `http_status` int(11) NOT NULL,
  `duration_ms` decimal(10,2) NOT NULL,
  `request_size` int(11) DEFAULT NULL COMMENT 'Bytes',
  `response_size` int(11) DEFAULT NULL COMMENT 'Bytes',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_types`
--

CREATE TABLE `service_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(10,2) NOT NULL,
  `billing_unit` varchar(50) NOT NULL COMMENT 'per month, per request, etc.',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_types`
--

INSERT INTO `service_types` (`id`, `name`, `code`, `description`, `base_price`, `billing_unit`, `is_active`, `created_at`) VALUES
(1, 'Facebook Chatbot', 'facebook_bot', 'AI Chatbot สำหรับ Facebook Messenger', 1500.00, 'per month', 1, '2025-12-10 01:29:16'),
(2, 'LINE Chatbot', 'line_bot', 'AI Chatbot สำหรับ LINE Official Account', 1500.00, 'per month', 1, '2025-12-10 01:29:16'),
(3, 'Google Vision API', 'google_vision', 'Image Analysis & Recognition', 0.50, 'per request', 1, '2025-12-10 01:29:16'),
(4, 'Google Natural Language API', 'google_nl', 'Text Analysis & Sentiment Detection', 0.30, 'per request', 1, '2025-12-10 01:29:16');

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `status` enum('trial','active','paused','cancelled','expired') DEFAULT 'trial',
  `current_period_start` date NOT NULL,
  `current_period_end` date NOT NULL,
  `next_billing_date` date DEFAULT NULL,
  `auto_renew` tinyint(1) DEFAULT 1,
  `trial_end_date` date DEFAULT NULL,
  `trial_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cancelled_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `user_id`, `plan_id`, `status`, `current_period_start`, `current_period_end`, `next_billing_date`, `auto_renew`, `trial_end_date`, `trial_used`, `created_at`, `updated_at`, `cancelled_at`) VALUES
(1, 1, 2, 'active', '2025-11-25', '2025-12-25', NULL, 1, NULL, 0, '2025-12-10 01:29:16', '2025-12-10 01:29:16', NULL),
(2, 3, 3, 'cancelled', '2025-12-12', '2026-01-12', NULL, 1, NULL, 0, '2025-12-12 16:18:13', '2025-12-13 00:03:52', '2025-12-13 00:03:52'),
(4, 3, 1, 'cancelled', '2025-12-14', '2025-12-15', '2025-12-15', 1, NULL, 0, '2025-12-14 02:14:35', '2025-12-14 02:15:00', '2025-12-14 02:15:00'),
(5, 3, 2, 'cancelled', '2025-12-14', '2026-01-13', '2026-01-13', 1, NULL, 0, '2025-12-14 02:15:00', '2025-12-14 02:15:06', '2025-12-14 02:15:06'),
(7, 3, 1, 'paused', '2025-12-13', '2025-12-14', '2025-12-14', 1, NULL, 0, '2025-12-14 03:49:01', '2025-12-14 04:04:56', NULL),
(8, 3, 1, 'active', '2025-12-14', '2025-12-15', '2025-12-16', 1, NULL, 0, '2025-12-14 04:08:38', '2025-12-14 04:09:50', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `monthly_price` decimal(10,2) NOT NULL,
  `included_requests` int(11) DEFAULT NULL COMMENT 'Free requests per month',
  `overage_rate` decimal(10,4) DEFAULT NULL COMMENT 'Price per request over limit',
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `billing_period_days` int(11) NOT NULL DEFAULT 30
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscription_plans`
--

INSERT INTO `subscription_plans` (`id`, `name`, `description`, `monthly_price`, `included_requests`, `overage_rate`, `features`, `is_active`, `created_at`, `billing_period_days`) VALUES
(1, 'Starter', 'เหมาะสำหรับธุรกิจขนาดเล็ก', 2500.00, 1000, 0.5000, '[]', 1, '2025-12-10 01:29:16', 1),
(2, 'Professional', 'เหมาะสำหรับธุรกิจขนาดกลาง', 5000.00, 5000, 0.4000, '[\"3 Bots\", \"5000 API Requests\", \"Priority Support\", \"Analytics Dashboard\"]', 1, '2025-12-10 01:29:16', 30),
(3, 'Enterprise', 'เหมาะสำหรับองค์กรขนาดใหญ่', 10000.00, 20000, 0.3000, '[\"Unlimited Bots\", \"20000 API Requests\", \"24/7 Support\", \"Custom Integration\", \"Dedicated Account Manager\"]', 1, '2025-12-10 01:29:16', 30);

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_method_id` int(11) DEFAULT NULL,
  `omise_charge_id` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'THB',
  `status` enum('pending','successful','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `invoice_id`, `payment_method_id`, `omise_charge_id`, `amount`, `currency`, `status`, `error_message`, `metadata`, `created_at`) VALUES
(1, 5, NULL, 'chrg_test_6618s9yn7jut7x34blo', 2500.00, 'THB', 'successful', NULL, '{\"subscription_id\":8,\"user_id\":3,\"plan_id\":1,\"invoice_id\":\"5\"}', '2025-12-14 04:09:50');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `status` enum('active','suspended','cancelled') DEFAULT 'active',
  `trial_start_date` timestamp NULL DEFAULT NULL,
  `trial_days_remaining` int(11) DEFAULT 7
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `full_name`, `phone`, `company_name`, `created_at`, `updated_at`, `last_login`, `status`, `trial_start_date`, `trial_days_remaining`) VALUES
(1, 'demo@aiautomation.com', '$2y$10$MlIAeGJKaRfm28dFrrc6r.EQmQtzkvefTiPKIyG2PtDEUFNLC0XBi', 'Demo User', '0812345678', 'Demo Company Ltd.', '2025-12-10 01:29:16', '2025-12-13 13:55:11', '2025-12-13 13:55:11', 'active', NULL, 7),
(2, 'test@example.com', '$2y$10$vINk0dFopx0RfPCHYy1wJ.tmHolEXECROp3jM3vEh4/GXigm8L5g.', 'Test User', NULL, NULL, '2025-12-10 05:36:58', '2025-12-10 07:40:47', '2025-12-10 07:40:47', 'active', NULL, 7),
(3, 'jack@gmail.com', '$2y$10$.DQl3byTa2rDIZaiXgOGieiFp3oRT7UyxE9Or6z3AYxfeV6PCtjNq', 'Jacky Name', '08234233', 'ไทยประกัน', '2025-12-12 14:22:41', '2025-12-14 02:38:17', '2025-12-14 02:38:17', 'active', NULL, 7);

-- --------------------------------------------------------

--
-- Table structure for table `webhook_logs`
--

CREATE TABLE `webhook_logs` (
  `id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL COMMENT 'Payment provider (e.g., omise, stripe)',
  `event_type` varchar(100) NOT NULL COMMENT 'Event type (e.g., charge.complete)',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Full webhook payload' CHECK (json_valid(`payload`)),
  `processed` tinyint(1) DEFAULT 0 COMMENT 'Whether webhook has been processed',
  `error_message` text DEFAULT NULL COMMENT 'Error message if processing failed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`created_at`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_key` (`api_key`),
  ADD KEY `idx_api_key` (`api_key`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `api_service_config`
--
ALTER TABLE `api_service_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `service_code` (`service_code`),
  ADD KEY `idx_service_code` (`service_code`),
  ADD KEY `idx_enabled` (`is_enabled`);

--
-- Indexes for table `api_usage_logs`
--
ALTER TABLE `api_usage_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service_api_date` (`customer_service_id`,`api_type`,`created_at`),
  ADD KEY `idx_date` (`created_at`),
  ADD KEY `idx_api_type` (`api_type`),
  ADD KEY `idx_api_usage_date_service` (`created_at`,`api_type`,`customer_service_id`),
  ADD KEY `idx_api_usage_customer_date` (`customer_service_id`,`created_at`);

--
-- Indexes for table `bot_chat_logs`
--
ALTER TABLE `bot_chat_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service_date` (`customer_service_id`,`created_at`),
  ADD KEY `idx_platform_user` (`platform_user_id`),
  ADD KEY `idx_bot_chat_customer_date` (`customer_service_id`,`created_at`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session_created` (`session_id`,`created_at`);

--
-- Indexes for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_channel_user` (`channel_id`,`external_user_id`);

--
-- Indexes for table `customer_api_access`
--
ALTER TABLE `customer_api_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_service` (`user_id`,`service_code`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_service` (`service_code`),
  ADD KEY `idx_enabled` (`is_enabled`);

--
-- Indexes for table `customer_bot_profiles`
--
ALTER TABLE `customer_bot_profiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_default` (`user_id`,`is_default`),
  ADD KEY `idx_user_active` (`user_id`,`is_active`);

--
-- Indexes for table `customer_channels`
--
ALTER TABLE `customer_channels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_inbound_api_key` (`inbound_api_key`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_bot_profile` (`bot_profile_id`);

--
-- Indexes for table `customer_integrations`
--
ALTER TABLE `customer_integrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_provider` (`user_id`,`provider`),
  ADD KEY `idx_user_active` (`user_id`,`is_active`);

--
-- Indexes for table `customer_services`
--
ALTER TABLE `customer_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_key` (`api_key`),
  ADD KEY `service_type_id` (`service_type_id`),
  ADD KEY `idx_user_service` (`user_id`,`service_type_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_customer_service_user_status` (`user_id`,`status`);

--
-- Indexes for table `gateway_message_events`
--
ALTER TABLE `gateway_message_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_channel_event` (`channel_id`,`external_event_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `unique_invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `unique_user_billing_period` (`user_id`,`billing_period_start`,`billing_period_end`,`subscription_id`),
  ADD KEY `subscription_id` (`subscription_id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_invoice_number` (`invoice_number`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_invoices_user_date` (`user_id`,`created_at`),
  ADD KEY `idx_invoices_status` (`status`,`created_at`),
  ADD KEY `idx_user_subscription_date` (`user_id`,`subscription_id`,`billing_period_start`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice` (`invoice_id`);

--
-- Indexes for table `message_buffers`
--
ALTER TABLE `message_buffers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_channel_user` (`channel_id`,`external_user_id`,`status`),
  ADD KEY `idx_last_message_at` (`last_message_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_default` (`user_id`,`is_default`),
  ADD KEY `idx_omise_customer` (`omise_customer_id`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rate_limit_lookup` (`identifier`,`action`,`created_at`),
  ADD KEY `idx_rate_limit_cleanup` (`action`,`created_at`);

--
-- Indexes for table `request_metrics`
--
ALTER TABLE `request_metrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_endpoint` (`endpoint`,`created_at`),
  ADD KEY `idx_user` (`user_id`,`created_at`),
  ADD KEY `idx_status` (`http_status`,`created_at`),
  ADD KEY `idx_request_id` (`request_id`);

--
-- Indexes for table `service_types`
--
ALTER TABLE `service_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_period_end` (`current_period_end`),
  ADD KEY `idx_subscriptions_user_status` (`user_id`,`status`),
  ADD KEY `idx_subscriptions_plan` (`plan_id`,`status`),
  ADD KEY `idx_next_billing` (`next_billing_date`),
  ADD KEY `idx_trial_end` (`trial_end_date`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payment_method_id` (`payment_method_id`),
  ADD KEY `idx_omise_charge` (`omise_charge_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_transactions_invoice` (`invoice_id`,`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `webhook_logs`
--
ALTER TABLE `webhook_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_provider` (`provider`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `api_service_config`
--
ALTER TABLE `api_service_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `api_usage_logs`
--
ALTER TABLE `api_usage_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `bot_chat_logs`
--
ALTER TABLE `bot_chat_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_sessions`
--
ALTER TABLE `chat_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_api_access`
--
ALTER TABLE `customer_api_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `customer_bot_profiles`
--
ALTER TABLE `customer_bot_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customer_channels`
--
ALTER TABLE `customer_channels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customer_integrations`
--
ALTER TABLE `customer_integrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_services`
--
ALTER TABLE `customer_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `gateway_message_events`
--
ALTER TABLE `gateway_message_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `message_buffers`
--
ALTER TABLE `message_buffers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `request_metrics`
--
ALTER TABLE `request_metrics`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `service_types`
--
ALTER TABLE `service_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `webhook_logs`
--
ALTER TABLE `webhook_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD CONSTRAINT `api_keys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `api_usage_logs`
--
ALTER TABLE `api_usage_logs`
  ADD CONSTRAINT `api_usage_logs_ibfk_1` FOREIGN KEY (`customer_service_id`) REFERENCES `customer_services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bot_chat_logs`
--
ALTER TABLE `bot_chat_logs`
  ADD CONSTRAINT `bot_chat_logs_ibfk_1` FOREIGN KEY (`customer_service_id`) REFERENCES `customer_services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `fk_chat_messages_session` FOREIGN KEY (`session_id`) REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_api_access`
--
ALTER TABLE `customer_api_access`
  ADD CONSTRAINT `customer_api_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_services`
--
ALTER TABLE `customer_services`
  ADD CONSTRAINT `customer_services_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_services_ibfk_2` FOREIGN KEY (`service_type_id`) REFERENCES `service_types` (`id`);

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`);

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD CONSTRAINT `payment_methods_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;