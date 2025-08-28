-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 21, 2025 at 11:11 AM
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
-- Database: `myfreemangit`
--

-- --------------------------------------------------------

--
-- Table structure for table `adherents`
--

CREATE TABLE `adherents` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `reason` text NOT NULL COMMENT 'Reason for marking member as adherent',
  `date_became_adherent` date NOT NULL COMMENT 'Date when member became adherent',
  `marked_by` int(11) NOT NULL COMMENT 'User ID who marked the member as adherent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When this record was created'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks adherent status history for members';

--
-- Dumping data for table `adherents`
--

INSERT INTO `adherents` (`id`, `member_id`, `reason`, `date_became_adherent`, `marked_by`, `created_at`) VALUES
(16, 130, 'NOTE', '2025-08-04', 3, '2025-08-04 21:19:57');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `session_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `status` enum('present','absent') DEFAULT 'present',
  `marked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `zkteco_raw_log_id` int(11) DEFAULT NULL,
  `hikvision_raw_log_id` int(11) DEFAULT NULL,
  `sync_source` enum('manual','zkteco','hikvision','hybrid') DEFAULT 'manual',
  `verification_type` varchar(20) DEFAULT NULL,
  `device_timestamp` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `id` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `service_date` date DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT 0,
  `recurrence_type` enum('weekly','monthly') DEFAULT NULL,
  `recurrence_day` int(11) DEFAULT NULL,
  `parent_recurring_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `attendance_with_zkteco`
-- (See below for the actual view)
--
CREATE TABLE `attendance_with_zkteco` (
);

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(32) NOT NULL,
  `entity_type` varchar(32) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`) VALUES
(995, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.187.133\"}', '154.161.187.133', '2025-08-03 17:24:28'),
(996, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.47.194\"}', '102.176.47.194', '2025-08-03 17:31:11'),
(997, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.187.133\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.187.133', '2025-08-03 17:45:37'),
(998, 3, 'logout', 'user', 3, '{\"ip\":\"102.176.47.194\",\"time\":\"2025-07-14T16:56:25Z\"}', '102.176.47.194', '2025-08-03 18:34:07'),
(999, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.47.194\"}', '102.176.47.194', '2025-08-03 19:16:21'),
(1000, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.47.194\"}', '102.176.47.194', '2025-08-03 20:45:30'),
(1001, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-04 08:14:04'),
(1002, 3, 'logout', 'user', 3, '{\"ip\":\"169.150.218.59\",\"time\":\"2025-07-14T16:56:25Z\"}', '169.150.218.59', '2025-08-04 08:49:17'),
(1003, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"169.150.218.59\"}', '169.150.218.59', '2025-08-04 09:08:28'),
(1004, 3, 'logout', 'user', 3, '{\"ip\":\"169.150.218.59\",\"time\":\"2025-07-14T16:56:25Z\"}', '169.150.218.59', '2025-08-04 09:22:36'),
(1005, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.167.168\"}', '154.161.167.168', '2025-08-04 09:34:34'),
(1006, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.167.168\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.167.168', '2025-08-04 10:36:50'),
(1007, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"169.150.218.59\"}', '169.150.218.59', '2025-08-04 10:52:33'),
(1008, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-08-04 11:33:58'),
(1009, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-04 15:11:30'),
(1010, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-08-04 15:42:46'),
(1011, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-04 16:18:21'),
(1012, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-08-04 16:53:17'),
(1013, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-04 18:23:31'),
(1014, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.191.37\"}', '154.161.191.37', '2025-08-04 18:41:10'),
(1015, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.191.37\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.191.37', '2025-08-04 18:55:41'),
(1016, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.191.37\"}', '154.161.191.37', '2025-08-04 19:16:27'),
(1017, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.191.37\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.191.37', '2025-08-04 19:30:55'),
(1018, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-04 20:16:24'),
(1019, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-04 20:27:34'),
(1020, 3, 'logout', 'user', 3, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-04 20:44:19'),
(1021, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-04 20:44:28'),
(1022, 3, 'logout', 'user', 3, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-04 20:56:26'),
(1023, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-04 20:56:36'),
(1024, 3, 'logout', 'user', 3, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-04 21:03:55'),
(1025, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-04 21:05:19'),
(1026, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.9.16\"}', '154.160.9.16', '2025-08-04 23:42:22'),
(1027, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-05 08:33:23'),
(1028, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-05 10:29:47'),
(1029, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-08-05 11:51:36'),
(1030, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-05 11:51:50'),
(1031, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-05 14:11:52'),
(1032, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.66.209.168\"}', '41.66.209.168', '2025-08-05 21:46:57'),
(1033, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.5.157\"}', '154.160.5.157', '2025-08-05 22:03:59'),
(1034, 3, 'logout', 'user', 3, '{\"ip\":\"41.66.209.168\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.66.209.168', '2025-08-05 22:05:41'),
(1035, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.5.157\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.5.157', '2025-08-05 22:43:16'),
(1036, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.5.157\"}', '154.160.5.157', '2025-08-05 23:21:59'),
(1037, 3, 'update', 'role', 2, '{\"name\":\"ADMIN\",\"description\":\"\"}', NULL, '2025-08-06 00:19:49'),
(1038, 3, 'create', 'role', 10, '{\"name\":\"SUNDAY SCHOOL\",\"description\":\"In charge of Sunday School Activities\"}', NULL, '2025-08-06 00:26:39'),
(1039, 3, 'update', 'role', 3, '{\"name\":\"STEWARDS\",\"description\":\"\"}', NULL, '2025-08-06 00:46:32'),
(1040, 3, 'update', 'role', 4, '{\"name\":\"Rev. Ministers\",\"description\":\"\"}', NULL, '2025-08-06 00:57:43'),
(1041, 3, 'update', 'role', 8, '{\"name\":\"HEALTH\",\"description\":\"Medical team\"}', NULL, '2025-08-06 01:09:31'),
(1042, 3, 'create', 'role', 11, '{\"name\":\"SUPPER ADMIN\",\"description\":\"In charge of digital platforms\"}', NULL, '2025-08-06 01:24:14'),
(1043, 3, 'delete', 'role', 11, '', NULL, '2025-08-06 01:29:10'),
(1044, 25, 'login_success', 'user', 25, '{\"username\":\"barnasco4uallgh@gmail.com\",\"ip\":\"154.160.5.157\"}', '154.160.5.157', '2025-08-06 01:30:08'),
(1045, 26, 'login_success', 'user', 26, '{\"username\":\"ansam@gmail.com\",\"ip\":\"154.160.5.157\"}', '154.160.5.157', '2025-08-06 01:41:48'),
(1046, 26, 'login_success', 'user', 26, '{\"username\":\"ansam@gmail.com\",\"ip\":\"154.160.5.157\"}', '154.160.5.157', '2025-08-06 01:46:44'),
(1047, 26, 'login_success', 'user', 26, '{\"username\":\"ansam@gmail.com\",\"ip\":\"154.160.5.157\"}', '154.160.5.157', '2025-08-06 01:47:30'),
(1048, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.5.157\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.5.157', '2025-08-06 01:48:25'),
(1049, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.66.209.119\"}', '41.66.209.119', '2025-08-06 07:26:43'),
(1050, 3, 'logout', 'user', 3, '{\"ip\":\"41.66.209.119\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.66.209.119', '2025-08-06 07:31:26'),
(1051, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.66.209.119\"}', '41.66.209.119', '2025-08-06 07:31:54'),
(1052, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.66.209.119\"}', '41.66.209.119', '2025-08-06 07:32:11'),
(1053, 3, 'logout', 'user', 3, '{\"ip\":\"41.66.209.119\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.66.209.119', '2025-08-06 07:33:07'),
(1054, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.66.209.119\"}', '41.66.209.119', '2025-08-06 07:33:22'),
(1055, 25, 'login_success', 'user', 25, '{\"username\":\"barnasco4uallgh@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-06 08:50:49'),
(1056, 26, 'login_success', 'user', 26, '{\"username\":\"ansam@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-06 08:55:16'),
(1057, 25, 'logout', 'user', 25, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-08-06 09:24:27'),
(1058, 25, 'login_success', 'user', 25, '{\"username\":\"barnasco4uallgh@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-06 16:22:22'),
(1059, 25, 'logout', 'user', 25, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-08-06 17:04:45'),
(1060, NULL, 'login_failed', 'user', NULL, '{\"username\":\"nasam@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 18:40:23'),
(1061, NULL, 'login_failed', 'user', NULL, '{\"username\":\"nasam@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 18:44:04'),
(1062, 25, 'login_success', 'user', 25, '{\"username\":\"barnasco4uallgh@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 18:47:05'),
(1063, 26, 'login_success', 'user', 26, '{\"username\":\"ansam@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 19:08:56'),
(1064, 26, 'logout', 'user', 26, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 20:15:33'),
(1065, 28, 'login_success', 'user', 28, '{\"username\":\"kpanford@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 20:15:43'),
(1066, 25, 'logout', 'user', 25, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 20:25:43'),
(1067, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 20:41:21'),
(1068, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 20:52:50'),
(1069, 3, 'logout', 'user', 3, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 20:58:35'),
(1070, 25, 'login_success', 'user', 25, '{\"username\":\"barnasco4uallgh@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 20:59:14'),
(1071, 25, 'logout', 'user', 25, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:00:12'),
(1072, 26, 'login_success', 'user', 26, '{\"username\":\"ansam@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:00:29'),
(1073, 26, 'logout', 'user', 26, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:17:23'),
(1074, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:17:36'),
(1075, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:17:56'),
(1076, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:18:20'),
(1077, 27, 'logout', 'user', 27, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:18:36'),
(1078, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:18:49'),
(1079, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:19:23'),
(1080, 27, 'logout', 'user', 27, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:19:55'),
(1081, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:20:06'),
(1082, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:20:24'),
(1083, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:20:45'),
(1084, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:21:02'),
(1085, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:21:24'),
(1086, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:28:42'),
(1087, 27, 'logout', 'user', 27, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:32:10'),
(1088, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:32:36'),
(1089, 27, 'logout', 'user', 27, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:33:53'),
(1090, 28, 'login_success', 'user', 28, '{\"username\":\"kpanford@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:34:29'),
(1091, 28, 'logout', 'user', 28, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:35:18'),
(1092, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:36:46'),
(1093, 27, 'logout', 'user', 27, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:42:57'),
(1094, 28, 'login_success', 'user', 28, '{\"username\":\"kpanford@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:43:39'),
(1095, 28, 'logout', 'user', 28, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:54:34'),
(1096, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:54:44'),
(1097, 27, 'logout', 'user', 27, '{\"ip\":\"41.218.193.27\",\"time\":\"2025-07-14T16:56:25Z\"}', '41.218.193.27', '2025-08-06 21:57:25'),
(1098, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"41.218.193.27\"}', '41.218.193.27', '2025-08-06 21:57:38'),
(1099, 25, 'login_success', 'user', 25, '{\"username\":\"barnasco4uallgh@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-08-07 11:09:34'),
(1100, 25, 'logout', 'user', 25, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-08-07 11:13:29'),
(1101, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 21:30:47'),
(1102, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 21:40:15'),
(1103, 3, 'logout', 'user', 3, '{\"ip\":\"143.105.209.107\",\"time\":\"2025-07-14T16:56:25Z\"}', '143.105.209.107', '2025-08-08 21:49:03'),
(1104, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:26:03'),
(1105, 3, 'logout', 'user', 3, '{\"ip\":\"143.105.209.107\",\"time\":\"2025-07-14T16:56:25Z\"}', '143.105.209.107', '2025-08-08 22:28:40'),
(1106, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:28:55'),
(1107, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:29:14'),
(1108, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:29:29'),
(1109, 3, 'logout', 'user', 3, '{\"ip\":\"143.105.209.107\",\"time\":\"2025-07-14T16:56:25Z\"}', '143.105.209.107', '2025-08-08 22:33:14'),
(1110, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:33:26'),
(1111, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:33:40'),
(1112, 3, 'logout', 'user', 3, '{\"ip\":\"143.105.209.107\",\"time\":\"2025-07-14T16:56:25Z\"}', '143.105.209.107', '2025-08-08 22:36:36'),
(1113, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:36:47'),
(1114, 3, 'logout', 'user', 3, '{\"ip\":\"143.105.209.107\",\"time\":\"2025-07-14T16:56:25Z\"}', '143.105.209.107', '2025-08-08 22:36:54'),
(1115, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:37:06'),
(1116, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"143.105.209.107\"}', '143.105.209.107', '2025-08-08 22:37:21'),
(1117, 3, 'logout', 'user', 3, '{\"ip\":\"143.105.209.107\",\"time\":\"2025-07-14T16:56:25Z\"}', '143.105.209.107', '2025-08-08 23:03:14'),
(1118, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 07:33:53'),
(1119, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 07:34:02'),
(1120, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 07:34:50'),
(1121, 27, 'logout', 'user', 27, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 07:37:38'),
(1122, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 07:37:45'),
(1123, 27, 'logout', 'user', 27, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 07:45:14'),
(1124, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 07:45:21'),
(1125, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 07:52:44'),
(1126, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 07:55:04'),
(1127, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 08:09:18'),
(1128, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 13:07:25'),
(1129, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 13:26:48'),
(1130, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 13:29:12'),
(1131, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 13:48:52'),
(1132, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 13:57:11'),
(1133, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 14:44:20'),
(1134, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-09 14:44:28'),
(1135, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-09 17:11:59'),
(1136, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 09:50:30'),
(1137, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 10:12:43'),
(1138, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 10:20:55'),
(1139, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 10:32:01'),
(1140, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 10:32:22'),
(1141, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 10:58:16'),
(1142, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 10:59:41'),
(1143, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 11:10:36'),
(1144, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 11:11:18'),
(1145, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 11:29:38'),
(1146, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 11:29:49'),
(1147, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 12:08:29'),
(1148, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 12:10:12'),
(1149, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 18:18:00'),
(1150, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 19:33:22'),
(1151, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-12 19:44:44'),
(1152, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-12 20:45:14'),
(1153, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-16 18:50:58'),
(1154, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-16 18:59:01'),
(1155, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-16 19:46:03'),
(1156, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-16 19:47:55'),
(1157, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-16 20:00:43'),
(1158, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-16 20:46:23'),
(1159, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-16 21:14:04'),
(1160, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-17 12:55:10'),
(1161, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-17 13:08:01'),
(1162, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-17 13:12:10'),
(1163, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-17 14:23:14'),
(1164, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-17 14:41:25'),
(1165, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-17 14:59:45'),
(1166, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-18 08:23:05'),
(1167, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-18 08:35:08'),
(1168, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-19 21:37:53'),
(1169, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-19 21:51:39'),
(1170, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-19 22:13:02'),
(1171, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-19 22:30:58'),
(1172, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-19 22:49:55'),
(1173, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-19 23:02:53'),
(1174, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-19 23:25:35'),
(1175, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-20 00:47:26'),
(1176, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-20 01:00:30'),
(1177, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-20 01:13:09'),
(1178, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-20 01:37:03'),
(1179, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-20 02:30:01'),
(1180, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-20 02:41:48'),
(1181, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-20 02:59:06'),
(1182, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-20 03:11:48'),
(1183, NULL, 'login_failed', 'user', NULL, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-20 06:31:55'),
(1184, 27, 'login_success', 'user', 27, '{\"username\":\"danielantwi512@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-20 06:32:00'),
(1185, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-20 06:58:49'),
(1186, 27, 'logout', 'user', 27, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-20 07:15:07'),
(1187, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-08-21 07:51:57'),
(1188, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-08-21 09:03:43');

-- --------------------------------------------------------

--
-- Table structure for table `bible_classes`
--

CREATE TABLE `bible_classes` (
  `id` int(11) NOT NULL,
  `class_group_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `code` varchar(10) DEFAULT NULL,
  `leader_id` int(11) DEFAULT NULL COMMENT 'References users(id) as class leader',
  `church_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bible_classes`
--

INSERT INTO `bible_classes` (`id`, `class_group_id`, `name`, `code`, `leader_id`, `church_id`) VALUES
(40, NULL, 'DUNTU 01', 'D01', NULL, 7),
(41, NULL, 'DUNTU 02', 'D02', NULL, 7),
(42, NULL, 'DUNTU 03', 'D03', NULL, 7),
(43, NULL, 'FREEMAN 01', 'F01', NULL, 7),
(44, NULL, 'FREEMAN 02', 'F02', NULL, 7),
(45, NULL, 'FREEMAN 03', 'F03', NULL, 7),
(46, NULL, 'KOOMSON 01', 'K01', NULL, 7),
(47, NULL, 'KOOMSON 02', 'K02', NULL, 7),
(48, NULL, 'KOOMSON 03', 'K03', NULL, 7),
(49, NULL, 'SUNDAY SCHOOL 01', 'S01', NULL, 7);

-- --------------------------------------------------------

--
-- Table structure for table `calendar_events`
--

CREATE TABLE `calendar_events` (
  `id` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `event_type_id` int(11) DEFAULT NULL,
  `event_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cashier_denomination_entries`
--

CREATE TABLE `cashier_denomination_entries` (
  `id` int(11) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `denom_200` int(11) NOT NULL DEFAULT 0,
  `denom_100` int(11) NOT NULL DEFAULT 0,
  `denom_50` int(11) NOT NULL DEFAULT 0,
  `denom_20` int(11) NOT NULL DEFAULT 0,
  `denom_10` int(11) NOT NULL DEFAULT 0,
  `denom_5` int(11) NOT NULL DEFAULT 0,
  `denom_2` int(11) NOT NULL DEFAULT 0,
  `denom_1` int(11) NOT NULL DEFAULT 0,
  `denom_2_Coin` int(11) NOT NULL DEFAULT 0,
  `denom_1_Coin` int(11) NOT NULL DEFAULT 0,
  `denom_50_p` int(11) NOT NULL DEFAULT 0,
  `denom_20_p` int(11) NOT NULL DEFAULT 0,
  `denom_10_p` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(12,2) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `churches`
--

CREATE TABLE `churches` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `church_code` varchar(10) DEFAULT NULL,
  `circuit_code` varchar(10) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `churches`
--

INSERT INTO `churches` (`id`, `name`, `church_code`, `circuit_code`, `logo`, `created_at`) VALUES
(7, 'Freeman Methodist Church', 'FMC', 'KM', 'logo_686fd2f349cc5.png', '2025-07-09 01:50:49');

-- --------------------------------------------------------

--
-- Table structure for table `class_groups`
--

CREATE TABLE `class_groups` (
  `id` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_groups`
--

INSERT INTO `class_groups` (`id`, `church_id`, `name`) VALUES
(15, NULL, 'FREEMAN'),
(16, NULL, 'KOOMSON'),
(17, NULL, 'DUNTU'),
(18, NULL, 'SUNDAY SCHOOL');

-- --------------------------------------------------------

--
-- Table structure for table `deleted_members`
--

CREATE TABLE `deleted_members` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `church_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `crn` varchar(50) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `day_born` varchar(15) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `gps_address` varchar(255) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `home_town` varchar(255) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `status` enum('pending','active','de-activated','deleted') DEFAULT 'pending',
  `deleted_at` datetime DEFAULT NULL,
  `deactivated_at` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `registration_token` varchar(64) DEFAULT NULL,
  `employment_status` enum('Formal','Informal','Self Employed','Retired','Student') DEFAULT NULL,
  `profession` varchar(100) DEFAULT NULL,
  `baptized` enum('Yes','No') DEFAULT NULL,
  `confirmed` enum('Yes','No') DEFAULT NULL,
  `date_of_baptism` date DEFAULT NULL,
  `date_of_confirmation` date DEFAULT NULL,
  `membership_status` enum('Full Member','Catechumen','Adherent','Juvenile','Invalid Distant Member') DEFAULT NULL,
  `date_of_enrollment` date DEFAULT NULL,
  `sms_notifications_enabled` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `event_type_id` int(11) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time NOT NULL,
  `location` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `gallery` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_registrations`
--

CREATE TABLE `event_registrations` (
  `id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_types`
--

CREATE TABLE `event_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_types`
--

INSERT INTO `event_types` (`id`, `name`) VALUES
(3, 'Mini harvest'),
(4, 'BRIGADE PARTY');

-- --------------------------------------------------------

--
-- Table structure for table `health_records`
--

CREATE TABLE `health_records` (
  `id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `sundayschool_id` int(11) DEFAULT NULL,
  `vitals` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_at` datetime DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `health_records`
--

INSERT INTO `health_records` (`id`, `member_id`, `sundayschool_id`, `vitals`, `notes`, `recorded_at`, `recorded_by`) VALUES
(29, 122, NULL, '{\"weight\":\"38\",\"temperature\":\"32.5\",\"bp_systolic\":\"\",\"bp_diastolic\":\"\",\"bp_status\":\"low\",\"sugar\":\"\",\"sugar_status\":\"low\",\"hepatitis_b\":\"\",\"malaria\":\"\",\"bp\":\"\\/\"}', 'VERY GOOD', '2025-08-03 20:51:00', 3),
(30, NULL, 12, '{\"weight\":\"23.5\",\"temperature\":\"31.2\",\"bp_systolic\":\"105\",\"bp_diastolic\":\"75\",\"bp_status\":\"normal\",\"sugar\":\"6\",\"sugar_status\":\"normal\",\"hepatitis_b\":\"not_tested\",\"malaria\":\"negative\",\"bp\":\"105\\/75\"}', 'very good', '2025-08-04 20:37:00', 3);

-- --------------------------------------------------------

--
-- Table structure for table `hikvision_devices`
--

CREATE TABLE `hikvision_devices` (
  `id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `device_model` varchar(50) DEFAULT 'DS-K1T320MFWX',
  `ip_address` varchar(15) NOT NULL,
  `port` int(11) DEFAULT 80,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `device_serial` varchar(50) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_sync` datetime DEFAULT NULL,
  `sync_status` enum('connected','disconnected','error') DEFAULT 'disconnected',
  `max_users` int(11) DEFAULT 3000,
  `current_users` int(11) DEFAULT 0,
  `firmware_version` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='HikVision face recognition devices for attendance tracking';

--
-- Dumping data for table `hikvision_devices`
--

INSERT INTO `hikvision_devices` (`id`, `church_id`, `device_name`, `device_model`, `ip_address`, `port`, `username`, `password`, `device_serial`, `location`, `is_active`, `last_sync`, `sync_status`, `max_users`, `current_users`, `firmware_version`, `created_at`, `updated_at`) VALUES
(1, 7, 'FMC ATTENDANCE DEVICE', 'DS-K1T320MFWX', '192.168.5.201', 80, 'admin', '223344AD', NULL, 'GATE', 1, NULL, 'error', 3000, 0, NULL, '2025-08-17 13:39:23', '2025-08-17 14:12:39'),
(2, 7, 'AFSAFSF', 'DS-K1T320MFWX', '192.168.5.202', 8000, 'ADMIN', 'ADMIN', NULL, 'GATE 2', 1, NULL, 'error', 3000, 0, NULL, '2025-08-17 13:45:18', '2025-08-17 14:44:06');

-- --------------------------------------------------------

--
-- Table structure for table `hikvision_raw_logs`
--

CREATE TABLE `hikvision_raw_logs` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `user_id` varchar(50) NOT NULL COMMENT 'HikVision device user ID',
  `event_time` datetime NOT NULL,
  `event_type` varchar(20) DEFAULT 'access',
  `verification_mode` varchar(20) DEFAULT 'face',
  `door_id` int(11) DEFAULT 1,
  `raw_data` text DEFAULT NULL COMMENT 'Complete JSON response from device',
  `processed` tinyint(1) DEFAULT 0,
  `processed_at` datetime DEFAULT NULL,
  `attendance_record_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Raw access logs from HikVision devices';

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `church_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `crn` varchar(50) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `day_born` varchar(15) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `gps_address` varchar(255) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `home_town` varchar(255) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `status` enum('pending','active','de-activated') DEFAULT 'pending',
  `deactivated_at` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `registration_token` varchar(64) DEFAULT NULL,
  `employment_status` enum('Formal','Informal','Self Employed','Retired','Student') DEFAULT NULL,
  `profession` varchar(100) DEFAULT NULL,
  `baptized` enum('Yes','No') DEFAULT NULL,
  `confirmed` enum('Yes','No') DEFAULT NULL,
  `date_of_baptism` date DEFAULT NULL,
  `date_of_confirmation` date DEFAULT NULL,
  `membership_status` enum('Full Member','Catechumen','Adherent','Juvenile','Invalid Distant Member') DEFAULT NULL,
  `date_of_enrollment` date DEFAULT NULL,
  `sms_notifications_enabled` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`id`, `first_name`, `middle_name`, `last_name`, `church_id`, `class_id`, `crn`, `name`, `dob`, `place_of_birth`, `day_born`, `gender`, `phone`, `telephone`, `email`, `address`, `gps_address`, `marital_status`, `home_town`, `region`, `photo`, `status`, `deactivated_at`, `password_hash`, `created_at`, `registration_token`, `employment_status`, `profession`, `baptized`, `confirmed`, `date_of_baptism`, `date_of_confirmation`, `membership_status`, `date_of_enrollment`, `sms_notifications_enabled`) VALUES
(122, 'BARNA', '', 'BAABIOLA', 7, 43, 'FMC-F0101-KM', NULL, '2010-08-04', 'TOWN', 'Wednesday', 'Male', '0242363905', '', 'bbarna@gmail.com', 'KWESIMINTSIM', 'WS-254-8569', 'Married', 'HOTOPO', 'Ashanti', 'member_689295453af76.png', 'active', '', '$2y$10$x/ET7vFGtowdU5z0tQWbEutqPyPPzgt0S.nDAk8W3zb4j.xfuytH6', '2025-08-03 19:20:16', NULL, 'Formal', 'ACCOUNTANT', 'Yes', 'Yes', '2022-05-03', '2023-05-09', '', '2020-02-05', 0),
(123, 'DANNY', '', 'WISE', 7, 40, 'FMC-D0101-KM', NULL, '1999-12-31', 'ANAJI', 'Friday', 'Male', '0553143607', '', 'dwise@gmail.com', '', '', 'Married', 'TAKORADI', 'Western', '', 'active', '', '$2y$10$ZTgUMqwDdpB1kJ.51OAuJew.s3rWLmGVMbGHiAby05KOeKeEOAlyW', '2025-08-03 19:22:03', NULL, 'Informal', 'TRADER', 'Yes', 'No', '2000-12-23', '0000-00-00', '', '2000-01-23', 0),
(124, 'JACK', '', 'JHAY', 7, 46, 'FMC-K0101-KM', NULL, '1997-03-31', 'TAKORADI', 'Monday', 'Male', '0551756789', '', 'jjhay@gmail.com', '', '', 'Married', 'TAKORADI', 'Western', '', 'active', '', '$2y$10$AQYBPQ7KZ5pwjg7Q8eSF6.T0rujWX72iTG9gKB.acUFUmJWOgw3Yu', '2025-08-03 19:25:01', NULL, 'Self Employed', 'COMMUNICATOR', 'Yes', 'Yes', '2000-02-08', '2000-10-23', '', '1999-10-20', 0),
(125, 'NANA', '', 'OTU', 7, 41, 'FMC-F0201-KM', NULL, '9338-02-08', 'TAKORADI', 'Saturday', 'Male', '0202707072', '', 'notu@gmail.com', '', '', 'Married', 'TAKORADI', 'Western', '', 'active', '', '$2y$10$CCU7OlIoSVxq2/WVlY4VbO7K4fbjFy0KmrHipxIdrQ1g/ZEKqbrQm', '2025-08-04 18:37:15', NULL, 'Formal', 'TEACHER', 'Yes', 'Yes', '3930-02-09', '2984-08-31', '', '4889-12-31', 0),
(127, 'KWAME', '', 'PANFORD', 7, 45, 'FMC-F0301-KM', NULL, '2000-12-31', 'KOJOKROM', 'Sunday', 'Male', '0541758561', '', 'kpanford@gmail.com', '', '', 'Married', 'TAKORADI', 'Western', '', 'active', '', '$2y$10$5yWDLhUfQor.WzijMfyDcOPLCVUOxrrf82A9IR6A4G3duBDShmhcG', '2025-08-04 18:42:37', NULL, 'Informal', 'TRADER', 'Yes', 'No', '2003-12-05', '0000-00-00', '', '2002-12-31', 0),
(128, 'DANIEL', '', 'ANTWI', 7, 44, 'FMC-F0201-KM', NULL, '2021-06-08', 'Takoradi', 'Tuesday', 'Male', '0206376136', '', 'danielantwi512@gmail.com', '', '', 'Single', 'Takoradi', 'Western', '', 'active', '', '$2y$10$JmM7MthhIB03InfzU1DEne2hUJNHA1rNxrz9NjPWy9hv36pIJ.VkC', '2025-08-04 20:39:00', NULL, 'Self Employed', 'Shipper', 'Yes', 'Yes', '2004-02-04', '2021-02-03', '', '2000-01-02', 0),
(129, 'WISDOM', '', 'ARTHUR', 7, 41, 'FMC-D0201-KM', NULL, '1998-12-23', 'Axim', 'Wednesday', 'Male', '0534234523', '', 'wisdomarthur@gmail.com', '', '', 'Single', 'Axim', 'Western North', '', 'active', '', '$2y$10$y5lgs3hTTtTSYnHc25IPKOjfdUqG47CPeVQ4sUu9K3a4RLJHUd7Qu', '2025-08-04 20:49:04', NULL, 'Student', 'TTU', 'Yes', 'No', '2000-12-04', '0000-00-00', '', '2000-01-04', 0),
(130, 'ATTA', '', 'OPPONG', 7, 47, 'FMC-K0201-KM', NULL, '2001-02-02', 'ANAJI', 'Friday', 'Male', '0544567850', '', 'attaoppong@gmail.com', '', '', 'Single', 'ANAJI', 'Western', '', 'active', '', '$2y$10$uemZX48kMGl519rR9yj5s.rFHFssVp4aRF1ztutVctFRqNKHuk27G', '2025-08-04 21:00:44', NULL, 'Student', 'UCC', 'Yes', 'Yes', '2005-02-23', '2005-02-23', 'Adherent', '2001-12-01', 0),
(131, 'SAM NAA AMA', '', '', 7, 49, 'FMC-S0104-KM', NULL, NULL, NULL, NULL, NULL, '0277384201', NULL, 'ansam@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '', '', '2025-08-06 01:39:02', 'a122d02c85b1aecaacd9bcc85a91aff8', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(132, 'Justina', '', 'Mensah', 7, 41, 'FMC-D0202-KM', NULL, '1990-05-08', 'Agogo', 'Tuesday', 'Female', '0234567899', '', 'ekowme@gmail.comm', '42 Mapple St', '', 'Married', 'Tonawanda', 'Bono', 'member_68975a5ce6cdc.jpg', 'active', '', '$2y$10$q6QP5jRzPqWLUiQI1PSiHOoQfOBNDf6gX1ZS96Tsqf7G4bejBzWRG', '2025-08-09 14:20:39', NULL, 'Formal', 'Teacher', 'No', 'No', '0000-00-00', '0000-00-00', '', '0000-00-00', 0);

-- --------------------------------------------------------

--
-- Table structure for table `member_biometric_data`
--

CREATE TABLE `member_biometric_data` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `zk_user_id` varchar(50) NOT NULL,
  `fingerprint_enrolled` tinyint(1) DEFAULT 0,
  `face_enrolled` tinyint(1) DEFAULT 0,
  `card_number` varchar(50) DEFAULT NULL,
  `enrollment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Maps church members to their biometric data on ZKTeco devices';

-- --------------------------------------------------------

--
-- Table structure for table `member_emergency_contacts`
--

CREATE TABLE `member_emergency_contacts` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `mobile` varchar(30) NOT NULL,
  `relationship` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `member_emergency_contacts`
--

INSERT INTO `member_emergency_contacts` (`id`, `member_id`, `name`, `mobile`, `relationship`) VALUES
(0, 126, 'BARNABAS QUAYSON-OTOO', '0242363905', 'BROTHER'),
(0, 129, 'AA', '0123334523', 'Son'),
(0, 130, 'HB', '0233456678', 'DAUGHTER'),
(0, 125, 'VB', '0234567788', 'VB'),
(0, 127, 'VB', '0234567788', 'BB'),
(0, 123, 'NANA', '0234556777', 'SON'),
(0, 122, 'DAN OTU', '0277384201', 'BROTHER'),
(0, 128, 'Bb', '0545678908', 'Father'),
(0, 132, 'Tito', '1234567899', 'Brother'),
(0, 124, 'DAN', '0545678908', 'SON');

-- --------------------------------------------------------

--
-- Table structure for table `member_feedback`
--

CREATE TABLE `member_feedback` (
  `id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_feedback_thread`
--

CREATE TABLE `member_feedback_thread` (
  `id` int(11) NOT NULL,
  `feedback_id` int(11) DEFAULT NULL,
  `recipient_type` enum('member','user') NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `sender_type` enum('member','user') NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_hikvision_data`
--

CREATE TABLE `member_hikvision_data` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `hikvision_user_id` varchar(50) NOT NULL,
  `face_enrolled` tinyint(1) DEFAULT 0,
  `enrollment_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_organizations`
--

CREATE TABLE `member_organizations` (
  `id` int(11) NOT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `role` varchar(100) DEFAULT NULL,
  `joined_at` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `member_organizations`
--

INSERT INTO `member_organizations` (`id`, `organization_id`, `member_id`, `role`, `joined_at`) VALUES
(0, 18, 125, NULL, NULL),
(0, 20, 127, NULL, NULL),
(0, 20, 123, NULL, NULL),
(0, 18, 123, NULL, NULL),
(0, 20, 122, NULL, NULL),
(0, 18, 122, NULL, NULL),
(0, 19, 132, NULL, NULL),
(0, 20, 124, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `member_roles_of_serving`
--

CREATE TABLE `member_roles_of_serving` (
  `member_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `member_roles_of_serving`
--

INSERT INTO `member_roles_of_serving` (`member_id`, `role_id`) VALUES
(129, 2),
(130, 4),
(125, 5),
(127, 5),
(123, 3),
(128, 1),
(132, 1),
(124, 3);

-- --------------------------------------------------------

--
-- Table structure for table `member_transfers`
--

CREATE TABLE `member_transfers` (
  `id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `from_class_id` int(11) DEFAULT NULL,
  `to_class_id` int(11) DEFAULT NULL,
  `transfer_date` date DEFAULT NULL,
  `transferred_by` int(11) DEFAULT NULL,
  `old_crn` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `id` int(11) NOT NULL,
  `permission_name` varchar(100) NOT NULL,
  `label` varchar(100) NOT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `url` varchar(255) NOT NULL,
  `menu_group` varchar(100) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`id`, `permission_name`, `label`, `icon`, `url`, `menu_group`, `sort_order`, `is_active`) VALUES
(7, 'view_dashboard', 'Dashboard', 'fas fa-tachometer-alt', 'index.php', 'Dashboard', 1, 1),
(8, 'view_member', 'Registered Members', 'fas fa-users', 'views/member_list.php', 'Members', 1, 1),
(9, 'create_member', 'Add Member', 'fas fa-user-plus', 'views/member_form.php', 'Members', 2, 1),
(10, 'view_attendance_list', 'Attendance', 'fas fa-calendar-check', 'views/attendance_list.php', 'Attendance', 20, 1),
(11, 'mark_attendance', 'Mark Attendance', 'fas fa-check-square', 'views/attendance_mark.php', 'Attendance', 21, 1),
(12, 'view_payment_list', 'Payment History', 'fas fa-money-check-alt', 'views/payment_list.php', 'Payments', 30, 1),
(13, 'make_payment', 'Make Payment', 'fas fa-credit-card', 'views/payment_form.php', 'Payments', 31, 1),
(14, 'view_payment_types_today', 'Today\'s Payment Types', 'fas fa-list-ul', 'views/reports/payment_types_today.php', 'Payments', 32, 1),
(15, 'view_payment_total_today', 'Today\'s Payment Total', 'fas fa-coins', 'views/reports/payment_total_today.php', 'Payments', 33, 1),
(16, 'view_reports_dashboard', 'Reports Dashboard', 'fas fa-chart-line', 'views/reports.php', 'Reports', 40, 1),
(17, 'view_membership_report', 'Membership Report', 'fas fa-id-badge', 'views/reports/membership_report.php', 'Reports', 41, 1),
(18, 'view_attendance_report', 'Attendance Report', 'fas fa-calendar-check', 'views/reports/attendance_report.php', 'Reports', 42, 1),
(19, 'view_payment_report', 'Payment Report', 'fas fa-money-bill-wave', 'views/reports/payment_report.php', 'Reports', 43, 1),
(20, 'view_event_report', 'Event Report', 'fas fa-calendar-alt', 'views/reports/event_report.php', 'Reports', 44, 1),
(21, 'view_visitor_report', 'Visitor Report', 'fas fa-user-friends', 'views/reports/visitor_report.php', 'Reports', 45, 1),
(22, 'view_health_report', 'Health Report', 'fas fa-heartbeat', 'views/reports/health_report.php', 'Reports', 46, 1),
(23, 'view_feedback_report', 'Feedback Report', 'fas fa-comments', 'views/reports/feedback_report.php', 'Reports', 47, 1),
(24, 'view_audit_report', 'Audit Report', 'fas fa-user-shield', 'views/reports/audit_report.php', 'Reports', 48, 1),
(25, 'view_sms_report', 'SMS Report', 'fas fa-sms', 'views/reports/sms_report.php', 'Reports', 49, 1),
(26, 'view_accumulated_payment_type_report', 'Accumulated Payment Type Report', 'fas fa-layer-group', 'views/reports/details/accumulated_payment_type_report.php', 'Reports', 51, 1),
(27, 'view_age_bracket_payment_report', 'Age Bracket Payment Report', 'fas fa-chart-bar', 'views/reports/details/age_bracket_payment_report.php', 'Reports', 52, 1),
(28, 'view_age_bracket_report', 'Age Bracket Report', 'fas fa-chart-pie', 'views/reports/details/age_bracket_report.php', 'Reports', 53, 1),
(29, 'view_baptism_report', 'Baptism Report', 'fas fa-water', 'views/reports/details/baptism_report.php', 'Reports', 54, 1),
(30, 'view_bibleclass_payment_report', 'Bibleclass Payment Report', 'fas fa-book', 'views/reports/details/bibleclass_payment_report.php', 'Reports', 55, 1),
(31, 'view_class_health_report', 'Class Health Report', 'fas fa-heartbeat', 'views/reports/details/class_health_report.php', 'Reports', 56, 1),
(32, 'view_confirmation_report', 'Confirmation Report', 'fas fa-certificate', 'views/reports/details/confirmation_report.php', 'Reports', 57, 1),
(33, 'view_date_of_birth_report', 'Date of Birth Report', 'fas fa-birthday-cake', 'views/reports/details/date_of_birth_report.php', 'Reports', 58, 1),
(34, 'view_day_born_payment_report', 'Day Born Payment Report', 'fas fa-calendar-day', 'views/reports/details/day_born_payment_report.php', 'Reports', 59, 1),
(35, 'view_employment_status_report', 'Employment Status Report', 'fas fa-briefcase', 'views/reports/details/employment_status_report.php', 'Reports', 60, 1),
(36, 'view_gender_report', 'Gender Report', 'fas fa-venus-mars', 'views/reports/details/gender_report.php', 'Reports', 61, 1),
(37, 'view_health_type_report', 'Health Type Report', 'fas fa-heart', 'views/reports/details/health_type_report.php', 'Reports', 62, 1),
(38, 'view_individual_health_report', 'Individual Health Report', 'fas fa-user-md', 'views/reports/details/individual_health_report.php', 'Reports', 63, 1),
(39, 'view_individual_payment_report', 'Individual Payment Report', 'fas fa-user', 'views/reports/details/individual_payment_report.php', 'Reports', 64, 1),
(40, 'view_marital_status_report', 'Marital Status Report', 'fas fa-ring', 'views/reports/details/marital_status_report.php', 'Reports', 65, 1),
(41, 'view_membership_status_report', 'Membership Status Report', 'fas fa-user-check', 'views/reports/details/membership_status_report.php', 'Reports', 66, 1),
(42, 'view_organisation_payment_report', 'Organisation Payment Report', 'fas fa-building', 'views/reports/details/organisation_payment_report.php', 'Reports', 67, 1),
(43, 'view_organisational_health_report', 'Organisational Health Report', 'fas fa-building', 'views/reports/details/organisational_health_report.php', 'Reports', 68, 1),
(44, 'view_organisational_member_report', 'Organisational Member Report', 'fas fa-users', 'views/reports/details/organisational_member_report.php', 'Reports', 69, 1),
(45, 'view_payment_made_report', 'Payment Made Report', 'fas fa-money-bill', 'views/reports/details/payment_made_report.php', 'Reports', 70, 1),
(46, 'view_profession_report', 'Profession Report', 'fas fa-briefcase', 'views/reports/details/profession_report.php', 'Reports', 71, 1),
(47, 'view_registered_by_date_report', 'Registered By Date Report', 'fas fa-calendar-plus', 'views/reports/details/registered_by_date_report.php', 'Reports', 72, 1),
(48, 'view_role_of_service_report', 'Role of Service Report', 'fas fa-user-tag', 'views/reports/details/role_of_service_report.php', 'Reports', 73, 1),
(49, 'view_zero_payment_type_report', 'Zero Payment Type Report', 'fas fa-ban', 'views/reports/details/zero_payment_type_report.php', 'Reports', 74, 1),
(50, 'view_bibleclass_list', 'Bible Classes', 'fas fa-book', 'views/bibleclass_list.php', 'Bible Classes', 80, 1),
(51, 'create_bibleclass', 'Add Bible Class', 'fas fa-book-medical', 'views/bibleclass_form.php', 'Bible Classes', 81, 1),
(52, 'view_organization_list', 'Organizations', 'fas fa-building', 'views/organization_list.php', 'Organizations', 90, 1),
(53, 'create_organization', 'Add Organization', 'fas fa-plus', 'views/organization_form.php', 'Organizations', 91, 1),
(54, 'view_event_list', 'Events', 'fas fa-calendar-alt', 'views/event_list.php', 'Events', 100, 1),
(55, 'create_event', 'Add Event', 'fas fa-calendar-plus', 'views/event_form.php', 'Events', 101, 1),
(56, 'view_feedback_list', 'Feedback', 'fas fa-comment-dots', 'views/memberfeedback_list.php', 'Feedback', 110, 1),
(57, 'view_sms_logs', 'SMS Logs', 'fas fa-sms', 'views/sms_logs.php', 'SMS', 120, 1),
(58, 'send_bulk_sms', 'Bulk SMS', 'fas fa-paper-plane', 'views/sms_bulk.php', 'SMS', 121, 1),
(59, 'view_visitor_list', 'Visitors', 'fas fa-user-friends', 'views/visitor_list.php', 'Visitors', 130, 1),
(60, 'create_visitor', 'Add Visitor', 'fas fa-user-plus', 'views/visitor_form.php', 'Visitors', 131, 1),
(61, 'view_role_list', 'Roles', 'fas fa-user-shield', 'views/role_list.php', 'User Management', 140, 1),
(62, 'view_permission_list', 'Permissions', 'fas fa-key', 'views/permission_list.php', 'User Management', 141, 1),
(63, 'view_user_list', 'Users', 'fas fa-user-cog', 'views/user_list.php', 'User Management', 150, 1),
(64, 'create_user', 'Add User', 'fas fa-user-plus', 'views/user_form.php', 'User Management', 151, 1),
(65, 'view_sundayschool_list', 'Sunday School List', 'fas fa-book-open', 'views/sundayschool_list.php', 'Sunday School', 1, 1),
(66, 'create_sundayschool', 'Add Sunday School', 'fas fa-plus', 'views/sundayschool_form.php', 'Sunday School', 2, 1),
(67, 'view_transfer_list', 'Transfers', 'fas fa-exchange-alt', 'views/transfer_list.php', 'Members', 5, 1),
(69, 'view_member', 'De-Activated Members', 'fas fa-clock', 'views/pending_members_list.php', 'Members', 4, 1),
(70, 'create_member', 'Register Member', 'fas fa-user-plus', 'views/register_member.php', 'Members', 3, 1),
(71, 'manage_menu_items', 'Menu Management', 'fas fa-bars', 'views/menu_management.php', 'System', 1, 1),
(72, 'view_health_list', 'Health Records', 'fas fa-heartbeat', 'views/health_list.php', 'Health', 1, 1),
(73, 'view_church_list', 'Church List', 'fas fa-church', 'views/church_list.php', 'Churches', 1, 1),
(74, 'submit_bulk_payment', 'Bulk Payment', 'fas fa-credit-card', '/views/payment_bulk.php', 'Payments', 32, 1),
(75, '', 'Payment Statistics', 'fas fa-coins', 'views/payments_statistics.php', 'Payments', 0, 1),
(76, '', 'Class Group', '', 'views/classgroup_list.php', 'Class Group', 0, 1),
(77, 'pending_members_list', 'Add Payment Types', 'fas fa-money', 'views/paymenttype_list.php', 'Payments', 50, 1),
(78, 'approve_organization_memberships', 'Approve Member Organizations', '', 'views/organization_membership_approvals.php', 'Members', 0, 1),
(79, '', 'Biometric Device', '', 'views/zkteco_devices.php', 'System', 0, 1),
(80, '', 'Adherents', 'fas fa-user', 'views/adherent_list.php', 'Members', 0, 1),
(81, '', 'Event Types', '', 'views/eventtype_list.php', 'Events', 5, 1),
(82, 'register_event', 'Roles of Serving', '', 'views/roles_of_serving_list.php', 'Roles of Serving', 0, 1),
(83, 'manage_hikvision_devices', 'HikVision Devices', '', 'views/hikvision_devices.php', 'System', 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

CREATE TABLE `organizations` (
  `id` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `leader_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `organizations`
--

INSERT INTO `organizations` (`id`, `church_id`, `name`, `description`, `leader_id`) VALUES
(18, 7, 'MYF', 'Youth Organization', NULL),
(19, 7, 'MGF', 'Girls Group', NULL),
(20, 7, 'BRIGADE', 'Boys and Girls Cadet', NULL),
(21, 7, 'NONE', 'No Organization Joined', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `organization_membership_approvals`
--

CREATE TABLE `organization_membership_approvals` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `organization_id` int(11) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores pending organization membership requests that require approval from Organization Leaders with proper permission checks';

--
-- Dumping data for table `organization_membership_approvals`
--

INSERT INTO `organization_membership_approvals` (`id`, `member_id`, `organization_id`, `requested_at`, `status`, `approved_by`, `approved_at`, `rejection_reason`, `notes`, `created_at`, `updated_at`) VALUES
(12, 128, 20, '2025-08-04 20:41:49', 'pending', NULL, NULL, NULL, NULL, '2025-08-04 20:41:49', '2025-08-04 20:41:49'),
(13, 129, 18, '2025-08-04 20:54:05', 'pending', NULL, NULL, NULL, NULL, '2025-08-04 20:54:05', '2025-08-04 20:54:05'),
(14, 130, 18, '2025-08-04 21:03:36', 'pending', NULL, NULL, NULL, NULL, '2025-08-04 21:03:36', '2025-08-04 21:03:36');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `email`, `token`, `expires_at`, `used`, `created_at`) VALUES
(0, 125, '', '8050a5e4b429fb1a3b50d1d1564ffdeb18aff1764f3d3c26d2f71bb1a3cc9f47', '2025-08-09 10:56:57', 0, '2025-08-09 07:56:58'),
(1, 3, 'ekowme@gmail.com', 'f3d3f1301acba457d1a3537d8811df3ba4919ff9c2fa97e0b48e74d6deda749e', '2025-07-16 10:55:19', 0, '2025-07-16 07:55:19');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `sundayschool_id` int(11) DEFAULT NULL,
  `church_id` int(11) DEFAULT NULL,
  `payment_type_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `client_reference` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `payment_period` date DEFAULT NULL,
  `payment_period_description` varchar(100) DEFAULT NULL,
  `recorded_by` varchar(11) DEFAULT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'Offline',
  `description` varchar(255) DEFAULT NULL,
  `reversal_requested_at` datetime DEFAULT NULL,
  `reversal_requested_by` int(11) DEFAULT NULL,
  `reversal_approved_at` datetime DEFAULT NULL,
  `reversal_approved_by` int(11) DEFAULT NULL,
  `reversal_undone_at` datetime DEFAULT NULL,
  `reversal_undone_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `member_id`, `sundayschool_id`, `church_id`, `payment_type_id`, `amount`, `payment_date`, `client_reference`, `status`, `payment_period`, `payment_period_description`, `recorded_by`, `mode`, `description`, `reversal_requested_at`, `reversal_requested_by`, `reversal_approved_at`, `reversal_approved_by`, `reversal_undone_at`, `reversal_undone_by`) VALUES
(590, 124, NULL, 7, 4, 1.00, '2025-08-20 08:18:36', NULL, NULL, '2025-07-01', 'July 2025', '3', 'Cash', 'Payment for July 2025 HARVEST', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_intents`
--

CREATE TABLE `payment_intents` (
  `id` int(11) NOT NULL,
  `client_reference` varchar(50) NOT NULL,
  `checkout_id` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `church_id` int(11) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `payment_type_id` int(11) DEFAULT NULL,
  `payment_period` varchar(32) DEFAULT NULL,
  `payment_period_description` varchar(64) DEFAULT NULL,
  `bulk_breakdown` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_intents`
--

INSERT INTO `payment_intents` (`id`, `client_reference`, `checkout_id`, `transaction_id`, `amount`, `description`, `customer_name`, `customer_phone`, `customer_email`, `member_id`, `church_id`, `status`, `payment_type_id`, `payment_period`, `payment_period_description`, `bulk_breakdown`, `created_at`, `updated_at`) VALUES
(12, 'PAY-68a54890c2be7', NULL, NULL, 7.00, 'Payment for - TITHE; Payment for - HARVEST; Payment for - TITHE; Payment for - HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', NULL, NULL, NULL, '[{\"typeId\":\"1\",\"typeName\":\"TITHE\",\"amount\":\"1\",\"date\":\"2025-08-20\",\"period\":\"2024-09-01\",\"periodText\":\"September 2024\",\"desc\":\"Payment for September 2024 TITHE\"},{\"typeId\":\"4\",\"typeName\":\"HARVEST\",\"amount\":\"2\",\"date\":\"2025-08-20\",\"period\":\"2024-10-01\",\"periodText\":\"October 2024\",\"desc\":\"Payment for October 2024 HARVEST\"},{\"typeId\":\"1\",\"typeName\":\"TITHE\",\"amount\":\"3\",\"date\":\"2025-08-20\",\"period\":\"2024-09-01\",\"periodText\":\"September 2024\",\"desc\":\"Payment for September 2024 TITHE\"},{\"typeId\":\"4\",\"typeName\":\"HARVEST\",\"amount\":\"1\",\"date\":\"2025-08-20\",\"period\":\"2025-02-01\",\"periodText\":\"February 2025\",\"desc\":\"Payment for February 2025 HARVEST\"}]', '0000-00-00 00:00:00', NULL),
(13, 'PAY-68a548e37638c', NULL, NULL, 8.00, 'Payment for January 2025 HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', 4, '2025-01-01', 'January 2025', NULL, '0000-00-00 00:00:00', NULL),
(14, 'PAY-68a54d924b1be', NULL, NULL, 1.00, 'Payment for October 2024 HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', 4, '2024-10-01', 'October 2024', NULL, '0000-00-00 00:00:00', NULL),
(15, 'PAY-68a54e5dc964d', NULL, NULL, 1.00, 'Payment for September 2024 HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', 4, '2024-09-01', 'September 2024', NULL, '0000-00-00 00:00:00', NULL),
(16, 'PAY-68a55ade6feb8', NULL, NULL, 1.00, 'Payment for October 2024 HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', 4, '2024-10-01', 'October 2024', NULL, '0000-00-00 00:00:00', NULL),
(17, 'PAY-68a56296dd1a1', NULL, NULL, 1.20, 'Payment for - HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', NULL, NULL, NULL, '[{\"typeId\":\"4\",\"typeName\":\"HARVEST\",\"amount\":\"1.2\",\"date\":\"2025-08-20\",\"period\":\"2024-09-01\",\"periodText\":\"September 2024\",\"desc\":\"Payment for September 2024 HARVEST\"}]', '0000-00-00 00:00:00', NULL),
(18, 'PAY-68a56330352f7', NULL, NULL, 2.50, 'Payment for - HARVEST; Payment for - HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', NULL, NULL, NULL, '[{\"typeId\":\"4\",\"typeName\":\"HARVEST\",\"amount\":\"1.2\",\"date\":\"2025-08-20\",\"period\":\"2024-11-01\",\"periodText\":\"November 2024\",\"desc\":\"Payment for November 2024 HARVEST\"},{\"typeId\":\"4\",\"typeName\":\"HARVEST\",\"amount\":\"1.3\",\"date\":\"2025-08-20\",\"period\":\"2024-12-01\",\"periodText\":\"December 2024\",\"desc\":\"Payment for December 2024 HARVEST\"}]', '0000-00-00 00:00:00', NULL),
(19, 'PAY-68a6d0731b628', NULL, NULL, 1.00, 'Payment for September 2024 HARVEST by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', 4, '2024-09-01', 'September 2024', NULL, '0000-00-00 00:00:00', NULL),
(20, 'PAY-68a6d1215d108', NULL, NULL, 2.00, 'Payment for September 2024 WELFARE by FMC-K0101-KM', 'JHAY, JACK', '0551756789', NULL, 124, 7, 'Pending', 3, '2024-09-01', 'September 2024', NULL, '0000-00-00 00:00:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_reversal_log`
--

CREATE TABLE `payment_reversal_log` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `action` enum('request','approve','undo') NOT NULL,
  `actor_id` int(11) NOT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_reversal_log`
--

INSERT INTO `payment_reversal_log` (`id`, `payment_id`, `action`, `actor_id`, `action_at`, `reason`) VALUES
(0, 483, 'request', 3, '2025-08-05 07:56:19', 'Requested by user');

-- --------------------------------------------------------

--
-- Table structure for table `payment_types`
--

CREATE TABLE `payment_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_types`
--

INSERT INTO `payment_types` (`id`, `name`, `description`, `active`) VALUES
(1, 'TITHE', 'Monthly Promise', 1),
(3, 'WELFARE', 'for donations', 1),
(4, 'HARVEST', 'Promise', 1);

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `group` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `group`, `description`) VALUES
(0, 'manage_hikvision_devices', NULL, 'Enroll members in Hikvision devices'),
(77, 'view_dashboard', 'Dashboard', 'View the main dashboard'),
(78, 'view_member', 'Members', 'View member records'),
(79, 'create_member', 'Members', 'Create new member records'),
(80, 'edit_member', 'Members', 'Edit member records'),
(81, 'delete_member', 'Members', 'Delete member records'),
(82, 'export_member', 'Members', 'Export member data'),
(83, 'import_member', 'Members', 'Import member data'),
(84, 'upload_member', 'Members', 'Upload member files'),
(85, 'activate_member', 'Members', 'Activate a member'),
(86, 'deactivate_member', 'Members', 'Deactivate a member'),
(87, 'permanently_delete_member', 'Members', 'Permanently delete a member'),
(88, 'restore_deleted_member', 'Members', 'Restore a deleted member'),
(89, 'view_member_profile', 'Members', 'View member profile'),
(90, 'edit_member_profile', 'Members', 'Edit member profile'),
(91, 'view_member_organizations', 'Members', 'View member organizations'),
(92, 'edit_member_organizations', 'Members', 'Edit member organizations'),
(93, 'view_member_health_records', 'Members', 'View member health records'),
(94, 'view_member_events', 'Members', 'View member events'),
(95, 'view_member_feedback', 'Members', 'View member feedback'),
(96, 'respond_member_feedback', 'Members', 'Respond to member feedback'),
(97, 'convert_visitor_to_member', 'Members', 'Convert a visitor to a member'),
(98, 'view_attendance_list', 'Attendance', 'View attendance list'),
(99, 'view_attendance_history', 'Attendance', 'View attendance history'),
(100, 'mark_attendance', 'Attendance', 'Mark attendance'),
(101, 'edit_attendance', 'Attendance', 'Edit attendance'),
(102, 'delete_attendance', 'Attendance', 'Delete attendance'),
(103, 'export_attendance', 'Attendance', 'Export attendance records'),
(104, 'import_attendance', 'Attendance', 'Import attendance records'),
(105, 'view_attendance_report', 'Attendance', 'View attendance report'),
(106, 'export_attendance_report', 'Attendance', 'Export attendance report'),
(107, 'view_payment_list', 'Payments', 'View payment list'),
(108, 'view_payment_history', 'Payments', 'View payment history'),
(109, 'make_payment', 'Payments', 'Make a payment'),
(110, 'create_payment', 'Payments', 'Create a payment'),
(111, 'edit_payment', 'Payments', 'Edit a payment'),
(112, 'delete_payment', 'Payments', 'Delete a payment'),
(113, 'reverse_payment', 'Payments', 'Reverse a payment'),
(114, 'view_payment_reversal_log', 'Payments', 'View payment reversal log'),
(115, 'export_payment', 'Payments', 'Export payment data'),
(116, 'import_payment', 'Payments', 'Import payment data'),
(117, 'view_payment_bulk', 'Payments', 'View bulk payment UI'),
(118, 'submit_bulk_payment', 'Payments', 'Submit bulk payment'),
(119, 'view_payment_bulk_summary', 'Payments', 'View bulk payment summary'),
(120, 'view_payment_types_today', 'Payments', 'View payment types for today'),
(121, 'view_payment_total_today', 'Payments', 'View total payments for today'),
(122, 'resend_payment_sms', 'Payments', 'Resend payment SMS'),
(123, 'view_paystack_callback', 'Payments', 'View Paystack callback'),
(124, 'view_hubtel_callback', 'Payments', 'View Hubtel callback'),
(125, 'view_reports_dashboard', 'Reports', 'View reports dashboard'),
(126, 'view_audit_report', 'Reports', 'View audit report'),
(127, 'view_event_report', 'Reports', 'View event report'),
(128, 'view_feedback_report', 'Reports', 'View feedback report'),
(129, 'view_health_report', 'Reports', 'View health report'),
(130, 'view_sms_report', 'Reports', 'View SMS report'),
(131, 'view_visitor_report', 'Reports', 'View visitor report'),
(132, 'view_membership_report', 'Reports', 'View membership report'),
(133, 'view_payment_report', 'Reports', 'View payment report'),
(134, 'export_payment_report', 'Reports', 'Export payment report'),
(135, 'export_health_report', 'Reports', 'Export health report'),
(136, 'export_feedback_report', 'Reports', 'Export feedback report'),
(137, 'export_membership_report', 'Reports', 'Export membership report'),
(138, 'export_visitor_report', 'Reports', 'Export visitor report'),
(139, 'view_accumulated_payment_type_report', 'Reports', 'View accumulated payment type report'),
(140, 'view_age_bracket_payment_report', 'Reports', 'View age bracket payment report'),
(141, 'view_age_bracket_report', 'Reports', 'View age bracket report'),
(142, 'view_baptism_report', 'Reports', 'View baptism report'),
(143, 'view_bibleclass_payment_report', 'Reports', 'View bibleclass payment report'),
(144, 'view_class_health_report', 'Reports', 'View class health report'),
(145, 'view_confirmation_report', 'Reports', 'View confirmation report'),
(146, 'view_date_of_birth_report', 'Reports', 'View date of birth report'),
(147, 'view_day_born_payment_report', 'Reports', 'View day born payment report'),
(148, 'view_employment_status_report', 'Reports', 'View employment status report'),
(149, 'view_gender_report', 'Reports', 'View gender report'),
(150, 'view_health_type_report', 'Reports', 'View health type report'),
(151, 'view_individual_health_report', 'Reports', 'View individual health report'),
(152, 'view_individual_payment_report', 'Reports', 'View individual payment report'),
(153, 'view_marital_status_report', 'Reports', 'View marital status report'),
(154, 'view_membership_status_report', 'Reports', 'View membership status report'),
(155, 'view_organisation_payment_report', 'Reports', 'View organisation payment report'),
(156, 'view_organisational_health_report', 'Reports', 'View organisational health report'),
(157, 'view_organisational_member_report', 'Reports', 'View organisational member report'),
(158, 'view_payment_made_report', 'Reports', 'View payment made report'),
(159, 'view_profession_report', 'Reports', 'View profession report'),
(160, 'view_registered_by_date_report', 'Reports', 'View registered by date report'),
(161, 'view_role_of_service_report', 'Reports', 'View role of service report'),
(162, 'view_zero_payment_type_report', 'Reports', 'View zero payment type report'),
(163, 'view_bibleclass_list', 'Bible Classes', 'View bibleclass list'),
(164, 'create_bibleclass', 'Bible Classes', 'Create bibleclass'),
(165, 'edit_bibleclass', 'Bible Classes', 'Edit bibleclass'),
(166, 'delete_bibleclass', 'Bible Classes', 'Delete bibleclass'),
(167, 'assign_bibleclass_leader', 'Bible Classes', 'Assign bibleclass leader'),
(168, 'remove_bibleclass_leader', 'Bible Classes', 'Remove bibleclass leader'),
(169, 'upload_bibleclass', 'Bible Classes', 'Upload bibleclass'),
(170, 'export_bibleclass', 'Bible Classes', 'Export bibleclass'),
(171, 'view_classgroup_list', 'Class Groups', 'View classgroup list'),
(172, 'create_classgroup', 'Class Groups', 'Create classgroup'),
(173, 'edit_classgroup', 'Class Groups', 'Edit classgroup'),
(174, 'delete_classgroup', 'Class Groups', 'Delete classgroup'),
(175, 'view_organization_list', 'Organizations', 'View organization list'),
(176, 'create_organization', 'Organizations', 'Create organization'),
(177, 'edit_organization', 'Organizations', 'Edit organization'),
(178, 'delete_organization', 'Organizations', 'Delete organization'),
(179, 'upload_organization', 'Organizations', 'Upload organization'),
(180, 'export_organization', 'Organizations', 'Export organization'),
(181, 'view_event_list', 'Events', 'View event list'),
(182, 'create_event', 'Events', 'Create event'),
(183, 'edit_event', 'Events', 'Edit event'),
(184, 'delete_event', 'Events', 'Delete event'),
(185, 'register_event', 'Events', 'Register for event'),
(186, 'view_event_registration_list', 'Events', 'View event registration list'),
(187, 'view_event_registration', 'Events', 'View event registration'),
(188, 'export_event', 'Events', 'Export event'),
(189, 'view_feedback_list', 'Feedback', 'View feedback list'),
(190, 'create_feedback', 'Feedback', 'Create feedback'),
(191, 'edit_feedback', 'Feedback', 'Edit feedback'),
(192, 'delete_feedback', 'Feedback', 'Delete feedback'),
(193, 'respond_feedback', 'Feedback', 'Respond to feedback'),
(194, 'view_memberfeedback_list', 'Feedback', 'View member feedback list'),
(195, 'view_memberfeedback_thread', 'Feedback', 'View member feedback thread'),
(196, 'view_memberfeedback_my', 'Feedback', 'View my member feedback'),
(197, 'view_health_list', 'Health', 'View health list'),
(198, 'create_health_record', 'Health', 'Create health record'),
(199, 'edit_health_record', 'Health', 'Edit health record'),
(200, 'delete_health_record', 'Health', 'Delete health record'),
(201, 'export_health', 'Health', 'Export health records'),
(202, 'import_health', 'Health', 'Import health records'),
(203, 'view_health_records', 'Health', 'View health records'),
(204, 'view_health_form_prefill', 'Health', 'View health form prefill'),
(205, 'view_health_bp_graph', 'Health', 'View health BP graph'),
(206, 'view_sms_log', 'SMS', 'View SMS log'),
(207, 'send_sms', 'SMS', 'Send SMS'),
(208, 'resend_sms', 'SMS', 'Resend SMS'),
(209, 'view_sms_logs', 'SMS', 'View SMS logs'),
(210, 'export_sms_logs', 'SMS', 'Export SMS logs'),
(211, 'manage_sms_templates', 'SMS', 'Manage SMS templates'),
(212, 'view_sms_settings', 'SMS', 'View SMS settings'),
(213, 'send_bulk_sms', 'SMS', 'Send bulk SMS'),
(214, 'send_member_message', 'SMS', 'Send member message'),
(215, 'view_visitor_sms_modal', 'SMS', 'View visitor SMS modal'),
(216, 'view_visitor_send_sms', 'SMS', 'View visitor send SMS'),
(217, 'view_sms_bulk', 'SMS', 'View SMS bulk'),
(218, 'view_visitor_list', 'Visitors', 'View visitor list'),
(219, 'create_visitor', 'Visitors', 'Create visitor'),
(220, 'edit_visitor', 'Visitors', 'Edit visitor'),
(221, 'delete_visitor', 'Visitors', 'Delete visitor'),
(222, 'convert_visitor', 'Visitors', 'Convert visitor'),
(223, 'send_visitor_sms', 'Visitors', 'Send visitor SMS'),
(224, 'export_visitor', 'Visitors', 'Export visitor'),
(225, 'view_sundayschool_list', 'Sunday School', 'View Sunday School list'),
(226, 'create_sundayschool', 'Sunday School', 'Create Sunday School'),
(227, 'edit_sundayschool', 'Sunday School', 'Edit Sunday School'),
(228, 'delete_sundayschool', 'Sunday School', 'Delete Sunday School'),
(229, 'transfer_sundayschool', 'Sunday School', 'Transfer Sunday School'),
(230, 'view_sundayschool_view', 'Sunday School', 'View Sunday School'),
(231, 'export_sundayschool', 'Sunday School', 'Export Sunday School'),
(232, 'import_sundayschool', 'Sunday School', 'Import Sunday School'),
(233, 'view_transfer_list', 'Transfers', 'View transfer list'),
(234, 'create_transfer', 'Transfers', 'Create transfer'),
(235, 'edit_transfer', 'Transfers', 'Edit transfer'),
(236, 'delete_transfer', 'Transfers', 'Delete transfer'),
(237, 'view_role_list', 'Roles & Permissions', 'View role list'),
(238, 'create_role', 'Roles & Permissions', 'Create role'),
(239, 'edit_role', 'Roles & Permissions', 'Edit role'),
(240, 'delete_role', 'Roles & Permissions', 'Delete role'),
(241, 'assign_role', 'Roles & Permissions', 'Assign role'),
(242, 'view_permission_list', 'Roles & Permissions', 'View permission list'),
(243, 'create_permission', 'Roles & Permissions', 'Create permission'),
(244, 'edit_permission', 'Roles & Permissions', 'Edit permission'),
(245, 'delete_permission', 'Roles & Permissions', 'Delete permission'),
(246, 'assign_permission', 'Roles & Permissions', 'Assign permission'),
(247, 'manage_roles', 'Roles & Permissions', 'Manage roles'),
(248, 'manage_permissions', 'Roles & Permissions', 'Manage permissions'),
(249, 'assign_permissions', 'Roles & Permissions', 'Assign permissions'),
(250, 'view_permission_audit_log', 'Roles & Permissions', 'View permission audit log'),
(251, 'use_permission_template', 'Roles & Permissions', 'Use permission template'),
(252, 'manage_permission_templates', 'Roles & Permissions', 'Manage permission templates'),
(253, 'view_activity_logs', 'Audit & Logs', 'View activity logs'),
(254, 'view_user_audit', 'Audit & Logs', 'View user audit'),
(255, 'create_user_audit', 'Audit & Logs', 'Create user audit'),
(256, 'edit_user_audit', 'Audit & Logs', 'Edit user audit'),
(257, 'delete_user_audit', 'Audit & Logs', 'Delete user audit'),
(258, 'export_audit', 'Audit & Logs', 'Export audit'),
(259, 'view_user_list', 'User Management', 'View user list'),
(260, 'create_user', 'User Management', 'Create user'),
(261, 'edit_user', 'User Management', 'Edit user'),
(262, 'delete_user', 'User Management', 'Delete user'),
(263, 'activate_user', 'User Management', 'Activate user'),
(264, 'deactivate_user', 'User Management', 'Deactivate user'),
(265, 'reset_password', 'User Management', 'Reset password'),
(266, 'forgot_password', 'User Management', 'Forgot password'),
(267, 'complete_registration', 'User Management', 'Complete registration'),
(268, 'complete_registration_admin', 'User Management', 'Complete registration as admin'),
(269, 'resend_registration_link', 'User Management', 'Resend registration link'),
(270, 'view_profile', 'User Management', 'View profile'),
(271, 'edit_profile', 'User Management', 'Edit profile'),
(272, 'access_ajax_bulk_members', 'AJAX/API', 'Access AJAX bulk members'),
(273, 'access_ajax_bulk_payment', 'AJAX/API', 'Access AJAX bulk payment'),
(274, 'access_ajax_bulk_payments_single_member', 'AJAX/API', 'Access AJAX bulk payments for a single member'),
(275, 'access_ajax_check_phone_duplicate', 'AJAX/API', 'Access AJAX check phone duplicate'),
(276, 'access_ajax_events', 'AJAX/API', 'Access AJAX events'),
(277, 'access_ajax_find_member_by_crn', 'AJAX/API', 'Access AJAX find member by CRN'),
(278, 'access_ajax_get_churches', 'AJAX/API', 'Access AJAX get churches'),
(279, 'access_ajax_get_classes_by_church', 'AJAX/API', 'Access AJAX get classes by church'),
(280, 'access_ajax_get_health_records', 'AJAX/API', 'Access AJAX get health records'),
(281, 'access_ajax_get_member_by_crn', 'AJAX/API', 'Access AJAX get member by CRN'),
(282, 'access_ajax_get_member_by_srn', 'AJAX/API', 'Access AJAX get member by SRN'),
(283, 'access_ajax_get_organizations_by_church', 'AJAX/API', 'Access AJAX get organizations by church'),
(284, 'access_ajax_get_person_by_id', 'AJAX/API', 'Access AJAX get person by ID'),
(285, 'access_ajax_get_total_payments', 'AJAX/API', 'Access AJAX get total payments'),
(286, 'access_ajax_hubtel_checkout', 'AJAX/API', 'Access AJAX Hubtel checkout'),
(287, 'access_ajax_members_by_church', 'AJAX/API', 'Access AJAX members by church'),
(288, 'access_ajax_payment_types', 'AJAX/API', 'Access AJAX payment types'),
(289, 'access_ajax_paystack_checkout', 'AJAX/API', 'Access AJAX Paystack checkout'),
(290, 'access_ajax_recent_payments', 'AJAX/API', 'Access AJAX recent payments'),
(291, 'access_ajax_resend_registration_link', 'AJAX/API', 'Access AJAX resend registration link'),
(292, 'access_ajax_resend_token_sms', 'AJAX/API', 'Access AJAX resend token SMS'),
(293, 'access_ajax_single_payment_member', 'AJAX/API', 'Access AJAX single payment member'),
(294, 'access_ajax_top_payment_types', 'AJAX/API', 'Access AJAX top payment types'),
(295, 'access_ajax_users_by_church', 'AJAX/API', 'Access AJAX users by church'),
(296, 'access_ajax_validate_member', 'AJAX/API', 'Access AJAX validate member'),
(297, 'view_bulk_payment', 'Bulk', 'View bulk payment UI'),
(298, 'submit_bulk_payment', 'Bulk', 'Submit bulk payment'),
(299, 'view_bulk_paystack_email_prompt', 'Bulk', 'View bulk Paystack email prompt'),
(300, 'upload_bulk_member', 'Bulk', 'Upload bulk member'),
(301, 'upload_bulk_organization', 'Bulk', 'Upload bulk organization'),
(302, 'edit_member_in_own_class', 'Advanced', 'Edit member in own class'),
(303, 'edit_member_in_own_church', 'Advanced', 'Edit member in own church'),
(304, 'view_report_for_own_org', 'Advanced', 'View report for own organization'),
(305, 'assign_leader_in_own_class', 'Advanced', 'Assign leader in own class'),
(306, 'make_payment_for_own_class', 'Advanced', 'Make payment for own class'),
(307, 'request_additional_permission', 'Advanced', 'Request additional permission'),
(308, 'view_system_logs', 'System', 'View system logs'),
(309, 'run_migrations', 'System', 'Run migrations'),
(310, 'access_admin_panel', 'System', 'Access admin panel'),
(311, 'backup_database', 'System', 'Backup database'),
(312, 'restore_database', 'System', 'Restore database'),
(313, 'manage_templates', 'System', 'Manage templates'),
(314, 'manage_settings', 'System', 'Manage settings'),
(315, 'manage_menu_items', NULL, 'Manage menu items (create, edit, delete, reorder)'),
(316, 'pending_members_list', NULL, NULL),
(317, 'view_organization_membership_approvals', NULL, 'View pending organization membership approval requests'),
(318, 'approve_organization_memberships', NULL, 'Approve organization membership requests from members'),
(319, 'reject_organization_memberships', NULL, 'Reject organization membership requests from members'),
(320, 'view_payments_by_user_report', 'Reports', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `permission_audit_log`
--

CREATE TABLE `permission_audit_log` (
  `id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `action` enum('add','remove','grant','deny','request','approve','reject') DEFAULT NULL,
  `target_type` enum('role','user','template','system') DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `permission_id` int(11) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permission_audit_log`
--

INSERT INTO `permission_audit_log` (`id`, `actor_user_id`, `action`, `target_type`, `target_id`, `permission_id`, `timestamp`, `details`) VALUES
(1, 38, 'deny', 'user', 38, NULL, '2025-07-18 20:58:35', 'denied'),
(2, 38, 'deny', 'user', 38, NULL, '2025-07-18 20:58:35', 'denied'),
(3, 38, 'deny', 'user', 38, NULL, '2025-07-18 20:59:52', 'denied'),
(4, 38, 'deny', 'user', 38, NULL, '2025-07-18 20:59:52', 'denied'),
(5, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:00:15', 'denied'),
(6, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:00:15', 'denied'),
(7, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:00:18', 'denied'),
(8, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:00:18', 'denied'),
(9, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:01', 'denied'),
(10, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:01', 'denied'),
(11, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:01', 'denied'),
(12, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(13, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(14, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(15, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(16, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(17, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(18, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(19, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(20, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(21, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(22, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(23, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(24, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(25, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(26, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(27, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(28, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(29, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(30, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(31, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(32, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(33, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(34, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(35, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(36, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(37, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(38, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(39, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(40, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(41, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(42, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(43, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(44, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(45, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(46, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(47, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(48, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(49, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(50, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(51, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:02', 'denied'),
(52, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(53, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(54, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(55, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(56, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(57, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(58, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(59, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(60, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(61, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(62, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(63, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(64, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(65, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(66, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:05:03', 'denied'),
(67, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(68, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(69, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(70, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(71, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(72, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(73, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(74, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(75, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(76, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(77, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(78, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(79, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(80, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(81, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(82, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(83, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(84, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(85, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(86, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(87, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(88, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(89, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(90, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(91, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(92, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(93, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(94, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(95, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(96, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(97, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(98, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(99, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(100, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(101, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(102, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(103, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(104, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(105, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(106, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(107, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(108, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(109, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(110, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(111, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(112, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(113, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(114, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(115, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(116, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(117, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(118, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(119, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(120, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(121, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(122, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(123, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(124, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:00', 'denied'),
(125, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:02', 'denied'),
(126, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:02', 'denied'),
(127, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:03', 'denied'),
(128, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:07:03', 'denied'),
(129, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:09:05', 'denied'),
(130, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:09:05', 'denied'),
(131, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:09:09', 'denied'),
(132, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:09:09', 'denied'),
(133, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(134, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(135, 38, 'deny', 'user', 38, 253, '2025-07-18 21:10:04', 'denied'),
(136, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(137, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(138, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(139, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(140, 38, 'deny', 'user', 38, 78, '2025-07-18 21:10:04', 'denied'),
(141, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(142, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(143, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(144, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(145, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(146, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(147, 38, 'deny', 'user', 38, 209, '2025-07-18 21:10:04', 'denied'),
(148, 38, 'deny', 'user', 38, 213, '2025-07-18 21:10:04', 'denied'),
(149, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(150, 38, 'deny', 'user', 38, 211, '2025-07-18 21:10:04', 'denied'),
(151, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(152, 38, 'deny', 'user', 38, 109, '2025-07-18 21:10:04', 'denied'),
(153, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(154, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(155, 38, 'deny', 'user', 38, 121, '2025-07-18 21:10:04', 'denied'),
(156, 38, 'deny', 'user', 38, 120, '2025-07-18 21:10:04', 'denied'),
(157, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(158, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(159, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(160, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(161, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(162, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(163, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(164, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(165, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(166, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(167, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(168, 38, 'deny', 'user', 38, 187, '2025-07-18 21:10:04', 'denied'),
(169, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(170, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(171, 38, 'deny', 'user', 38, 95, '2025-07-18 21:10:04', 'denied'),
(172, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(173, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(174, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(175, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(176, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(177, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(178, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(179, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(180, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(181, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:10:04', 'denied'),
(182, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(183, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(184, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(185, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(186, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(187, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(188, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(189, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(190, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(191, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(192, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(193, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(194, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(195, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(196, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(197, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(198, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(199, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(200, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(201, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(202, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(203, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(204, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(205, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(206, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(207, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(208, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(209, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(210, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(211, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(212, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(213, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(214, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(215, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(216, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(217, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(218, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(219, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(220, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(221, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(222, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(223, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(224, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(225, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(226, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(227, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(228, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(229, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(230, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(231, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(232, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(233, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(234, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(235, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(236, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(237, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(238, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(239, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(240, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:10:16', 'denied'),
(241, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(242, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(243, 38, 'deny', 'user', 38, 253, '2025-07-18 21:12:10', 'denied'),
(244, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(245, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(246, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(247, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(248, 38, 'deny', 'user', 38, 78, '2025-07-18 21:12:10', 'denied'),
(249, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(250, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(251, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(252, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(253, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(254, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(255, 38, 'deny', 'user', 38, 209, '2025-07-18 21:12:10', 'denied'),
(256, 38, 'deny', 'user', 38, 213, '2025-07-18 21:12:10', 'denied'),
(257, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(258, 38, 'deny', 'user', 38, 211, '2025-07-18 21:12:10', 'denied'),
(259, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(260, 38, 'deny', 'user', 38, 109, '2025-07-18 21:12:10', 'denied'),
(261, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(262, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(263, 38, 'deny', 'user', 38, 121, '2025-07-18 21:12:10', 'denied'),
(264, 38, 'deny', 'user', 38, 120, '2025-07-18 21:12:10', 'denied'),
(265, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(266, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(267, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(268, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(269, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(270, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(271, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(272, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(273, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(274, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(275, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(276, 38, 'deny', 'user', 38, 187, '2025-07-18 21:12:10', 'denied'),
(277, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(278, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(279, 38, 'deny', 'user', 38, 95, '2025-07-18 21:12:10', 'denied'),
(280, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(281, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(282, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(283, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(284, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(285, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(286, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(287, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:10', 'denied'),
(288, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:11', 'denied'),
(289, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:11', 'denied'),
(290, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(291, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(292, 38, 'deny', 'user', 38, 253, '2025-07-18 21:12:59', 'denied'),
(293, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(294, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(295, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(296, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(297, 38, 'deny', 'user', 38, 78, '2025-07-18 21:12:59', 'denied'),
(298, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(299, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(300, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(301, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(302, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(303, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(304, 38, 'deny', 'user', 38, 209, '2025-07-18 21:12:59', 'denied'),
(305, 38, 'deny', 'user', 38, 213, '2025-07-18 21:12:59', 'denied'),
(306, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(307, 38, 'deny', 'user', 38, 211, '2025-07-18 21:12:59', 'denied'),
(308, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(309, 38, 'deny', 'user', 38, 109, '2025-07-18 21:12:59', 'denied'),
(310, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(311, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(312, 38, 'deny', 'user', 38, 121, '2025-07-18 21:12:59', 'denied'),
(313, 38, 'deny', 'user', 38, 120, '2025-07-18 21:12:59', 'denied'),
(314, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(315, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(316, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(317, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(318, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(319, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(320, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(321, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(322, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(323, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(324, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(325, 38, 'deny', 'user', 38, 187, '2025-07-18 21:12:59', 'denied'),
(326, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(327, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(328, 38, 'deny', 'user', 38, 95, '2025-07-18 21:12:59', 'denied'),
(329, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(330, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(331, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(332, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(333, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(334, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(335, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(336, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(337, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(338, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:12:59', 'denied'),
(339, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(340, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(341, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(342, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(343, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(344, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(345, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(346, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(347, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(348, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(349, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(350, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(351, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(352, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(353, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(354, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(355, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(356, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(357, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(358, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(359, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(360, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(361, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(362, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(363, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(364, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(365, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(366, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(367, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(368, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(369, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(370, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(371, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(372, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(373, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(374, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(375, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(376, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(377, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(378, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(379, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(380, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(381, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(382, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(383, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(384, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(385, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(386, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(387, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(388, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(389, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(390, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(391, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(392, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(393, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(394, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(395, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(396, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(397, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:05', 'denied'),
(398, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(399, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(400, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(401, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(402, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(403, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(404, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(405, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(406, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(407, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(408, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(409, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(410, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(411, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(412, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(413, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(414, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(415, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(416, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(417, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(418, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(419, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(420, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(421, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(422, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(423, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(424, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(425, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(426, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(427, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(428, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(429, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(430, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(431, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(432, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(433, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(434, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(435, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(436, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(437, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(438, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(439, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(440, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(441, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(442, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(443, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(444, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(445, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(446, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(447, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(448, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(449, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(450, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(451, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(452, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(453, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(454, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(455, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(456, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:13:29', 'denied'),
(457, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(458, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(459, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(460, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(461, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(462, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(463, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(464, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(465, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(466, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(467, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(468, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(469, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(470, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(471, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(472, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(473, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(474, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(475, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(476, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(477, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(478, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(479, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(480, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(481, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(482, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(483, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(484, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(485, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(486, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(487, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(488, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(489, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(490, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(491, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(492, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(493, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(494, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(495, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(496, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(497, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(498, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(499, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(500, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(501, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(502, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(503, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(504, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(505, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(506, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(507, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(508, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(509, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(510, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(511, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(512, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(513, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(514, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(515, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:14:58', 'denied'),
(516, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:13', 'denied'),
(517, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:13', 'denied'),
(518, 38, 'deny', 'user', 38, 253, '2025-07-18 21:15:13', 'denied'),
(519, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:13', 'denied'),
(520, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:13', 'denied'),
(521, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:13', 'denied'),
(522, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:13', 'denied'),
(523, 38, 'deny', 'user', 38, 78, '2025-07-18 21:15:13', 'denied'),
(524, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(525, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(526, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(527, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(528, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(529, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(530, 38, 'deny', 'user', 38, 209, '2025-07-18 21:15:14', 'denied'),
(531, 38, 'deny', 'user', 38, 213, '2025-07-18 21:15:14', 'denied'),
(532, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(533, 38, 'deny', 'user', 38, 211, '2025-07-18 21:15:14', 'denied'),
(534, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(535, 38, 'deny', 'user', 38, 109, '2025-07-18 21:15:14', 'denied'),
(536, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(537, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(538, 38, 'deny', 'user', 38, 121, '2025-07-18 21:15:14', 'denied'),
(539, 38, 'deny', 'user', 38, 120, '2025-07-18 21:15:14', 'denied'),
(540, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(541, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(542, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(543, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(544, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(545, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(546, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(547, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(548, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(549, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(550, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(551, 38, 'deny', 'user', 38, 187, '2025-07-18 21:15:14', 'denied'),
(552, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(553, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(554, 38, 'deny', 'user', 38, 95, '2025-07-18 21:15:14', 'denied'),
(555, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(556, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(557, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(558, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(559, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(560, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(561, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(562, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(563, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(564, 38, 'deny', 'user', 38, NULL, '2025-07-18 21:15:14', 'denied'),
(565, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:18:33', 'denied'),
(566, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:18:41', 'denied'),
(567, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:19:01', 'denied'),
(568, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:19:29', 'denied'),
(569, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:20:31', 'denied'),
(570, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:21:53', 'denied'),
(571, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:22:18', 'denied'),
(572, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:22:34', 'denied'),
(573, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:31:18', 'denied'),
(574, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:34:14', 'denied'),
(575, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:36:27', 'denied'),
(576, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:38:31', 'denied'),
(577, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:43:43', 'denied'),
(578, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:46:11', 'denied'),
(579, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:46:22', 'denied'),
(580, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:47:37', 'denied'),
(581, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:47:43', 'denied'),
(582, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:49:36', 'denied'),
(583, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:50:05', 'denied'),
(584, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:53:24', 'denied'),
(585, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:55:44', 'denied'),
(586, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:55:45', 'denied'),
(587, 3, 'deny', 'user', 3, NULL, '2025-07-18 21:56:26', 'denied'),
(588, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:03:11', 'denied'),
(589, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:03:20', 'denied'),
(590, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:03:45', 'denied'),
(591, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:04:42', 'denied'),
(592, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:08:00', 'denied'),
(593, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:08:07', 'denied'),
(594, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:08:26', 'denied'),
(595, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:09:05', 'denied'),
(596, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:09:22', 'denied'),
(597, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:10:09', 'denied'),
(598, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:12:04', 'denied'),
(599, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:12:48', 'denied'),
(600, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:13:55', 'denied'),
(601, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:13:56', 'denied'),
(602, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:16:01', 'denied'),
(603, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:17:33', 'denied'),
(604, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:19:12', 'denied'),
(605, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:19:30', 'denied'),
(606, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:20:34', 'denied'),
(607, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:21:23', 'denied'),
(608, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:22:09', 'denied'),
(609, 3, 'deny', 'user', 3, NULL, '2025-07-18 22:23:25', 'denied'),
(610, 38, 'deny', 'user', 38, NULL, '2025-07-18 23:16:08', 'denied'),
(611, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:42:35', 'denied'),
(612, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:42:46', 'denied'),
(613, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:43:38', 'denied'),
(614, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:44:42', 'denied'),
(615, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:46:06', 'denied'),
(616, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:46:07', 'denied'),
(617, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:46:07', 'denied'),
(618, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:46:16', 'denied'),
(619, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:48:29', 'denied'),
(620, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:48:30', 'denied'),
(621, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:49:14', 'denied'),
(622, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:49:14', 'denied'),
(623, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:49:14', 'denied'),
(624, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:49:14', 'denied'),
(625, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:49:15', 'denied'),
(626, 3, 'deny', 'user', 3, NULL, '2025-07-19 15:49:15', 'denied'),
(627, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:13:54', 'denied'),
(628, 38, 'deny', 'user', 38, 218, '2025-07-19 17:14:15', 'denied'),
(629, 38, 'deny', 'user', 38, 247, '2025-07-19 17:15:41', 'denied'),
(630, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:17:52', 'denied'),
(631, 38, 'deny', 'user', 38, 310, '2025-07-19 17:17:52', 'denied'),
(632, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:18:01', 'denied'),
(633, 38, 'deny', 'user', 38, 211, '2025-07-19 17:18:04', 'denied'),
(634, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:18:10', 'denied'),
(635, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:18:34', 'denied'),
(636, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:28:14', 'denied'),
(637, 38, 'deny', 'user', 38, 310, '2025-07-19 17:28:14', 'denied'),
(638, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:28:28', 'denied'),
(639, 38, 'deny', 'user', 38, 310, '2025-07-19 17:28:28', 'denied'),
(640, 38, 'deny', 'user', 38, NULL, '2025-07-19 17:30:22', 'denied'),
(641, 38, 'deny', 'user', 38, 310, '2025-07-19 17:30:22', 'denied'),
(642, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:00:52', 'denied'),
(643, 38, 'deny', 'user', 38, 310, '2025-07-19 18:00:52', 'denied'),
(644, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:11:44', 'denied'),
(645, 38, 'deny', 'user', 38, 310, '2025-07-19 18:11:44', 'denied'),
(646, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:12:06', 'denied'),
(647, 38, 'deny', 'user', 38, 310, '2025-07-19 18:12:06', 'denied'),
(648, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:13:22', 'denied'),
(649, 38, 'deny', 'user', 38, 310, '2025-07-19 18:13:22', 'denied'),
(650, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:25:02', 'denied'),
(651, 38, 'deny', 'user', 38, 310, '2025-07-19 18:25:02', 'denied'),
(652, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:30:03', 'denied'),
(653, 38, 'deny', 'user', 38, 310, '2025-07-19 18:30:03', 'denied'),
(654, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:30:59', 'denied'),
(655, 38, 'deny', 'user', 38, 310, '2025-07-19 18:30:59', 'denied'),
(656, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(657, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(658, 38, 'deny', 'user', 38, 253, '2025-07-19 18:34:32', 'denied'),
(659, 38, 'deny', 'user', 38, 78, '2025-07-19 18:34:32', 'denied'),
(660, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(661, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(662, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(663, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(664, 38, 'deny', 'user', 38, 209, '2025-07-19 18:34:32', 'denied'),
(665, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(666, 38, 'deny', 'user', 38, 109, '2025-07-19 18:34:32', 'denied'),
(667, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(668, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(669, 38, 'deny', 'user', 38, 121, '2025-07-19 18:34:32', 'denied'),
(670, 38, 'deny', 'user', 38, 120, '2025-07-19 18:34:32', 'denied'),
(671, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(672, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(673, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(674, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(675, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(676, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(677, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(678, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(679, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(680, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(681, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(682, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(683, 38, 'deny', 'user', 38, 213, '2025-07-19 18:34:32', 'denied'),
(684, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(685, 38, 'deny', 'user', 38, 211, '2025-07-19 18:34:32', 'denied'),
(686, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(687, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(688, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(689, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(690, 38, 'deny', 'user', 38, 187, '2025-07-19 18:34:32', 'denied'),
(691, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(692, 38, 'deny', 'user', 38, 95, '2025-07-19 18:34:32', 'denied'),
(693, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(694, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(695, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(696, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(697, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(698, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(699, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(700, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(701, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(702, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(703, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(704, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:34:32', 'denied'),
(705, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(706, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(707, 38, 'deny', 'user', 38, 253, '2025-07-19 18:35:22', 'denied'),
(708, 38, 'deny', 'user', 38, 78, '2025-07-19 18:35:22', 'denied'),
(709, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(710, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(711, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(712, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(713, 38, 'deny', 'user', 38, 209, '2025-07-19 18:35:22', 'denied'),
(714, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(715, 38, 'deny', 'user', 38, 109, '2025-07-19 18:35:22', 'denied'),
(716, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(717, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(718, 38, 'deny', 'user', 38, 121, '2025-07-19 18:35:22', 'denied'),
(719, 38, 'deny', 'user', 38, 120, '2025-07-19 18:35:22', 'denied'),
(720, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(721, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(722, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(723, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(724, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(725, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(726, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(727, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(728, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(729, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(730, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(731, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(732, 38, 'deny', 'user', 38, 213, '2025-07-19 18:35:22', 'denied'),
(733, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(734, 38, 'deny', 'user', 38, 211, '2025-07-19 18:35:22', 'denied'),
(735, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(736, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(737, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(738, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(739, 38, 'deny', 'user', 38, 187, '2025-07-19 18:35:22', 'denied'),
(740, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(741, 38, 'deny', 'user', 38, 95, '2025-07-19 18:35:22', 'denied'),
(742, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(743, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(744, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(745, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(746, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(747, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(748, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied');
INSERT INTO `permission_audit_log` (`id`, `actor_user_id`, `action`, `target_type`, `target_id`, `permission_id`, `timestamp`, `details`) VALUES
(749, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(750, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(751, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(752, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(753, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:22', 'denied'),
(754, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(755, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(756, 38, 'deny', 'user', 38, 253, '2025-07-19 18:35:28', 'denied'),
(757, 38, 'deny', 'user', 38, 78, '2025-07-19 18:35:28', 'denied'),
(758, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(759, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(760, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(761, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(762, 38, 'deny', 'user', 38, 209, '2025-07-19 18:35:28', 'denied'),
(763, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(764, 38, 'deny', 'user', 38, 109, '2025-07-19 18:35:28', 'denied'),
(765, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(766, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(767, 38, 'deny', 'user', 38, 121, '2025-07-19 18:35:28', 'denied'),
(768, 38, 'deny', 'user', 38, 120, '2025-07-19 18:35:28', 'denied'),
(769, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(770, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(771, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(772, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(773, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(774, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(775, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(776, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(777, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(778, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(779, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(780, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(781, 38, 'deny', 'user', 38, 213, '2025-07-19 18:35:28', 'denied'),
(782, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(783, 38, 'deny', 'user', 38, 211, '2025-07-19 18:35:28', 'denied'),
(784, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(785, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(786, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(787, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(788, 38, 'deny', 'user', 38, 187, '2025-07-19 18:35:28', 'denied'),
(789, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(790, 38, 'deny', 'user', 38, 95, '2025-07-19 18:35:28', 'denied'),
(791, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(792, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(793, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(794, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(795, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(796, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(797, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(798, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(799, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(800, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(801, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(802, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:35:28', 'denied'),
(803, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(804, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(805, 38, 'deny', 'user', 38, 253, '2025-07-19 18:36:48', 'denied'),
(806, 38, 'deny', 'user', 38, 78, '2025-07-19 18:36:48', 'denied'),
(807, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(808, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(809, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(810, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(811, 38, 'deny', 'user', 38, 209, '2025-07-19 18:36:48', 'denied'),
(812, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(813, 38, 'deny', 'user', 38, 109, '2025-07-19 18:36:48', 'denied'),
(814, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(815, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(816, 38, 'deny', 'user', 38, 121, '2025-07-19 18:36:48', 'denied'),
(817, 38, 'deny', 'user', 38, 120, '2025-07-19 18:36:48', 'denied'),
(818, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(819, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(820, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(821, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(822, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(823, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(824, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(825, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(826, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(827, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(828, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(829, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(830, 38, 'deny', 'user', 38, 213, '2025-07-19 18:36:48', 'denied'),
(831, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(832, 38, 'deny', 'user', 38, 211, '2025-07-19 18:36:48', 'denied'),
(833, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(834, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(835, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(836, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(837, 38, 'deny', 'user', 38, 187, '2025-07-19 18:36:48', 'denied'),
(838, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(839, 38, 'deny', 'user', 38, 95, '2025-07-19 18:36:48', 'denied'),
(840, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(841, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:48', 'denied'),
(842, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(843, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(844, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(845, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(846, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(847, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(848, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(849, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(850, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(851, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:49', 'denied'),
(852, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(853, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(854, 38, 'deny', 'user', 38, 253, '2025-07-19 18:36:50', 'denied'),
(855, 38, 'deny', 'user', 38, 78, '2025-07-19 18:36:50', 'denied'),
(856, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(857, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(858, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(859, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(860, 38, 'deny', 'user', 38, 209, '2025-07-19 18:36:50', 'denied'),
(861, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(862, 38, 'deny', 'user', 38, 109, '2025-07-19 18:36:50', 'denied'),
(863, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(864, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(865, 38, 'deny', 'user', 38, 121, '2025-07-19 18:36:50', 'denied'),
(866, 38, 'deny', 'user', 38, 120, '2025-07-19 18:36:50', 'denied'),
(867, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(868, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(869, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(870, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(871, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(872, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(873, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(874, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(875, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(876, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(877, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(878, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(879, 38, 'deny', 'user', 38, 213, '2025-07-19 18:36:50', 'denied'),
(880, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(881, 38, 'deny', 'user', 38, 211, '2025-07-19 18:36:50', 'denied'),
(882, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(883, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(884, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(885, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(886, 38, 'deny', 'user', 38, 187, '2025-07-19 18:36:50', 'denied'),
(887, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(888, 38, 'deny', 'user', 38, 95, '2025-07-19 18:36:50', 'denied'),
(889, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(890, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(891, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(892, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(893, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(894, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(895, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(896, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(897, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(898, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(899, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(900, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:36:50', 'denied'),
(901, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(902, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(903, 38, 'deny', 'user', 38, 253, '2025-07-19 18:41:11', 'denied'),
(904, 38, 'deny', 'user', 38, 78, '2025-07-19 18:41:11', 'denied'),
(905, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(906, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(907, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(908, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(909, 38, 'deny', 'user', 38, 209, '2025-07-19 18:41:11', 'denied'),
(910, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(911, 38, 'deny', 'user', 38, 109, '2025-07-19 18:41:11', 'denied'),
(912, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(913, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(914, 38, 'deny', 'user', 38, 121, '2025-07-19 18:41:11', 'denied'),
(915, 38, 'deny', 'user', 38, 120, '2025-07-19 18:41:11', 'denied'),
(916, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(917, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(918, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(919, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(920, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(921, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(922, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(923, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(924, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(925, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(926, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(927, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(928, 38, 'deny', 'user', 38, 213, '2025-07-19 18:41:11', 'denied'),
(929, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(930, 38, 'deny', 'user', 38, 211, '2025-07-19 18:41:11', 'denied'),
(931, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(932, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(933, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(934, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(935, 38, 'deny', 'user', 38, 187, '2025-07-19 18:41:11', 'denied'),
(936, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(937, 38, 'deny', 'user', 38, 95, '2025-07-19 18:41:11', 'denied'),
(938, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(939, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(940, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(941, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(942, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(943, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(944, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(945, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(946, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(947, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(948, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(949, 38, 'deny', 'user', 38, NULL, '2025-07-19 18:41:11', 'denied'),
(950, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:37:48', 'denied'),
(951, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:37:52', 'denied'),
(952, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:39:22', 'denied'),
(953, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:39:47', 'denied'),
(954, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:40:06', 'denied'),
(955, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:40:11', 'denied'),
(956, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:40:15', 'denied'),
(957, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:40:19', 'denied'),
(958, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:40:20', 'denied'),
(959, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:40:21', 'denied'),
(960, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:40:32', 'denied'),
(961, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:42:23', 'denied'),
(962, 38, 'deny', 'user', 38, 100, '2025-07-19 19:44:13', 'denied'),
(963, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:44:15', 'denied'),
(964, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:51:58', 'denied'),
(965, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:53:17', 'denied'),
(966, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:53:26', 'denied'),
(967, 38, 'deny', 'user', 38, 111, '2025-07-19 19:53:26', 'denied'),
(968, 38, 'deny', 'user', 38, 112, '2025-07-19 19:53:26', 'denied'),
(969, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:53:26', 'denied'),
(970, 38, 'deny', 'user', 38, NULL, '2025-07-19 19:53:26', 'denied'),
(971, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:54:16', 'denied'),
(972, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:54:55', 'denied'),
(973, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:56:34', 'denied'),
(974, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:56:47', 'denied'),
(975, 3, 'deny', 'user', 3, NULL, '2025-07-19 19:57:59', 'denied'),
(976, 3, 'deny', 'user', 3, NULL, '2025-07-19 20:00:46', 'denied'),
(977, 3, 'deny', 'user', 3, NULL, '2025-07-19 20:02:46', 'denied'),
(978, 3, 'deny', 'user', 3, NULL, '2025-07-19 20:05:05', 'denied'),
(979, 3, 'deny', 'user', 3, NULL, '2025-07-19 20:05:22', 'denied'),
(980, 38, 'deny', 'user', 38, NULL, '2025-07-19 20:05:26', 'denied'),
(981, 3, 'deny', 'user', 3, NULL, '2025-07-19 20:28:11', 'denied'),
(982, 3, 'deny', 'user', 3, NULL, '2025-07-19 20:42:30', 'denied'),
(983, 3, 'deny', 'user', 3, NULL, '2025-07-19 20:43:03', 'denied'),
(984, 3, 'deny', 'user', 3, NULL, '2025-07-19 21:32:41', 'denied'),
(985, 3, 'deny', 'user', 3, NULL, '2025-07-19 21:32:49', 'denied'),
(986, 3, 'deny', 'user', 3, NULL, '2025-07-19 21:43:13', 'denied'),
(987, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:00:35', 'denied'),
(988, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:03:07', 'denied'),
(989, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:03:20', 'denied'),
(990, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:05:40', 'denied'),
(991, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:08:21', 'denied'),
(992, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:08:34', 'denied'),
(993, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:08:39', 'denied'),
(994, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:08:43', 'denied'),
(995, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:10:46', 'denied'),
(996, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:10:47', 'denied'),
(997, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:10:47', 'denied'),
(998, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:10:49', 'denied'),
(999, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:10:50', 'denied'),
(1000, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:10:53', 'denied'),
(1001, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:13:07', 'denied'),
(1002, 3, 'deny', 'user', 3, NULL, '2025-07-19 22:15:45', 'denied'),
(1003, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:18:14', 'denied'),
(1004, 38, 'deny', 'user', 38, 316, '2025-07-19 22:34:33', 'denied'),
(1005, 38, 'deny', 'user', 38, 316, '2025-07-19 22:34:35', 'denied'),
(1006, 38, 'deny', 'user', 38, 316, '2025-07-19 22:34:43', 'denied'),
(1007, 38, 'deny', 'user', 38, 316, '2025-07-19 22:34:45', 'denied'),
(1008, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:37:51', 'denied'),
(1009, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:39:25', 'denied'),
(1010, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:39:29', 'denied'),
(1011, 38, 'deny', 'user', 38, 111, '2025-07-19 22:39:29', 'denied'),
(1012, 38, 'deny', 'user', 38, 112, '2025-07-19 22:39:29', 'denied'),
(1013, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:39:29', 'denied'),
(1014, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:39:29', 'denied'),
(1015, 3, 'deny', 'user', 3, NULL, '2025-07-19 22:42:35', 'denied'),
(1016, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:43:25', 'denied'),
(1017, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:45:16', 'denied'),
(1018, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:52:11', 'denied'),
(1019, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:55:12', 'denied'),
(1020, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:55:12', 'denied'),
(1021, 38, 'deny', 'user', 38, 80, '2025-07-19 22:55:12', 'denied'),
(1022, 38, 'deny', 'user', 38, 81, '2025-07-19 22:55:12', 'denied'),
(1023, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:55:12', 'denied'),
(1024, 38, 'deny', 'user', 38, 80, '2025-07-19 22:55:12', 'denied'),
(1025, 38, 'deny', 'user', 38, 81, '2025-07-19 22:55:12', 'denied'),
(1026, 38, 'deny', 'user', 38, 85, '2025-07-19 22:55:12', 'denied'),
(1027, 38, 'deny', 'user', 38, NULL, '2025-07-19 22:55:12', 'denied'),
(1028, 3, 'deny', 'user', 3, NULL, '2025-07-19 23:00:16', 'denied'),
(1029, 3, 'deny', 'user', 3, NULL, '2025-07-19 23:01:51', 'denied'),
(1030, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:10:22', 'denied'),
(1031, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:10:35', 'denied'),
(1032, 38, 'deny', 'user', 38, 111, '2025-07-19 23:10:35', 'denied'),
(1033, 38, 'deny', 'user', 38, 112, '2025-07-19 23:10:35', 'denied'),
(1034, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:10:35', 'denied'),
(1035, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:10:35', 'denied'),
(1036, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:12:45', 'denied'),
(1037, 38, 'deny', 'user', 38, 111, '2025-07-19 23:12:45', 'denied'),
(1038, 38, 'deny', 'user', 38, 112, '2025-07-19 23:12:45', 'denied'),
(1039, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:12:45', 'denied'),
(1040, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:12:45', 'denied'),
(1041, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:12:53', 'denied'),
(1042, 38, 'deny', 'user', 38, 111, '2025-07-19 23:12:53', 'denied'),
(1043, 38, 'deny', 'user', 38, 112, '2025-07-19 23:12:53', 'denied'),
(1044, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:12:53', 'denied'),
(1045, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:12:53', 'denied'),
(1046, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:13:00', 'denied'),
(1047, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:13:11', 'denied'),
(1048, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:13:13', 'denied'),
(1049, 38, 'deny', 'user', 38, 111, '2025-07-19 23:13:13', 'denied'),
(1050, 38, 'deny', 'user', 38, 112, '2025-07-19 23:13:13', 'denied'),
(1051, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:13:13', 'denied'),
(1052, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:13:13', 'denied'),
(1053, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:18:43', 'denied'),
(1054, 38, 'deny', 'user', 38, 111, '2025-07-19 23:18:43', 'denied'),
(1055, 38, 'deny', 'user', 38, 112, '2025-07-19 23:18:43', 'denied'),
(1056, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:18:43', 'denied'),
(1057, 38, 'deny', 'user', 38, NULL, '2025-07-19 23:18:43', 'denied'),
(1058, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:30:09', 'denied'),
(1059, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:30:10', 'denied'),
(1060, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:30:12', 'denied'),
(1061, 38, 'deny', 'user', 38, 111, '2025-07-20 15:30:12', 'denied'),
(1062, 38, 'deny', 'user', 38, 112, '2025-07-20 15:30:12', 'denied'),
(1063, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:30:12', 'denied'),
(1064, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:30:12', 'denied'),
(1065, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:35:01', 'denied'),
(1066, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:56:09', 'denied'),
(1067, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:56:20', 'denied'),
(1068, 38, 'deny', 'user', 38, 111, '2025-07-20 15:56:20', 'denied'),
(1069, 38, 'deny', 'user', 38, 112, '2025-07-20 15:56:20', 'denied'),
(1070, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:56:20', 'denied'),
(1071, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:56:20', 'denied'),
(1072, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:57:00', 'denied'),
(1073, 38, 'deny', 'user', 38, 111, '2025-07-20 15:57:00', 'denied'),
(1074, 38, 'deny', 'user', 38, 112, '2025-07-20 15:57:00', 'denied'),
(1075, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:57:00', 'denied'),
(1076, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:57:00', 'denied'),
(1077, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:58:32', 'denied'),
(1078, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:58:47', 'denied'),
(1079, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:58:49', 'denied'),
(1080, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:58:49', 'denied'),
(1081, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:58:49', 'denied'),
(1082, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:59:05', 'denied'),
(1083, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:59:05', 'denied'),
(1084, 38, 'deny', 'user', 38, 80, '2025-07-20 15:59:05', 'denied'),
(1085, 38, 'deny', 'user', 38, 81, '2025-07-20 15:59:05', 'denied'),
(1086, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:59:05', 'denied'),
(1087, 38, 'deny', 'user', 38, 80, '2025-07-20 15:59:05', 'denied'),
(1088, 38, 'deny', 'user', 38, 81, '2025-07-20 15:59:05', 'denied'),
(1089, 38, 'deny', 'user', 38, 85, '2025-07-20 15:59:05', 'denied'),
(1090, 38, 'deny', 'user', 38, NULL, '2025-07-20 15:59:05', 'denied'),
(1091, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:47:11', 'denied'),
(1092, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:47:15', 'denied'),
(1093, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:48:51', 'denied'),
(1094, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:49:03', 'denied'),
(1095, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:50:18', 'denied'),
(1096, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:50:33', 'denied'),
(1097, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:50:42', 'denied'),
(1098, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:52:25', 'denied'),
(1099, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:52:39', 'denied'),
(1100, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:52:48', 'denied'),
(1101, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:53:16', 'denied'),
(1102, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:53:59', 'denied'),
(1103, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:54:14', 'denied'),
(1104, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:54:29', 'denied'),
(1105, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:55:37', 'denied'),
(1106, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:56:53', 'denied'),
(1107, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:57:02', 'denied'),
(1108, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:57:07', 'denied'),
(1109, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:57:22', 'denied'),
(1110, 38, 'deny', 'user', 38, NULL, '2025-07-20 16:57:33', 'denied'),
(1111, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:00:57', 'denied'),
(1112, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:01:11', 'denied'),
(1113, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:01:16', 'denied'),
(1114, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:01:24', 'denied'),
(1115, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:01:53', 'denied'),
(1116, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:02:09', 'denied'),
(1117, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:02:12', 'denied'),
(1118, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:22:28', 'denied'),
(1119, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:22:31', 'denied'),
(1120, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:22:44', 'denied'),
(1121, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:23:45', 'denied'),
(1122, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:23:59', 'denied'),
(1123, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:24:08', 'denied'),
(1124, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:31:08', 'denied'),
(1125, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:31:18', 'denied'),
(1126, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:31:24', 'denied'),
(1127, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:31:40', 'denied'),
(1128, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:31:42', 'denied'),
(1129, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:31:48', 'denied'),
(1130, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:32:30', 'denied'),
(1131, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:32:57', 'denied'),
(1132, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:33:03', 'denied'),
(1133, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:34:20', 'denied'),
(1134, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:40:16', 'denied'),
(1135, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:44:14', 'denied'),
(1136, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:44:20', 'denied'),
(1137, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:44:23', 'denied'),
(1138, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:44:28', 'denied'),
(1139, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:47:07', 'denied'),
(1140, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:48:44', 'denied'),
(1141, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:48:48', 'denied'),
(1142, 38, 'deny', 'user', 38, 85, '2025-07-20 17:50:23', 'denied'),
(1143, 38, 'deny', 'user', 38, 85, '2025-07-20 17:50:23', 'denied'),
(1144, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:51:00', 'denied'),
(1145, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:26', 'denied'),
(1146, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:32', 'denied'),
(1147, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:35', 'denied'),
(1148, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:38', 'denied'),
(1149, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:40', 'denied'),
(1150, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:43', 'denied'),
(1151, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:45', 'denied'),
(1152, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:50', 'denied'),
(1153, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:53', 'denied'),
(1154, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:52:55', 'denied'),
(1155, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:53:59', 'denied'),
(1156, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:56:49', 'denied'),
(1157, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:58:53', 'denied'),
(1158, 38, 'deny', 'user', 38, 101, '2025-07-20 17:58:53', 'denied'),
(1159, 38, 'deny', 'user', 38, 102, '2025-07-20 17:58:53', 'denied'),
(1160, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:59:00', 'denied'),
(1161, 38, 'deny', 'user', 38, 101, '2025-07-20 17:59:00', 'denied'),
(1162, 38, 'deny', 'user', 38, 102, '2025-07-20 17:59:00', 'denied'),
(1163, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:59:13', 'denied'),
(1164, 38, 'deny', 'user', 38, 101, '2025-07-20 17:59:13', 'denied'),
(1165, 38, 'deny', 'user', 38, 102, '2025-07-20 17:59:13', 'denied'),
(1166, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:59:16', 'denied'),
(1167, 38, 'deny', 'user', 38, 101, '2025-07-20 17:59:16', 'denied'),
(1168, 38, 'deny', 'user', 38, 102, '2025-07-20 17:59:16', 'denied'),
(1169, 38, 'deny', 'user', 38, 85, '2025-07-20 18:00:13', 'denied'),
(1170, 38, 'deny', 'user', 38, 85, '2025-07-20 18:00:13', 'denied'),
(1171, 38, 'deny', 'user', 38, 85, '2025-07-20 18:05:37', 'denied'),
(1172, 38, 'deny', 'user', 38, 85, '2025-07-20 18:05:37', 'denied'),
(1173, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:05:41', 'denied'),
(1174, 38, 'deny', 'user', 38, 85, '2025-07-20 18:12:53', 'denied'),
(1175, 38, 'deny', 'user', 38, 85, '2025-07-20 18:12:53', 'denied'),
(1176, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:13:32', 'denied'),
(1177, 38, 'deny', 'user', 38, 85, '2025-07-20 18:13:34', 'denied'),
(1178, 38, 'deny', 'user', 38, 85, '2025-07-20 18:13:34', 'denied'),
(1179, 38, 'deny', 'user', 38, 85, '2025-07-20 18:13:36', 'denied'),
(1180, 38, 'deny', 'user', 38, 85, '2025-07-20 18:13:36', 'denied'),
(1181, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:13:40', 'denied'),
(1182, 38, 'deny', 'user', 38, 85, '2025-07-20 18:15:20', 'denied'),
(1183, 38, 'deny', 'user', 38, 85, '2025-07-20 18:15:20', 'denied'),
(1184, 38, 'deny', 'user', 38, 85, '2025-07-20 18:16:22', 'denied'),
(1185, 38, 'deny', 'user', 38, 85, '2025-07-20 18:16:22', 'denied'),
(1186, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:19:24', 'denied'),
(1187, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:21:06', 'denied'),
(1188, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:23:42', 'denied'),
(1189, 38, 'deny', 'user', 38, 85, '2025-07-20 18:23:53', 'denied'),
(1190, 38, 'deny', 'user', 38, 85, '2025-07-20 18:23:53', 'denied'),
(1191, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:25:24', 'denied'),
(1192, 38, 'deny', 'user', 38, 81, '2025-07-20 18:25:28', 'denied'),
(1193, 38, 'deny', 'user', 38, 85, '2025-07-20 18:25:28', 'denied'),
(1194, 38, 'deny', 'user', 38, 85, '2025-07-20 18:25:28', 'denied'),
(1195, 38, 'deny', 'user', 38, 81, '2025-07-20 18:25:36', 'denied'),
(1196, 38, 'deny', 'user', 38, 85, '2025-07-20 18:25:36', 'denied'),
(1197, 38, 'deny', 'user', 38, 85, '2025-07-20 18:25:36', 'denied'),
(1198, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:28:46', 'denied'),
(1199, 38, 'deny', 'user', 38, 164, '2025-07-20 18:28:51', 'denied'),
(1200, 38, 'deny', 'user', 38, 165, '2025-07-20 18:28:51', 'denied'),
(1201, 38, 'deny', 'user', 38, 166, '2025-07-20 18:28:51', 'denied'),
(1202, 38, 'deny', 'user', 38, 163, '2025-07-20 18:30:37', 'denied'),
(1203, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:30:39', 'denied'),
(1204, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:30:42', 'denied'),
(1205, 38, 'deny', 'user', 38, 197, '2025-07-20 18:31:19', 'denied'),
(1206, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:32:11', 'denied'),
(1207, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:32:11', 'denied'),
(1208, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:32:11', 'denied'),
(1209, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:38:53', 'denied'),
(1210, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:38:53', 'denied'),
(1211, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:38:53', 'denied'),
(1212, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:01', 'denied'),
(1213, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:01', 'denied'),
(1214, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:01', 'denied'),
(1215, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:05', 'denied'),
(1216, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:05', 'denied'),
(1217, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:05', 'denied'),
(1218, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:11', 'denied'),
(1219, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:11', 'denied'),
(1220, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:11', 'denied'),
(1221, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:18', 'denied'),
(1222, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:18', 'denied'),
(1223, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:39:18', 'denied'),
(1224, 38, 'deny', 'user', 38, 198, '2025-07-20 18:42:23', 'denied'),
(1225, 38, 'deny', 'user', 38, 199, '2025-07-20 18:42:23', 'denied'),
(1226, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:23', 'denied'),
(1227, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:42', 'denied'),
(1228, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:42', 'denied'),
(1229, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:42', 'denied'),
(1230, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:57', 'denied'),
(1231, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:57', 'denied'),
(1232, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:57', 'denied'),
(1233, 38, 'deny', 'user', 38, 198, '2025-07-20 18:42:59', 'denied'),
(1234, 38, 'deny', 'user', 38, 199, '2025-07-20 18:42:59', 'denied'),
(1235, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:42:59', 'denied'),
(1236, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:43:07', 'denied'),
(1237, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:43:07', 'denied'),
(1238, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:43:07', 'denied'),
(1239, 38, 'deny', 'user', 38, 198, '2025-07-20 18:43:27', 'denied'),
(1240, 38, 'deny', 'user', 38, 199, '2025-07-20 18:43:27', 'denied'),
(1241, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:43:27', 'denied'),
(1242, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:07', 'denied'),
(1243, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:07', 'denied'),
(1244, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:07', 'denied'),
(1245, 38, 'deny', 'user', 38, 198, '2025-07-20 18:44:10', 'denied'),
(1246, 38, 'deny', 'user', 38, 199, '2025-07-20 18:44:10', 'denied'),
(1247, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:10', 'denied'),
(1248, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:12', 'denied'),
(1249, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:12', 'denied'),
(1250, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:12', 'denied'),
(1251, 38, 'deny', 'user', 38, 198, '2025-07-20 18:44:14', 'denied'),
(1252, 38, 'deny', 'user', 38, 199, '2025-07-20 18:44:14', 'denied'),
(1253, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:14', 'denied'),
(1254, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:25', 'denied'),
(1255, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:25', 'denied'),
(1256, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:25', 'denied'),
(1257, 38, 'deny', 'user', 38, 198, '2025-07-20 18:44:28', 'denied'),
(1258, 38, 'deny', 'user', 38, 199, '2025-07-20 18:44:28', 'denied'),
(1259, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:28', 'denied'),
(1260, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:30', 'denied'),
(1261, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:30', 'denied'),
(1262, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:30', 'denied'),
(1263, 38, 'deny', 'user', 38, 198, '2025-07-20 18:44:42', 'denied'),
(1264, 38, 'deny', 'user', 38, 199, '2025-07-20 18:44:42', 'denied'),
(1265, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:44:42', 'denied'),
(1266, 38, 'deny', 'user', 38, 85, '2025-07-20 18:45:43', 'denied'),
(1267, 38, 'deny', 'user', 38, 85, '2025-07-20 18:45:43', 'denied'),
(1268, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:47:51', 'denied'),
(1269, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:47:56', 'denied'),
(1270, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:47:56', 'denied'),
(1271, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:47:56', 'denied'),
(1272, 38, 'deny', 'user', 38, 198, '2025-07-20 18:47:58', 'denied'),
(1273, 38, 'deny', 'user', 38, 199, '2025-07-20 18:47:58', 'denied'),
(1274, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:47:58', 'denied'),
(1275, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:48:54', 'denied'),
(1276, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:48:54', 'denied'),
(1277, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:48:54', 'denied'),
(1278, 38, 'deny', 'user', 38, 198, '2025-07-20 18:49:04', 'denied'),
(1279, 38, 'deny', 'user', 38, 199, '2025-07-20 18:49:04', 'denied'),
(1280, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:49:04', 'denied'),
(1281, 38, 'deny', 'user', 38, 198, '2025-07-20 18:50:00', 'denied'),
(1282, 38, 'deny', 'user', 38, 199, '2025-07-20 18:50:00', 'denied'),
(1283, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:50:00', 'denied'),
(1284, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:54:02', 'denied'),
(1285, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:54:02', 'denied'),
(1286, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:54:02', 'denied'),
(1287, 38, 'deny', 'user', 38, 198, '2025-07-20 18:54:05', 'denied'),
(1288, 38, 'deny', 'user', 38, 199, '2025-07-20 18:54:05', 'denied'),
(1289, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:54:05', 'denied'),
(1290, 38, 'deny', 'user', 38, 198, '2025-07-20 18:57:26', 'denied'),
(1291, 38, 'deny', 'user', 38, 199, '2025-07-20 18:57:26', 'denied'),
(1292, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:57:26', 'denied'),
(1293, 38, 'deny', 'user', 38, 198, '2025-07-20 18:58:35', 'denied'),
(1294, 38, 'deny', 'user', 38, 199, '2025-07-20 18:58:35', 'denied'),
(1295, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:58:35', 'denied'),
(1296, 38, 'deny', 'user', 38, 198, '2025-07-20 18:58:41', 'denied'),
(1297, 38, 'deny', 'user', 38, 199, '2025-07-20 18:58:41', 'denied'),
(1298, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:58:41', 'denied'),
(1299, 38, 'deny', 'user', 38, 198, '2025-07-20 18:59:17', 'denied'),
(1300, 38, 'deny', 'user', 38, 199, '2025-07-20 18:59:17', 'denied'),
(1301, 38, 'deny', 'user', 38, NULL, '2025-07-20 18:59:17', 'denied'),
(1302, 38, 'deny', 'user', 38, NULL, '2025-07-20 19:00:28', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:11:54', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:12:22', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:12:41', 'denied'),
(0, 48, 'deny', 'user', 48, 310, '2025-07-30 22:12:41', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:12:49', 'denied'),
(0, 48, 'deny', 'user', 48, 310, '2025-07-30 22:12:49', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:13:39', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:14:00', 'denied'),
(0, 48, 'deny', 'user', 48, 310, '2025-07-30 22:14:01', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:14:10', 'denied'),
(0, 48, 'deny', 'user', 48, 310, '2025-07-30 22:14:10', 'denied'),
(0, 48, 'deny', 'user', 48, NULL, '2025-07-30 22:26:51', 'denied'),
(0, 48, 'deny', 'user', 48, 310, '2025-07-30 22:26:51', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:26:06', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:27:00', 'denied'),
(0, 4, 'deny', 'user', 4, 310, '2025-07-30 23:27:00', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:27:11', 'denied'),
(0, 4, 'deny', 'user', 4, 310, '2025-07-30 23:27:11', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:28:18', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:28:21', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:28:21', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:28:21', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:29:53', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:29:53', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:29:53', 'denied'),
(0, 4, 'deny', 'user', 4, 93, '2025-07-30 23:29:58', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:30:17', 'denied'),
(0, 4, 'deny', 'user', 4, 79, '2025-07-30 23:30:21', 'denied'),
(0, 4, 'deny', 'user', 4, 110, '2025-07-30 23:30:25', 'denied'),
(0, 4, 'deny', 'user', 4, 125, '2025-07-30 23:30:28', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:30:40', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:30:40', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:30:40', 'denied'),
(0, 4, 'deny', 'user', 4, 93, '2025-07-30 23:30:43', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:31:29', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:31:29', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:31:29', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:31:49', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:31:56', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:31:56', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-30 23:31:56', 'denied'),
(0, 4, 'deny', 'user', 4, 93, '2025-07-30 23:32:00', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 01:31:44', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 01:31:46', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 01:31:46', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 01:31:46', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 01:35:14', 'denied'),
(0, 4, 'deny', 'user', 4, 107, '2025-07-31 01:35:24', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:26:02', 'denied'),
(0, 4, 'deny', 'user', 4, 284, '2025-07-31 02:29:02', 'denied'),
(0, 4, 'deny', 'user', 4, 284, '2025-07-31 02:30:22', 'denied'),
(0, 4, 'deny', 'user', 4, 284, '2025-07-31 02:31:08', 'denied'),
(0, 4, 'deny', 'user', 4, 284, '2025-07-31 02:31:38', 'denied'),
(0, 4, 'deny', 'user', 4, 284, '2025-07-31 02:31:43', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:32:01', 'denied'),
(0, 4, 'deny', 'user', 4, 284, '2025-07-31 02:32:15', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:38:07', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:40:47', 'denied'),
(0, 4, 'deny', 'user', 4, 143, '2025-07-31 02:40:59', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 02:41:08', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:41:14', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:41:17', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 02:41:45', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:44:24', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:47:27', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 02:47:31', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:47:34', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:49:34', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 02:52:53', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:52:55', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 02:53:14', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:53:25', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:53:30', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 02:56:25', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:56:26', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 02:57:37', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 02:57:39', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:01:16', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:01:18', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:06:11', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:06:14', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:06:17', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:06:19', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:06:25', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:06:28', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:06:34', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:06:41', 'denied'),
(0, 4, 'deny', 'user', 4, 157, '2025-07-31 03:06:52', 'denied'),
(0, 4, 'deny', 'user', 4, 160, '2025-07-31 03:06:57', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:09:20', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:09:24', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:09:41', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:10:18', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:21:31', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:21:37', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:22:04', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:22:06', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:24:41', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:24:43', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:29:32', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:29:34', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:31:36', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:32:33', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:32:35', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:32:38', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:33:16', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:33:18', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:33:23', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:33:27', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:37:04', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:37:07', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:37:08', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:37:15', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:39:53', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:39:55', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:41:01', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:41:03', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:44:41', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:49:49', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:49:52', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:51:54', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:52:49', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:53:08', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:53:10', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:53:13', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:53:15', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:53:56', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:53:57', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:54:06', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:54:07', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:54:17', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:55:18', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:57:02', 'denied'),
(0, 4, 'deny', 'user', 4, 135, '2025-07-31 03:57:10', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:57:12', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:58:42', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:58:45', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:58:49', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:58:53', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 03:59:19', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 04:01:00', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 04:01:28', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 04:01:31', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 04:02:28', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 04:02:40', 'denied'),
(0, 4, 'deny', 'user', 4, NULL, '2025-07-31 04:17:35', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:33:53', 'denied'),
(0, 27, 'deny', 'user', 27, 310, '2025-08-09 07:33:53', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:34:02', 'denied'),
(0, 27, 'deny', 'user', 27, 310, '2025-08-09 07:34:02', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:34:50', 'denied'),
(0, 27, 'deny', 'user', 27, 164, '2025-08-09 07:36:34', 'denied'),
(0, 27, 'deny', 'user', 27, 165, '2025-08-09 07:36:34', 'denied'),
(0, 27, 'deny', 'user', 27, 166, '2025-08-09 07:36:34', 'denied'),
(0, 27, 'deny', 'user', 27, 295, '2025-08-09 07:37:04', 'denied'),
(0, 27, 'deny', 'user', 27, 295, '2025-08-09 07:37:06', 'denied'),
(0, 27, 'deny', 'user', 27, 163, '2025-08-09 07:37:24', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:37:26', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:37:28', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:37:30', 'denied'),
(0, 27, 'deny', 'user', 27, 163, '2025-08-09 07:37:32', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:37:45', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:38:15', 'denied'),
(0, 27, 'deny', 'user', 27, 150, '2025-08-09 07:38:15', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:38:19', 'denied'),
(0, 27, 'deny', 'user', 27, 150, '2025-08-09 07:38:19', 'denied'),
(0, 27, 'deny', 'user', 27, 133, '2025-08-09 07:38:33', 'denied'),
(0, 27, 'deny', 'user', 27, 133, '2025-08-09 07:38:37', 'denied'),
(0, 27, 'deny', 'user', 27, 110, '2025-08-09 07:38:39', 'denied'),
(0, 27, 'deny', 'user', 27, 110, '2025-08-09 07:38:42', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:38:42', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:38:42', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:38:42', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:38:42', 'denied'),
(0, 27, 'deny', 'user', 27, 110, '2025-08-09 07:39:19', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:40:24', 'denied'),
(0, 27, 'deny', 'user', 27, 133, '2025-08-09 07:40:30', 'denied'),
(0, 27, 'deny', 'user', 27, 133, '2025-08-09 07:40:33', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:40:36', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:40:36', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:40:36', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:40:36', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:40:44', 'denied'),
(0, 27, 'deny', 'user', 27, 284, '2025-08-09 07:40:59', 'denied'),
(0, 27, 'deny', 'user', 27, 284, '2025-08-09 07:41:03', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:42:02', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:42:02', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:42:02', 'denied');
INSERT INTO `permission_audit_log` (`id`, `actor_user_id`, `action`, `target_type`, `target_id`, `permission_id`, `timestamp`, `details`) VALUES
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:42:02', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:43:12', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:43:30', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:43:30', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:43:30', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:43:30', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:45:21', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:45:34', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:45:34', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:45:34', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:45:34', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:45:36', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:45:39', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:45:39', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:45:39', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:45:39', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:45:41', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:47:46', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:47:46', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:47:46', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:47:46', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:47:52', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-09 07:51:06', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-09 07:51:06', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:51:06', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-09 07:51:06', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:32:00', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:32:03', 'denied'),
(0, 27, 'deny', 'user', 27, 150, '2025-08-20 06:32:03', 'denied'),
(0, 27, 'deny', 'user', 27, 133, '2025-08-20 06:32:06', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:32:43', 'denied'),
(0, 27, 'deny', 'user', 27, 150, '2025-08-20 06:32:43', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:33:53', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:33:56', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:33:58', 'denied'),
(0, 27, 'deny', 'user', 27, 150, '2025-08-20 06:33:58', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:39:21', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:39:22', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:39:30', 'denied'),
(0, 27, 'deny', 'user', 27, 150, '2025-08-20 06:39:30', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:40:49', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:41:03', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:41:08', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:41:55', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:41:57', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:42:06', 'denied'),
(0, 27, 'deny', 'user', 27, 150, '2025-08-20 06:42:06', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:42:07', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:49:26', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:59:43', 'denied'),
(0, 27, 'deny', 'user', 27, 134, '2025-08-20 06:59:48', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-20 06:59:52', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-20 06:59:52', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:59:52', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:59:52', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:59:52', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-20 06:59:55', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-20 06:59:55', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:59:55', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:59:55', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 06:59:55', 'denied'),
(0, 27, 'deny', 'user', 27, 111, '2025-08-20 07:00:01', 'denied'),
(0, 27, 'deny', 'user', 27, 112, '2025-08-20 07:00:01', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:00:01', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:00:01', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:00:01', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:00:05', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:00:07', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:02:16', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:02:18', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:03:51', 'denied'),
(0, 27, 'deny', 'user', 27, NULL, '2025-08-20 07:03:53', 'denied'),
(0, 27, 'deny', 'user', 27, 125, '2025-08-20 07:04:12', 'denied');

-- --------------------------------------------------------

--
-- Table structure for table `permission_templates`
--

CREATE TABLE `permission_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Super Admin', NULL),
(2, 'ADMIN', ''),
(3, 'STEWARDS', ''),
(4, 'Rev. Ministers', ''),
(5, 'Class Leader', NULL),
(6, 'Organizational Leader', NULL),
(7, 'Cashier', NULL),
(8, 'HEALTH', 'Medical team'),
(10, 'SUNDAY SCHOOL', 'In charge of Sunday School Activities');

-- --------------------------------------------------------

--
-- Table structure for table `roles_of_serving`
--

CREATE TABLE `roles_of_serving` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles_of_serving`
--

INSERT INTO `roles_of_serving` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'BIBLE CLASS LEADER', 'Leading one of the bible class', '2025-08-04 18:55:13', '2025-08-04 18:55:13'),
(2, 'STEWARDS', 'In charge of church\'s business', '2025-08-04 20:25:45', '2025-08-04 20:25:45'),
(3, 'ADMINISTRATOR', 'In charge of the administrative works of the church', '2025-08-04 20:27:59', '2025-08-04 20:27:59'),
(4, 'ORGANIZATIONAL LEARDER', 'A leader of an organization', '2025-08-04 20:28:49', '2025-08-04 20:28:49'),
(5, 'CASH CHECKERS', 'Members who counts the church\'s monies', '2025-08-04 20:30:36', '2025-08-04 20:30:36'),
(6, 'NONE', 'Member has no actives  serve for the church', '2025-08-04 20:54:33', '2025-08-04 20:54:33');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 77),
(1, 78),
(1, 79),
(1, 80),
(1, 81),
(1, 82),
(1, 83),
(1, 84),
(1, 85),
(1, 86),
(1, 87),
(1, 88),
(1, 89),
(1, 90),
(1, 91),
(1, 92),
(1, 93),
(1, 94),
(1, 95),
(1, 96),
(1, 97),
(1, 98),
(1, 99),
(1, 100),
(1, 101),
(1, 102),
(1, 103),
(1, 104),
(1, 105),
(1, 106),
(1, 107),
(1, 108),
(1, 109),
(1, 110),
(1, 111),
(1, 112),
(1, 113),
(1, 114),
(1, 115),
(1, 116),
(1, 117),
(1, 118),
(1, 119),
(1, 120),
(1, 121),
(1, 122),
(1, 123),
(1, 124),
(1, 125),
(1, 126),
(1, 127),
(1, 128),
(1, 129),
(1, 130),
(1, 131),
(1, 132),
(1, 133),
(1, 134),
(1, 135),
(1, 136),
(1, 137),
(1, 138),
(1, 139),
(1, 140),
(1, 141),
(1, 142),
(1, 143),
(1, 144),
(1, 145),
(1, 146),
(1, 147),
(1, 148),
(1, 149),
(1, 150),
(1, 151),
(1, 152),
(1, 153),
(1, 154),
(1, 155),
(1, 156),
(1, 157),
(1, 158),
(1, 159),
(1, 160),
(1, 161),
(1, 162),
(1, 163),
(1, 164),
(1, 165),
(1, 166),
(1, 167),
(1, 168),
(1, 169),
(1, 170),
(1, 171),
(1, 172),
(1, 173),
(1, 174),
(1, 175),
(1, 176),
(1, 177),
(1, 178),
(1, 179),
(1, 180),
(1, 181),
(1, 182),
(1, 183),
(1, 184),
(1, 185),
(1, 186),
(1, 187),
(1, 188),
(1, 189),
(1, 190),
(1, 191),
(1, 192),
(1, 193),
(1, 194),
(1, 195),
(1, 196),
(1, 197),
(1, 198),
(1, 199),
(1, 200),
(1, 201),
(1, 202),
(1, 203),
(1, 204),
(1, 205),
(1, 206),
(1, 207),
(1, 208),
(1, 209),
(1, 210),
(1, 211),
(1, 212),
(1, 213),
(1, 214),
(1, 215),
(1, 216),
(1, 217),
(1, 218),
(1, 219),
(1, 220),
(1, 221),
(1, 222),
(1, 223),
(1, 224),
(1, 225),
(1, 226),
(1, 227),
(1, 228),
(1, 229),
(1, 230),
(1, 231),
(1, 232),
(1, 233),
(1, 234),
(1, 235),
(1, 236),
(1, 237),
(1, 238),
(1, 239),
(1, 240),
(1, 241),
(1, 242),
(1, 243),
(1, 244),
(1, 245),
(1, 246),
(1, 247),
(1, 248),
(1, 249),
(1, 250),
(1, 251),
(1, 252),
(1, 253),
(1, 254),
(1, 255),
(1, 256),
(1, 257),
(1, 258),
(1, 259),
(1, 260),
(1, 261),
(1, 262),
(1, 263),
(1, 264),
(1, 265),
(1, 266),
(1, 267),
(1, 268),
(1, 269),
(1, 270),
(1, 271),
(1, 272),
(1, 273),
(1, 274),
(1, 275),
(1, 276),
(1, 277),
(1, 278),
(1, 279),
(1, 280),
(1, 281),
(1, 282),
(1, 283),
(1, 284),
(1, 285),
(1, 286),
(1, 287),
(1, 288),
(1, 289),
(1, 290),
(1, 291),
(1, 292),
(1, 293),
(1, 294),
(1, 295),
(1, 296),
(1, 297),
(1, 298),
(1, 299),
(1, 300),
(1, 301),
(1, 302),
(1, 303),
(1, 304),
(1, 305),
(1, 306),
(1, 307),
(1, 308),
(1, 309),
(1, 310),
(1, 311),
(1, 312),
(1, 313),
(1, 314),
(1, 315),
(1, 317),
(1, 318),
(1, 319),
(8, 77),
(8, 79),
(8, 78),
(8, 93),
(8, 89),
(8, 144),
(8, 129),
(8, 150),
(8, 151),
(8, 156),
(8, 125),
(8, 198),
(8, 200),
(8, 199),
(8, 201),
(8, 202),
(8, 205),
(8, 204),
(8, 197),
(8, 203),
(8, 214),
(8, 272),
(8, 273),
(8, 274),
(8, 275),
(8, 276),
(8, 277),
(8, 278),
(8, 279),
(8, 280),
(8, 281),
(8, 282),
(8, 283),
(8, 284),
(8, 285),
(8, 286),
(8, 287),
(8, 288),
(8, 289),
(8, 290),
(8, 291),
(8, 292),
(8, 293),
(8, 294),
(8, 295),
(8, 296),
(8, 310),
(2, 77),
(2, 85),
(2, 97),
(2, 79),
(2, 86),
(2, 81),
(2, 80),
(2, 92),
(2, 90),
(2, 82),
(2, 83),
(2, 87),
(2, 96),
(2, 88),
(2, 84),
(2, 78),
(2, 94),
(2, 95),
(2, 93),
(2, 91),
(2, 89),
(2, 102),
(2, 101),
(2, 103),
(2, 106),
(2, 104),
(2, 100),
(2, 99),
(2, 98),
(2, 105),
(2, 110),
(2, 112),
(2, 111),
(2, 115),
(2, 116),
(2, 122),
(2, 113),
(2, 298),
(2, 118),
(2, 124),
(2, 117),
(2, 119),
(2, 108),
(2, 107),
(2, 114),
(2, 121),
(2, 120),
(2, 123),
(2, 136),
(2, 135),
(2, 137),
(2, 134),
(2, 138),
(2, 139),
(2, 140),
(2, 141),
(2, 126),
(2, 142),
(2, 143),
(2, 144),
(2, 145),
(2, 146),
(2, 147),
(2, 148),
(2, 127),
(2, 128),
(2, 149),
(2, 129),
(2, 150),
(2, 151),
(2, 152),
(2, 153),
(2, 132),
(2, 154),
(2, 156),
(2, 157),
(2, 155),
(2, 158),
(2, 133),
(2, 159),
(2, 160),
(2, 125),
(2, 161),
(2, 130),
(2, 131),
(2, 162),
(2, 167),
(2, 164),
(2, 166),
(2, 165),
(2, 170),
(2, 168),
(2, 169),
(2, 163),
(2, 172),
(2, 174),
(2, 173),
(2, 171),
(2, 176),
(2, 178),
(2, 177),
(2, 180),
(2, 179),
(2, 175),
(2, 182),
(2, 184),
(2, 183),
(2, 188),
(2, 185),
(2, 181),
(2, 187),
(2, 186),
(2, 190),
(2, 192),
(2, 191),
(2, 193),
(2, 189),
(2, 194),
(2, 196),
(2, 195),
(2, 198),
(2, 200),
(2, 199),
(2, 201),
(2, 202),
(2, 205),
(2, 204),
(2, 197),
(2, 203),
(2, 210),
(2, 211),
(2, 208),
(2, 213),
(2, 214),
(2, 207),
(2, 217),
(2, 206),
(2, 209),
(2, 212),
(2, 216),
(2, 215),
(2, 222),
(2, 219),
(2, 221),
(2, 220),
(2, 224),
(2, 223),
(2, 218),
(2, 226),
(2, 228),
(2, 227),
(2, 231),
(2, 232),
(2, 229),
(2, 225),
(2, 230),
(2, 244),
(2, 248),
(2, 247),
(2, 251),
(2, 250),
(2, 242),
(2, 237),
(2, 256),
(2, 258),
(2, 253),
(2, 254),
(2, 263),
(2, 267),
(2, 268),
(2, 260),
(2, 264),
(2, 262),
(2, 271),
(2, 261),
(2, 266),
(2, 269),
(2, 265),
(2, 270),
(2, 259),
(2, 272),
(2, 273),
(2, 274),
(2, 275),
(2, 276),
(2, 277),
(2, 278),
(2, 279),
(2, 280),
(2, 281),
(2, 282),
(2, 283),
(2, 284),
(2, 285),
(2, 286),
(2, 287),
(2, 288),
(2, 289),
(2, 290),
(2, 291),
(2, 292),
(2, 293),
(2, 294),
(2, 295),
(2, 296),
(2, 300),
(2, 301),
(2, 297),
(2, 299),
(2, 305),
(2, 303),
(2, 302),
(2, 306),
(2, 307),
(2, 304),
(2, 310),
(2, 311),
(2, 314),
(2, 313),
(2, 312),
(2, 309),
(2, 308),
(2, 318),
(2, 315),
(2, 316),
(2, 319),
(2, 317),
(2, 320),
(1, 0),
(7, 77),
(7, 96),
(7, 78),
(7, 94),
(7, 95),
(7, 91),
(7, 89),
(7, 102),
(7, 101),
(7, 103),
(7, 106),
(7, 104),
(7, 100),
(7, 99),
(7, 98),
(7, 105),
(7, 110),
(7, 112),
(7, 115),
(7, 116),
(7, 109),
(7, 122),
(7, 118),
(7, 298),
(7, 117),
(7, 119),
(7, 108),
(7, 107),
(7, 114),
(7, 121),
(7, 120),
(7, 214),
(7, 219),
(7, 225),
(7, 272),
(7, 273),
(7, 274),
(7, 275),
(7, 276),
(7, 277),
(7, 278),
(7, 279),
(7, 280),
(7, 281),
(7, 282),
(7, 283),
(7, 284),
(7, 285),
(7, 286),
(7, 287),
(7, 288),
(7, 289),
(7, 290),
(7, 291),
(7, 292),
(7, 293),
(7, 294),
(7, 295),
(7, 296),
(7, 310),
(5, 77),
(5, 97),
(5, 79),
(5, 86),
(5, 91),
(5, 110),
(5, 298),
(5, 117),
(5, 108),
(5, 107),
(5, 121),
(5, 143),
(5, 144),
(5, 133),
(5, 171),
(5, 272),
(5, 273),
(5, 274),
(5, 275),
(5, 276),
(5, 277),
(5, 278),
(5, 279),
(5, 280),
(5, 281),
(5, 282),
(5, 283),
(5, 284),
(5, 285),
(5, 286),
(5, 287),
(5, 288),
(5, 289),
(5, 290),
(5, 291),
(5, 292),
(5, 293),
(5, 294),
(5, 295),
(5, 296),
(5, 310),
(6, 77),
(6, 92),
(6, 91),
(6, 98),
(6, 109),
(6, 108),
(6, 121),
(6, 120),
(6, 156),
(6, 157),
(6, 155),
(6, 177),
(6, 180),
(6, 179),
(6, 175),
(6, 310),
(4, 77),
(4, 97),
(4, 86),
(4, 81),
(4, 80),
(4, 90),
(4, 83),
(4, 87),
(4, 96),
(4, 88),
(4, 78),
(4, 94),
(4, 95),
(4, 93),
(4, 91),
(4, 89),
(4, 103),
(4, 106),
(4, 104),
(4, 100),
(4, 99),
(4, 98),
(4, 105),
(4, 124),
(4, 119),
(4, 108),
(4, 107),
(4, 121),
(4, 120),
(4, 153),
(4, 157),
(4, 133),
(4, 159),
(4, 131),
(4, 162),
(4, 167),
(4, 168),
(4, 163),
(4, 177),
(4, 180),
(4, 179),
(4, 175),
(4, 183),
(4, 188),
(4, 185),
(4, 181),
(4, 187),
(4, 186),
(4, 191),
(4, 193),
(4, 189),
(4, 194),
(4, 196),
(4, 195),
(4, 199),
(4, 201),
(4, 202),
(4, 205),
(4, 204),
(4, 197),
(4, 203),
(4, 214),
(4, 222),
(4, 219),
(4, 221),
(4, 220),
(4, 224),
(4, 223),
(4, 218),
(4, 227),
(4, 229),
(4, 225),
(4, 230),
(4, 233),
(4, 270),
(4, 310),
(3, 77),
(3, 85),
(3, 97),
(3, 79),
(3, 86),
(3, 80),
(3, 92),
(3, 82),
(3, 83),
(3, 87),
(3, 96),
(3, 88),
(3, 84),
(3, 78),
(3, 94),
(3, 95),
(3, 93),
(3, 91),
(3, 89),
(3, 146),
(3, 153),
(3, 132),
(3, 157),
(3, 167),
(3, 164),
(3, 166),
(3, 165),
(3, 170),
(3, 168),
(3, 169),
(3, 163),
(3, 173),
(3, 171),
(3, 175),
(3, 182),
(3, 184),
(3, 183),
(3, 188),
(3, 185),
(3, 181),
(3, 187),
(3, 186),
(3, 190),
(3, 192),
(3, 191),
(3, 193),
(3, 189),
(3, 194),
(3, 196),
(3, 195),
(3, 201),
(3, 202),
(3, 205),
(3, 204),
(3, 197),
(3, 203),
(3, 210),
(3, 211),
(3, 208),
(3, 213),
(3, 214),
(3, 207),
(3, 217),
(3, 206),
(3, 209),
(3, 212),
(3, 216),
(3, 215),
(3, 222),
(3, 219),
(3, 221),
(3, 220),
(3, 224),
(3, 223),
(3, 218),
(3, 231),
(3, 232),
(3, 229),
(3, 225),
(3, 230),
(3, 234),
(3, 236),
(3, 235),
(3, 233),
(3, 237),
(3, 254),
(3, 270),
(3, 259),
(3, 300),
(3, 301),
(3, 297),
(3, 299),
(3, 310),
(10, 77),
(10, 96),
(10, 84),
(10, 78),
(10, 94),
(10, 95),
(10, 93),
(10, 91),
(10, 89),
(10, 102),
(10, 101),
(10, 103),
(10, 106),
(10, 104),
(10, 100),
(10, 99),
(10, 98),
(10, 105),
(10, 109),
(10, 118),
(10, 117),
(10, 108),
(10, 107),
(10, 114),
(10, 121),
(10, 120),
(10, 152),
(10, 132),
(10, 154),
(10, 156),
(10, 155),
(10, 125),
(10, 161),
(10, 131),
(10, 162),
(10, 171),
(10, 177),
(10, 180),
(10, 179),
(10, 175),
(10, 182),
(10, 184),
(10, 183),
(10, 188),
(10, 185),
(10, 181),
(10, 187),
(10, 186),
(10, 190),
(10, 192),
(10, 191),
(10, 193),
(10, 189),
(10, 194),
(10, 196),
(10, 195),
(10, 201),
(10, 202),
(10, 205),
(10, 204),
(10, 197),
(10, 203),
(10, 214),
(10, 222),
(10, 219),
(10, 221),
(10, 220),
(10, 224),
(10, 223),
(10, 218),
(10, 226),
(10, 228),
(10, 227),
(10, 231),
(10, 232),
(10, 229),
(10, 225),
(10, 230),
(10, 233),
(10, 270),
(10, 259),
(10, 301),
(10, 297),
(10, 299),
(10, 310),
(10, 315),
(10, 320);

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `phone` varchar(30) NOT NULL,
  `message` text NOT NULL,
  `template_name` varchar(100) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `provider` varchar(30) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `response` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sms_logs`
--

INSERT INTO `sms_logs` (`id`, `member_id`, `phone`, `message`, `template_name`, `type`, `status`, `provider`, `sent_at`, `response`) VALUES
(1, NULL, '0242363905', 'Hi BARNA, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=11702e69111b026eb9856ffacbb3cd68', NULL, 'registration', 'success', 'arkesel', '2025-08-03 19:20:24', '{\n    \"data\": [\n        {\n            \"id\": \"731de2d9-3e4b-443e-9c08-cb366b6b0aa4\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(2, NULL, '0553143607', 'Hi DANNY, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=c4ba7c8d58c9b647283139bb3fa24285', NULL, 'registration', 'success', 'arkesel', '2025-08-03 19:22:11', '{\n    \"data\": [\n        {\n            \"id\": \"7e9c83b3-56ba-4962-9963-4b777b5bef98\",\n            \"recipient\": \"233553143607\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(3, NULL, '0551756789', 'Hi JACK, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=d39d9298e842527fb8e4f62c38fc1d60', NULL, 'registration', 'success', 'arkesel', '2025-08-03 19:25:09', '{\n    \"data\": [\n        {\n            \"id\": \"2e13c19e-d823-4b03-a95d-82610ae3f1fc\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(4, NULL, '0545644749', 'Hello, {name}', 'Birthday', NULL, 'sent', 'unknown', '2025-08-04 09:54:57', '{\"data\":[{\"id\":\"deb92d7f-b434-4d18-8d5c-28ac7c63eabb\",\"recipient\":\"233545644749\"}],\"status\":\"success\"}'),
(5, NULL, '0242363905', 'Hi BARNA BAABIOLA, your payment of â‚µ60.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ60.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-04 15:13:30', '{\n    \"data\": [\n        {\n            \"id\": \"978d020c-a2e6-4dcf-b452-ba15e39705ac\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(6, NULL, '0242363905', 'Hi BARNA BAABIOLA, your payment of â‚µ50.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ110.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-04 16:20:20', '{\n    \"data\": [\n        {\n            \"id\": \"80aca5e3-f54c-4b4f-b208-98e34540dc0c\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(7, NULL, '0242363905', 'Hi BARNA BAABIOLA, your payment of â‚µ20.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ130.00', NULL, 'harvest_payment', 'fail', 'arkesel', '2025-08-04 16:39:46', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 500\",\n    \"http_code\": 500,\n    \"debug\": {\n        \"time\": \"2025-08-04 16:39:46\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi BARNA BAABIOLA, your payment of \\u20b520.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is \\u20b5130.00\",\n            \"recipients\": [\n                \"233242363905\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 500,\n        \"response_headers\": \"HTTP\\/1.1 500 Internal Server Error\\r\\nServer: nginx\\r\\nDate: Mon, 04 Aug 2025 16:39:46 GMT\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: private, must-revalidate\\r\\npragma: no-cache\\r\\nexpires: -1\\r\\nAccess-Control-Allow-Origin: *\\r\\n\\r\\n\",\n        \"response_body\": \"{\\n    \\\"message\\\": \\\"Server Error\\\"\\n}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 0.65458899999999997643129745483747683465480804443359375,\n            \"connect_time\": 0.02614199999999999857180910112219862639904022216796875,\n            \"namelookup_time\": 0.0001179999999999999950837936690817286944366060197353363037109375,\n            \"pretransfer_time\": 0.0628269999999999939621631028785486705601215362548828125,\n            \"starttransfer_time\": 0.654563000000000005940137270954437553882598876953125,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 249,\n            \"size_download\": 33,\n            \"speed_download\": 50,\n            \"speed_upload\": 380,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 249,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 5.161.241.15...\\n* TCP_NODELAY set\\n* Connected to sms.arkesel.com (5.161.241.15) port 443 (#0)\\n* ALPN, offering http\\/1.1\\n* successfully set certificate verify locations:\\n*   CAfile: \\/etc\\/pki\\/tls\\/certs\\/ca-bundle.crt\\n  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN, server accepted to use http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Aug  3 01:24:33 2025 GMT\\n*  expire date: Nov  1 01:24:32 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 249\\r\\n\\r\\n* upload completely sent off: 249 out of 249 bytes\\n< HTTP\\/1.1 500 Internal Server Error\\r\\n< Server: nginx\\r\\n< Date: Mon, 04 Aug 2025 16:39:46 GMT\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: private, must-revalidate\\r\\n< pragma: no-cache\\r\\n< expires: -1\\r\\n< Access-Control-Allow-Origin: *\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(8, NULL, '0202707072', 'Hi NANA, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=2dc4bf43dda64e2ec41701f389adf5ba', NULL, 'registration', 'success', 'arkesel', '2025-08-04 18:37:23', '{\n    \"data\": [\n        {\n            \"id\": \"502a14bf-7d8b-41ff-9e00-e3eab6ddb9ad\",\n            \"recipient\": \"233202707072\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(9, NULL, '0541758561', 'Hi KWAME, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=71f0b86cc49a06647ec0784d0e8b7897', NULL, 'registration', 'success', 'arkesel', '2025-08-04 18:42:37', '{\n    \"data\": [\n        {\n            \"id\": \"d492ba74-4840-45cc-aa17-2e63ab99c2a4\",\n            \"recipient\": \"233541758561\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(10, NULL, '0541758561', 'Hi KWAME, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=5ff689a47bd0dedaf13c4dd4d9a21644', NULL, 'registration', 'success', 'arkesel', '2025-08-04 18:42:45', '{\n    \"data\": [\n        {\n            \"id\": \"16147f5a-3941-4417-8857-364be89382e9\",\n            \"recipient\": \"233541758561\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(11, NULL, '0206376136', 'Hi DANIEL, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=729e04460aaab263d3432a1c1ab29e01', NULL, 'registration', 'success', 'arkesel', '2025-08-04 20:39:08', '{\n    \"data\": [\n        {\n            \"id\": \"29e8e898-6377-407e-a005-77842cd5a51b\",\n            \"recipient\": \"233206376136\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(12, NULL, '05342345123', 'Hi WISDOM, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=9cc4688c2af522300bb7147e2973234f', NULL, 'registration', 'fail', 'arkesel', '2025-08-04 20:49:10', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-08-04 20:49:10\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi WISDOM, click on the link to complete your registration: https:\\/\\/myfreeman.mensweb.xyz\\/views\\/complete_registration.php?token=9cc4688c2af522300bb7147e2973234f\",\n            \"recipients\": [\n                \"05342345123\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nDate: Mon, 04 Aug 2025 20:49:10 GMT\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: private, must-revalidate\\r\\npragma: no-cache\\r\\nexpires: -1\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1350\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=1i9LzcbSDwtU0ORtJSWN88aR63Cgeqrh4fTS5ec2; expires=Mon, 04-Aug-2025 22:49:10 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 3.029999999999999804600747665972448885440826416015625,\n            \"connect_time\": 0.025762000000000000177191594730174983851611614227294921875,\n            \"namelookup_time\": 1.800000000000000045601543374740316494353464804589748382568359375e-5,\n            \"pretransfer_time\": 0.0636110000000000008757439218243234790861606597900390625,\n            \"starttransfer_time\": 3.029977000000000142421185955754481256008148193359375,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 228,\n            \"size_download\": 61,\n            \"speed_download\": 20,\n            \"speed_upload\": 75,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 228,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"* Hostname sms.arkesel.com was found in DNS cache\\n*   Trying 5.161.241.15...\\n* TCP_NODELAY set\\n* Connected to sms.arkesel.com (5.161.241.15) port 443 (#0)\\n* ALPN, offering http\\/1.1\\n* successfully set certificate verify locations:\\n*   CAfile: \\/etc\\/pki\\/tls\\/certs\\/ca-bundle.crt\\n  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN, server accepted to use http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Aug  3 01:24:33 2025 GMT\\n*  expire date: Nov  1 01:24:32 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 228\\r\\n\\r\\n* upload completely sent off: 228 out of 228 bytes\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Date: Mon, 04 Aug 2025 20:49:10 GMT\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: private, must-revalidate\\r\\n< pragma: no-cache\\r\\n< expires: -1\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1350\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=1i9LzcbSDwtU0ORtJSWN88aR63Cgeqrh4fTS5ec2; expires=Mon, 04-Aug-2025 22:49:10 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(13, NULL, '0544567850', 'Hi ATTA, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=c2a3f53eb2ab7aab274ba49c0a98a215', NULL, 'registration', 'success', 'arkesel', '2025-08-04 21:00:53', '{\n    \"data\": [\n        {\n            \"id\": \"bc1fe21e-e772-406d-930c-afa2db3c9b55\",\n            \"recipient\": \"233544567850\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(14, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of â‚µ50.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ50.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-05 10:35:33', '{\n    \"data\": [\n        {\n            \"id\": \"3772b27f-b8e9-40a9-ac9f-da0b8332f43a\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(15, NULL, '0242363905', 'Hi BARNA BAABIOLA, your payment of â‚µ10.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ140.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-05 11:09:46', '{\n    \"data\": [\n        {\n            \"id\": \"7799e17a-b61f-4e61-bb55-51d81c055927\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(16, NULL, '0551756789', 'Hi JACK JHAY, your payment of â‚µ30.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ30.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-05 11:09:50', '{\n    \"data\": [\n        {\n            \"id\": \"f9a15df2-ffac-4fe3-a8ed-e10fe491234d\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(17, NULL, '0206376136', 'Hi DANIEL ANTWI, your payment of â‚µ15.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ15.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-05 11:09:55', '{\n    \"data\": [\n        {\n            \"id\": \"e26509bb-311a-4d83-bd5a-0f9d466b274b\",\n            \"recipient\": \"233206376136\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(18, NULL, '0242363905', 'Hi BARNA BAABIOLA, your payment of â‚µ20.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ160.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-05 15:28:18', '{\n    \"data\": [\n        {\n            \"id\": \"d6ff838d-dd74-4d98-8940-71af850ab75a\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(19, NULL, '0242363905', 'Hi BARNA BAABIOLA, your payment of â‚µ54.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ214.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-05 15:28:56', '{\n    \"data\": [\n        {\n            \"id\": \"764922c7-c646-437f-b071-483f15a35043\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(20, NULL, '0553143607', 'Hi DANNY WISE, your payment of â‚µ50.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ50.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-06 16:24:10', '{\n    \"data\": [\n        {\n            \"id\": \"9043b9b1-11ab-40e4-b76c-45023cfb1ab4\",\n            \"recipient\": \"233553143607\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(21, NULL, '0202707072', 'Hi NANA OTU, your payment of â‚µ230.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ230.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-06 21:38:25', '{\n    \"data\": [\n        {\n            \"id\": \"61c28ab3-bef1-4cc0-9f07-8000daafe747\",\n            \"recipient\": \"233202707072\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(22, NULL, '0553143607', 'Hi DANNY WISE, your payment of â‚µ237.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is â‚µ287.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-06 22:05:01', '{\n    \"data\": [\n        {\n            \"id\": \"6669666d-fd21-4a17-be22-5f62fa6d4fda\",\n            \"recipient\": \"233553143607\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(23, NULL, '0541758561', 'Dear KWAME  PANFORD, your payment of ₵7.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-09 07:42:02', '{\n    \"data\": [\n        {\n            \"id\": \"1c49a16c-639f-46c4-af8c-6627a392be3d\",\n            \"recipient\": \"233541758561\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(24, NULL, '0553143607', 'Hi DANNY WISE, your payment of ₵8.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵295.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-09 07:46:43', '{\n    \"data\": [\n        {\n            \"id\": \"06472089-8128-4ed3-a9c2-970c23186b7d\",\n            \"recipient\": \"233553143607\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(25, NULL, '0553143607', 'Dear DANNY  WISE, your payment of ₵4.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-09 07:46:52', '{\n    \"data\": [\n        {\n            \"id\": \"3fbb67e6-cdd5-41f1-a40b-f889717bebc2\",\n            \"recipient\": \"233553143607\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(26, NULL, '0553143607', 'Dear DANNY  WISE, your payment of ₵5.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-09 07:47:01', '{\n    \"data\": [\n        {\n            \"id\": \"1667fcee-e16b-49ea-ab5b-18fbb6515b58\",\n            \"recipient\": \"233553143607\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(27, NULL, '0551756789', 'Hi JACK JHAY, your payment of ₵3.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵33.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-09 07:50:47', '{\n    \"data\": [\n        {\n            \"id\": \"862f7b97-cf1a-4e77-86a3-89b19762f484\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(28, NULL, '0551756789', 'Dear JACK  JHAY, your payment of ₵1.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-09 07:50:56', '{\n    \"data\": [\n        {\n            \"id\": \"7335393d-3b77-48df-b34f-f06501eba302\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(29, NULL, '0551756789', 'Dear JACK  JHAY, your payment of ₵2.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-09 07:51:06', '{\n    \"data\": [\n        {\n            \"id\": \"762b5257-aaf6-4261-80c0-9e186e6516c0\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(30, NULL, '1234567890', 'Hi Justina, click on the link to complete your registration: http://localhost/myfreemanchurchgit/church/views/complete_registration.php?token=fb3e3309fdd71b10f47b25a248b9d150', NULL, 'registration', 'fail', 'arkesel', '2025-08-09 14:20:47', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-08-09 16:20:47\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi Justina, click on the link to complete your registration: http:\\/\\/localhost\\/myfreemanchurchgit\\/church\\/views\\/complete_registration.php?token=fb3e3309fdd71b10f47b25a248b9d150\",\n            \"recipients\": [\n                \"1234567890\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nDate: Sat, 09 Aug 2025 14:20:47 GMT\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: private, must-revalidate\\r\\npragma: no-cache\\r\\nexpires: -1\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1456\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=jBn9x7dE0bM3mYI6OtrOpRcpwUaDKlrocXk6OTzr; expires=Sat, 09-Aug-2025 16:20:47 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 3.750503,\n            \"connect_time\": 0.189423,\n            \"namelookup_time\": 0.002134,\n            \"pretransfer_time\": 0.54456,\n            \"starttransfer_time\": 3.750466,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 243,\n            \"size_download\": 61,\n            \"speed_download\": 16,\n            \"speed_upload\": 64,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 243,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 5.161.241.15:443...\\n* Connected to sms.arkesel.com (5.161.241.15) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Aug  3 01:24:33 2025 GMT\\n*  expire date: Nov  1 01:24:32 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 243\\r\\n\\r\\n* old SSL session ID is stale, removing\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Date: Sat, 09 Aug 2025 14:20:47 GMT\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: private, must-revalidate\\r\\n< pragma: no-cache\\r\\n< expires: -1\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1456\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=jBn9x7dE0bM3mYI6OtrOpRcpwUaDKlrocXk6OTzr; expires=Sat, 09-Aug-2025 16:20:47 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(31, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵60.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵110.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:24:58', '{\n    \"data\": [\n        {\n            \"id\": \"d62d76a5-c4bb-49ff-aea8-f1e5d472ac37\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(32, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵111.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:26:00', '{\n    \"data\": [\n        {\n            \"id\": \"161460ba-d800-42e0-8284-1b534752ab3b\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(33, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵2.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 10:26:09', '{\n    \"data\": [\n        {\n            \"id\": \"68e7f813-5ed3-4ff7-8ec8-09aafbec632c\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(34, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵3.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 10:26:17', '{\n    \"data\": [\n        {\n            \"id\": \"3bc0bfc0-7724-42d6-aa98-6a035a9798a7\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(35, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵112.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:33:00', '{\n    \"data\": [\n        {\n            \"id\": \"11c2c887-bdf3-4de5-af40-db4976a74ef2\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(36, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵2.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 10:33:10', '{\n    \"data\": [\n        {\n            \"id\": \"55d89c30-7aed-4e0d-a4a0-b9243d370784\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(37, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵2.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵114.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:37:27', '{\n    \"data\": [\n        {\n            \"id\": \"5d100904-473a-42bd-9714-6c0c9ed5999c\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(38, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵1.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 10:37:36', '{\n    \"data\": [\n        {\n            \"id\": \"37d80181-c449-4eda-beab-464b7491a837\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(39, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵3.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵117.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:39:18', '{\n    \"data\": [\n        {\n            \"id\": \"81dd0086-4c7f-458e-a9c1-1b4b202132d1\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(40, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵5.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵122.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:44:02', '{\n    \"data\": [\n        {\n            \"id\": \"1512dd47-1fe5-4299-bcba-07cd508685f9\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(41, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵3.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵115.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:46:23', '{\n    \"data\": [\n        {\n            \"id\": \"88b546b2-530a-4d0b-9621-bbe7ecf866b1\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(42, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵116.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:46:56', '{\n    \"data\": [\n        {\n            \"id\": \"1242ab02-2398-4675-8c11-96dc8c661112\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(43, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵116.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:49:55', '{\n    \"data\": [\n        {\n            \"id\": \"e999099e-0ad9-4a5e-a279-958d315cb287\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(44, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵10.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵126.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:51:08', '{\n    \"data\": [\n        {\n            \"id\": \"82e61306-628e-4e91-bde7-6f655aea33de\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(45, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵4.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 10:51:17', '{\n    \"data\": [\n        {\n            \"id\": \"19f7b0a7-237c-4deb-978d-f0e7f30f7eb5\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(46, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵5.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 10:51:27', '{\n    \"data\": [\n        {\n            \"id\": \"2504b1a8-7c32-4728-9f1c-305a87c9db04\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(47, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵7.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 10:52:15', '{\n    \"data\": [\n        {\n            \"id\": \"14c51023-34b4-4abb-9b2c-dbc11adc1612\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(48, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for August HARVEST. Your Total Harvest amount for the year 2025 is ₵127.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 10:57:29', '{\n    \"data\": [\n        {\n            \"id\": \"49bde22d-3f33-4284-befa-f8f6ec52fee8\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(49, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for August 2025 HARVEST. Your Total Harvest amount for the year 2025 is ₵128.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 11:17:29', '{\n    \"data\": [\n        {\n            \"id\": \"d8a52f64-e677-4cd1-aead-a6a90a92e479\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(50, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵10.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 11:21:23', '{\n    \"data\": [\n        {\n            \"id\": \"f2d1ceb7-bc7d-4fad-ae33-5a8450dd45ca\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(51, NULL, '0206376136', 'Hi DANIEL ANTWI, your payment of ₵6.00 has been paid to Freeman Methodist Church - KM as HARVEST for March 2025. Your Total Harvest amount for the year 2025 is ₵21.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 11:35:55', '{\n    \"data\": [\n        {\n            \"id\": \"e39dec34-10b9-47c8-9497-3045a753cc15\",\n            \"recipient\": \"233206376136\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(52, NULL, '0242363905', 'Dear BARNA  BAABIOLA, your payment of ₵5.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 11:36:05', '{\n    \"data\": [\n        {\n            \"id\": \"6951f8c5-1779-4682-af7b-50833816342f\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(53, NULL, '0206376136', 'Dear DANIEL  ANTWI, your payment of ₵6.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 11:36:14', '{\n    \"data\": [\n        {\n            \"id\": \"9b1aac56-e633-4892-9096-8a906ada1d13\",\n            \"recipient\": \"233206376136\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(54, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵10.00 has been paid to Freeman Methodist Church - KM as Payment for December 2024 HARVEST. Your Total Harvest amount for the year 2025 is ₵10.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 11:58:30', '{\n    \"data\": [\n        {\n            \"id\": \"d74b2d3b-deaa-4740-9446-8068d4916a87\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(55, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵1.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:00:28', '{\n    \"data\": [\n        {\n            \"id\": \"ab8a92a9-387e-4f65-b732-cb022adfc689\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(56, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵10.00 has been paid to Freeman Methodist Church - KM as HARVEST for October 2024. Your Total Harvest amount for the year 2025 is ₵20.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:03:07', '{\n    \"data\": [\n        {\n            \"id\": \"bf07bbcf-7590-4013-a214-f95e778f05c1\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(57, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for September 2024 HARVEST. Your Total Harvest amount for the year 2025 is ₵1.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:11:04', '{\n    \"data\": [\n        {\n            \"id\": \"07f23be8-d3d0-42b8-8f93-455565d8ecc2\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(58, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵3.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:12:46', '{\n    \"data\": [\n        {\n            \"id\": \"1db4b57d-b0ab-4d77-b7ab-55c7eb7bc107\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(59, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵2.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:12:55', '{\n    \"data\": [\n        {\n            \"id\": \"f723f122-0bd6-4be7-9cb2-4e7b388ffdcf\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(60, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for March 2025 HARVEST. Your Total Harvest amount for the year 2025 is ₵1.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:17:49', '{\n    \"data\": [\n        {\n            \"id\": \"d4c037a2-d779-43d6-a045-53e8df84f3f8\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(61, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for September 2024 HARVEST. Your Total Harvest amount for the year 2025 is ₵1.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:22:17', '{\n    \"data\": [\n        {\n            \"id\": \"cb4711e4-7075-4ee5-8e9d-cdb15902f887\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(62, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵5.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:24:25', '{\n    \"data\": [\n        {\n            \"id\": \"bc243883-a610-4c50-ac82-17af241f1750\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(63, NULL, '0206376136', 'Dear JOHN  ABBAN, your payment of ₵6.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:26:39', '{\n    \"data\": [\n        {\n            \"id\": \"f3ea512d-85da-4a2c-9aa0-4d205ec97a99\",\n            \"recipient\": \"233206376136\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(64, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for August 2025 HARVEST. Your Total Harvest amount for the year 2025 is ₵1.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:29:47', '{\n    \"data\": [\n        {\n            \"id\": \"1320bd79-3ffe-4f4f-987e-75fbe4c72b99\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(65, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵5.00 has been paid to Freeman Methodist Church - KM as Payment for April 2025 HARVEST. Your Total Harvest amount for the year 2025 is ₵6.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:33:16', '{\n    \"data\": [\n        {\n            \"id\": \"4046742c-e031-4ef9-8e67-c0da0e8b5834\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(66, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵3.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:33:25', '{\n    \"data\": [\n        {\n            \"id\": \"ba1a46ba-259e-4297-b748-dfed80a1eee9\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(67, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵5.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:39:17', '{\n    \"data\": [\n        {\n            \"id\": \"be66ec2f-8df1-408f-b181-4fda1bac3937\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(68, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for November 2024 HARVEST. Your Total Harvest amount for the year 2025 is ₵1.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:46:00', '{\n    \"data\": [\n        {\n            \"id\": \"6cd89e92-2a90-4cf6-b921-cedbb85e0bc0\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(69, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵6.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:50:46', '{\n    \"data\": [\n        {\n            \"id\": \"580133e6-a82e-4316-94db-0b179c4267b1\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(70, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵1.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:52:24', '{\n    \"data\": [\n        {\n            \"id\": \"fade22d7-b595-41f1-b018-b93d45fcb2d9\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(71, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵3.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 12:53:30', '{\n    \"data\": [\n        {\n            \"id\": \"1109acd3-52f2-458f-9744-83b5038d6ce6\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(72, NULL, '0534234523', 'Hi WISDOM ARTHUR, your payment of ₵6.00 has been paid to Freeman Methodist Church - KM as Payment for November 2024 HARVEST. Your Total Harvest amount for the year 2025 is ₵7.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-12 12:56:01', '{\n    \"data\": [\n        {\n            \"id\": \"4b55b7eb-db42-4872-aeab-a1a9612236d8\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(73, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵1.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 13:00:38', '{\n    \"data\": [\n        {\n            \"id\": \"f4c1ef60-7df8-48db-8c87-d66aac940259\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(74, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵4.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 13:00:47', '{\n    \"data\": [\n        {\n            \"id\": \"e511ba44-30e3-471d-acc5-c1fc1a611f3f\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(75, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵6.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 13:04:28', '{\n    \"data\": [\n        {\n            \"id\": \"2f5c34f9-cb41-4616-8d07-c07b80cf3c6b\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(76, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵5.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 13:04:37', '{\n    \"data\": [\n        {\n            \"id\": \"12fa6c69-9c38-4eb3-a32d-bb28a8e912b3\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(77, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵6.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 13:10:02', '{\n    \"data\": [\n        {\n            \"id\": \"23550fb5-cdac-4ecd-9db3-dc6b416a8f19\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(78, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵6.00 for August WELFARE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 13:11:54', '{\n    \"data\": [\n        {\n            \"id\": \"c46da685-8110-463f-8510-dbeb88ae6a44\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(79, NULL, '0534234523', 'Dear WISDOM  ARTHUR, your payment of ₵8.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-12 13:20:22', '{\n    \"data\": [\n        {\n            \"id\": \"41021cc1-82a2-4ceb-9478-26bda3e578e2\",\n            \"recipient\": \"233534234523\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(80, NULL, '0551756789', 'Hi JACK JHAY, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as HARVEST for August 2025. Your Total Harvest amount for the year 2025 is ₵1.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-20 03:12:27', '{\n    \"data\": [\n        {\n            \"id\": \"5037209f-aee4-4538-a7d5-66ae2a2578ff\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(81, NULL, '0551756789', 'Dear JACK  JHAY, your payment of ₵3.00 for August TITHE has been received by Freeman Methodist Church - KM. Thank You!', NULL, 'payment', 'success', 'arkesel', '2025-08-20 03:12:37', '{\n    \"data\": [\n        {\n            \"id\": \"35372f08-1857-4480-ae45-e01969e66432\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(82, NULL, '0551756789', 'Hi JACK JHAY, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for October 2024 HARVEST. Your Total Harvest amount for the year 2025 is ₵2.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-20 03:14:12', '{\n    \"data\": [\n        {\n            \"id\": \"c38885eb-765c-404d-8f39-8026ecf57319\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(83, NULL, '0551756789', 'Hi JACK JHAY, your payment of ₵1.00 has been paid to Freeman Methodist Church - KM as Payment for July 2025 HARVEST. Your Total Harvest amount for the year 2025 is ₵1.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-08-20 06:18:41', '{\n    \"data\": [\n        {\n            \"id\": \"b33754f3-3cbc-4bd3-b677-5497db2ec1af\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}');

-- --------------------------------------------------------

--
-- Table structure for table `sms_templates`
--

CREATE TABLE `sms_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `body` text NOT NULL,
  `variables` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sms_templates`
--

INSERT INTO `sms_templates` (`id`, `name`, `type`, `body`, `variables`, `created_at`, `updated_at`) VALUES
(1, 'Birthday', 'Bulk', 'Hello, {name}', NULL, '2025-07-06 10:44:32', '2025-07-06 10:44:32');

-- --------------------------------------------------------

--
-- Table structure for table `sunday_school`
--

CREATE TABLE `sunday_school` (
  `id` int(11) NOT NULL,
  `srn` varchar(32) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `contact` varchar(32) DEFAULT NULL,
  `gps_address` varchar(255) DEFAULT NULL,
  `residential_address` varchar(255) DEFAULT NULL,
  `organization` varchar(100) DEFAULT NULL,
  `school_attend` varchar(150) DEFAULT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `father_contact` varchar(32) DEFAULT NULL,
  `father_occupation` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_contact` varchar(32) DEFAULT NULL,
  `mother_occupation` varchar(100) DEFAULT NULL,
  `father_member_id` int(11) DEFAULT NULL,
  `father_is_member` varchar(10) DEFAULT NULL,
  `mother_member_id` int(11) DEFAULT NULL,
  `mother_is_member` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `transferred_at` datetime DEFAULT NULL,
  `transferred_to_member_id` int(11) DEFAULT NULL,
  `dayborn` varchar(16) DEFAULT NULL,
  `gender` varchar(16) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sunday_school`
--

INSERT INTO `sunday_school` (`id`, `srn`, `church_id`, `class_id`, `photo`, `last_name`, `middle_name`, `first_name`, `dob`, `contact`, `gps_address`, `residential_address`, `organization`, `school_attend`, `father_name`, `father_contact`, `father_occupation`, `mother_name`, `mother_contact`, `mother_occupation`, `father_member_id`, `father_is_member`, `mother_member_id`, `mother_is_member`, `created_at`, `updated_at`, `transferred_at`, `transferred_to_member_id`, `dayborn`, `gender`) VALUES
(12, 'FMC-S0101-KM', 7, 49, '', 'SAM', 'NAA', 'AMA', '2017-05-08', '0277384201', 'WH-123-4698', 'APOWA', '', 'ST. FRANCIS SCHOOL ANAJI', 'KOJO', '0254879654', 'Farmer', 'Mensah Justina', '0234567899', '0', NULL, 'no', 132, 'yes', '2025-08-04 20:20:57', '2025-08-09 14:42:51', NULL, NULL, 'Monday', 'female'),
(14, 'FMC-S0103-KM', 7, 49, '', 'ABBAN', '', 'JOHN', '2007-02-23', '0206376136', '', '', '18,19,20', 'TAKORADI TECHNICAL UNIVERSITY', 'ANTWI DANIEL', '0206376136', 'Shipper', 'Mensah Justina', '0234567899', '0', 128, 'yes', 132, 'yes', '2025-08-05 21:53:15', '2025-08-09 14:41:49', NULL, NULL, 'Friday', 'male');

-- --------------------------------------------------------

--
-- Table structure for table `sync_activity_log`
--

CREATE TABLE `sync_activity_log` (
  `id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL COMMENT 'Type of sync activity (attendance_sync, device_status_update, test_connection)',
  `processed_records` int(11) DEFAULT 0 COMMENT 'Number of records processed',
  `error_count` int(11) DEFAULT 0 COMMENT 'Number of errors encountered',
  `sync_timestamp` datetime NOT NULL COMMENT 'Timestamp from sync agent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Server timestamp when logged',
  `details` text DEFAULT NULL COMMENT 'Additional details or error messages'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log table for sync agent activity monitoring';

--
-- Dumping data for table `sync_activity_log`
--

INSERT INTO `sync_activity_log` (`id`, `activity_type`, `processed_records`, `error_count`, `sync_timestamp`, `created_at`, `details`) VALUES
(1, 'system_init', 0, 0, '2025-08-09 16:39:16', '2025-08-09 16:39:16', 'Sync activity log table created');

-- --------------------------------------------------------

--
-- Table structure for table `template_permissions`
--

CREATE TABLE `template_permissions` (
  `template_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `church_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(32) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `member_id`, `church_id`, `name`, `email`, `phone`, `password_hash`, `status`, `created_at`, `photo`) VALUES
(3, NULL, 1, 'Ekow Mensah', 'ekowme@gmail.com', '', '$2y$10$cy/HU5EM4JuScA6iVUyfDeRVgx0AKndOQodY1IPfG0iiTES5pY5A6', 'active', '2025-07-03 17:33:41', 'user_3_1751642143.jpg'),
(25, 122, 7, 'BARNA BAABIOLA', 'barnasco4uallgh@gmail.com', '0242363905', '$2y$10$DW5TNxH5i.OTIbPobKpoU.AWozW.7trn7y797nvd92nHJWk3xWt.G', 'active', '2025-08-06 01:27:28', NULL),
(26, 131, 7, 'SAM NAA AMA', 'ansam@gmail.com', '0277384201', '$2y$10$MLst2sPen.2w5eQ97HlfCu/dWXh3SWNylpfAEZep20g0zPFHdJ9zG', 'active', '2025-08-06 01:39:02', NULL),
(27, 128, 7, 'DANIEL ANTWI', 'danielantwi512@gmail.com', '0206376136', '$2y$10$aNiH/Ns8oBqrZqsQQYlPFe/wpG3b2Vf6qljv9OyuixijWcxd1PUYy', 'active', '2025-08-06 07:29:07', NULL),
(28, 127, 7, 'KWAME PANFORD', 'kpanford@gmail.com', '0541758561', '$2y$10$LU5hwNLMqWGdNdkkdqkVUe4R.0RmQvtFCbVlFEApJ3dzq6n4pAE6y', 'active', '2025-08-06 20:14:37', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_audit`
--

CREATE TABLE `user_audit` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_permission_requests`
--

CREATE TABLE `user_permission_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `status` enum('pending','approved','denied') DEFAULT 'pending',
  `requested_at` datetime DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(3, 1),
(26, 2),
(26, 10),
(28, 7),
(25, 1),
(27, 5);

-- --------------------------------------------------------

--
-- Table structure for table `visitors`
--

CREATE TABLE `visitors` (
  `id` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `home_town` varchar(100) DEFAULT NULL,
  `region` varchar(50) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `want_member` varchar(5) DEFAULT NULL,
  `visit_date` date DEFAULT NULL,
  `invited_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `visitors`
--

INSERT INTO `visitors` (`id`, `church_id`, `name`, `phone`, `email`, `address`, `purpose`, `gender`, `home_town`, `region`, `occupation`, `marital_status`, `want_member`, `visit_date`, `invited_by`, `created_at`) VALUES
(0, 7, 'NANA KOJO', '0242363905', '', '123 ST. BARS STREEL', 'TO WORSHIP WITH US', 'Male', 'WINDO', 'Savannah', 'DRIVER', 'Single', 'Yes', '2025-08-05', 124, '2025-08-05 22:19:45');

-- --------------------------------------------------------

--
-- Structure for view `attendance_with_zkteco`
--
DROP TABLE IF EXISTS `attendance_with_zkteco`;

CREATE ALGORITHM=UNDEFINED DEFINER=`menswebg`@`localhost` SQL SECURITY DEFINER VIEW `attendance_with_zkteco`  AS SELECT `ar`.`id` AS `id`, `ar`.`session_id` AS `session_id`, `ar`.`member_id` AS `member_id`, `ar`.`status` AS `status`, `ar`.`marked_by` AS `marked_by`, `ar`.`created_at` AS `created_at`, `ar`.`sync_source` AS `sync_source`, `ar`.`verification_type` AS `verification_type`, `ar`.`device_timestamp` AS `device_timestamp`, `zrl`.`device_id` AS `device_id`, `zd`.`device_name` AS `device_name`, `zd`.`location` AS `device_location`, `m`.`first_name` AS `first_name`, `m`.`last_name` AS `last_name`, `m`.`crn` AS `crn`, `ats`.`title` AS `session_title`, `ats`.`service_date` AS `service_date` FROM ((((`attendance_records` `ar` left join `zkteco_raw_logs` `zrl` on(`ar`.`zkteco_raw_log_id` = `zrl`.`id`)) left join `zkteco_devices` `zd` on(`zrl`.`device_id` = `zd`.`id`)) left join `members` `m` on(`ar`.`member_id` = `m`.`id`)) left join `attendance_sessions` `ats` on(`ar`.`session_id` = `ats`.`id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `adherents`
--
ALTER TABLE `adherents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_member_id` (`member_id`),
  ADD KEY `idx_marked_by` (`marked_by`),
  ADD KEY `idx_date_became_adherent` (`date_became_adherent`),
  ADD KEY `idx_adherents_member_date` (`member_id`,`date_became_adherent`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_session_member` (`session_id`,`member_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `marked_by` (`marked_by`),
  ADD KEY `idx_sync_source` (`sync_source`),
  ADD KEY `idx_zkteco_raw_log` (`zkteco_raw_log_id`),
  ADD KEY `idx_hikvision_raw_log` (`hikvision_raw_log_id`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bible_classes`
--
ALTER TABLE `bible_classes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cashier_denomination_entries`
--
ALTER TABLE `cashier_denomination_entries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `churches`
--
ALTER TABLE `churches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `class_groups`
--
ALTER TABLE `class_groups`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `deleted_members`
--
ALTER TABLE `deleted_members`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `event_registrations`
--
ALTER TABLE `event_registrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `event_types`
--
ALTER TABLE `event_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `health_records`
--
ALTER TABLE `health_records`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hikvision_devices`
--
ALTER TABLE `hikvision_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_device_ip` (`ip_address`,`port`),
  ADD KEY `idx_church_id` (`church_id`),
  ADD KEY `idx_active_devices` (`is_active`,`sync_status`);

--
-- Indexes for table `hikvision_raw_logs`
--
ALTER TABLE `hikvision_raw_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_time` (`device_id`,`event_time`),
  ADD KEY `idx_processed` (`processed`),
  ADD KEY `idx_user_time` (`user_id`,`event_time`),
  ADD KEY `attendance_record_id` (`attendance_record_id`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `member_biometric_data`
--
ALTER TABLE `member_biometric_data`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `member_feedback`
--
ALTER TABLE `member_feedback`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `member_feedback_thread`
--
ALTER TABLE `member_feedback_thread`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `member_hikvision_data`
--
ALTER TABLE `member_hikvision_data`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_id` (`device_id`,`hikvision_user_id`),
  ADD KEY `member_id` (`member_id`);

--
-- Indexes for table `member_transfers`
--
ALTER TABLE `member_transfers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_menu_items_permission` (`permission_name`);

--
-- Indexes for table `organizations`
--
ALTER TABLE `organizations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `organization_membership_approvals`
--
ALTER TABLE `organization_membership_approvals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_member_type_amount_date` (`member_id`,`payment_type_id`,`amount`,`payment_date`),
  ADD KEY `idx_payments_payment_period` (`payment_period`);

--
-- Indexes for table `payment_intents`
--
ALTER TABLE `payment_intents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `client_reference` (`client_reference`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `payment_reversal_log`
--
ALTER TABLE `payment_reversal_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_types`
--
ALTER TABLE `payment_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `permission_templates`
--
ALTER TABLE `permission_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles_of_serving`
--
ALTER TABLE `roles_of_serving`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sms_templates`
--
ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sunday_school`
--
ALTER TABLE `sunday_school`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sync_activity_log`
--
ALTER TABLE `sync_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_sync_timestamp` (`sync_timestamp`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_activity_date` (`activity_type`,`created_at`),
  ADD KEY `idx_error_tracking` (`error_count`,`created_at`);

--
-- Indexes for table `template_permissions`
--
ALTER TABLE `template_permissions`
  ADD PRIMARY KEY (`template_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `visitors`
--
ALTER TABLE `visitors`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adherents`
--
ALTER TABLE `adherents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1189;

--
-- AUTO_INCREMENT for table `bible_classes`
--
ALTER TABLE `bible_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cashier_denomination_entries`
--
ALTER TABLE `cashier_denomination_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `churches`
--
ALTER TABLE `churches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `class_groups`
--
ALTER TABLE `class_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `deleted_members`
--
ALTER TABLE `deleted_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=127;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `event_registrations`
--
ALTER TABLE `event_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_types`
--
ALTER TABLE `event_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `health_records`
--
ALTER TABLE `health_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `hikvision_devices`
--
ALTER TABLE `hikvision_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `hikvision_raw_logs`
--
ALTER TABLE `hikvision_raw_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=133;

--
-- AUTO_INCREMENT for table `member_biometric_data`
--
ALTER TABLE `member_biometric_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `member_feedback`
--
ALTER TABLE `member_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `member_feedback_thread`
--
ALTER TABLE `member_feedback_thread`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `member_hikvision_data`
--
ALTER TABLE `member_hikvision_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `member_transfers`
--
ALTER TABLE `member_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `organization_membership_approvals`
--
ALTER TABLE `organization_membership_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=591;

--
-- AUTO_INCREMENT for table `payment_intents`
--
ALTER TABLE `payment_intents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `payment_types`
--
ALTER TABLE `payment_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `roles_of_serving`
--
ALTER TABLE `roles_of_serving`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84;

--
-- AUTO_INCREMENT for table `sunday_school`
--
ALTER TABLE `sunday_school`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `sync_activity_log`
--
ALTER TABLE `sync_activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `hikvision_raw_logs`
--
ALTER TABLE `hikvision_raw_logs`
  ADD CONSTRAINT `hikvision_raw_logs_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `hikvision_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `hikvision_raw_logs_ibfk_2` FOREIGN KEY (`attendance_record_id`) REFERENCES `attendance_records` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `member_hikvision_data`
--
ALTER TABLE `member_hikvision_data`
  ADD CONSTRAINT `member_hikvision_data_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `member_hikvision_data_ibfk_2` FOREIGN KEY (`device_id`) REFERENCES `hikvision_devices` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
