-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 20, 2025 at 07:03 PM
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
-- Database: `myfreeman`
--

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_records`
--

INSERT INTO `attendance_records` (`id`, `session_id`, `member_id`, `status`, `marked_by`, `created_at`) VALUES
(27, 11, 79, 'absent', 3, '2025-07-19 20:27:50'),
(28, 11, 91, 'present', 3, '2025-07-19 20:27:50'),
(29, 11, 84, 'absent', 3, '2025-07-19 20:27:50'),
(30, 11, 80, 'absent', 3, '2025-07-19 20:27:50'),
(31, 11, 73, 'present', 3, '2025-07-19 20:27:50'),
(32, 11, 90, 'absent', 3, '2025-07-19 20:27:50'),
(33, 11, 85, 'absent', 3, '2025-07-19 20:27:50'),
(34, 11, 82, 'present', 3, '2025-07-19 20:27:50'),
(35, 11, 88, 'absent', 3, '2025-07-19 20:27:50'),
(36, 11, 72, 'absent', 3, '2025-07-19 20:27:50'),
(37, 11, 86, 'absent', 3, '2025-07-19 20:27:50'),
(38, 11, 81, 'absent', 3, '2025-07-19 20:27:50'),
(39, 11, 92, 'present', 3, '2025-07-19 20:27:50'),
(40, 11, 87, 'absent', 3, '2025-07-19 20:27:50'),
(41, 11, 89, 'absent', 3, '2025-07-19 20:27:50'),
(42, 11, 83, 'absent', 3, '2025-07-19 20:27:50');

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

--
-- Dumping data for table `attendance_sessions`
--

INSERT INTO `attendance_sessions` (`id`, `church_id`, `title`, `service_date`, `is_recurring`, `recurrence_type`, `recurrence_day`, `parent_recurring_id`, `notes`, `created_at`) VALUES
(11, 7, 'Sunday Service', '0000-00-00', 1, 'weekly', 0, NULL, NULL, '2025-07-16 18:51:07');

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
(27, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:01:42'),
(28, 30, 'logout', 'user', 30, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-14 17:04:14'),
(29, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:05:13'),
(30, 30, 'logout', 'user', 30, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-14 17:06:56'),
(31, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:07:06'),
(32, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:07:40'),
(33, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:09:15'),
(34, 30, 'logout', 'user', 30, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-14 17:15:39'),
(35, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:15:52'),
(36, 30, 'logout', 'user', 30, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-14 17:16:31'),
(37, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:16:41'),
(38, 30, 'logout', 'user', 30, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-14 17:17:00'),
(39, 30, 'logout', 'user', 30, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-14 17:19:05'),
(40, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:19:35'),
(41, 30, 'logout', 'user', 30, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-14 17:20:07'),
(42, 30, 'login_success', 'user', 30, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-14 17:20:19'),
(43, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.58.198\"}', '154.161.58.198', '2025-07-14 17:45:40'),
(44, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.58.198\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.58.198', '2025-07-14 17:53:40'),
(45, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.58.198\"}', '154.161.58.198', '2025-07-14 17:54:34'),
(46, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.58.198\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.58.198', '2025-07-14 18:05:12'),
(47, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-14 19:41:33'),
(48, 3, 'logout', 'user', 3, '{\"ip\":\"102.176.43.168\",\"time\":\"2025-07-14T16:56:25Z\"}', '102.176.43.168', '2025-07-14 20:02:10'),
(49, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-14 20:14:47'),
(50, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-14 20:24:33'),
(51, 3, 'login_failed', 'user', NULL, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-14 20:25:10'),
(52, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-14 20:25:23'),
(53, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.58.198\"}', '154.161.58.198', '2025-07-14 21:07:09'),
(54, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.144.44\"}', '154.161.144.44', '2025-07-14 22:33:33'),
(55, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.144.44\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.144.44', '2025-07-14 22:39:57'),
(56, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.144.44\"}', '154.161.144.44', '2025-07-14 22:40:31'),
(57, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.144.44\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.144.44', '2025-07-14 23:48:30'),
(58, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.144.44\"}', '154.161.144.44', '2025-07-14 23:48:59'),
(59, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.144.44\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.144.44', '2025-07-14 23:57:45'),
(60, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.144.44\"}', '154.161.144.44', '2025-07-15 00:13:42'),
(61, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.165.169\"}', '154.161.165.169', '2025-07-15 01:49:41'),
(62, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.165.212\"}', '154.161.165.212', '2025-07-15 07:20:59'),
(63, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.165.212\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.165.212', '2025-07-15 07:34:55'),
(64, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"185.107.56.128\"}', '185.107.56.128', '2025-07-15 08:33:23'),
(65, 3, 'logout', 'user', 3, '{\"ip\":\"185.107.56.128\",\"time\":\"2025-07-14T16:56:25Z\"}', '185.107.56.128', '2025-07-15 08:44:04'),
(66, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"185.107.56.128\"}', '185.107.56.128', '2025-07-15 08:46:27'),
(67, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.165.169\"}', '154.161.165.169', '2025-07-15 09:04:25'),
(68, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.165.169\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.165.169', '2025-07-15 09:50:18'),
(69, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"169.150.218.2\"}', '169.150.218.2', '2025-07-15 10:48:43'),
(70, 3, 'logout', 'user', 3, '{\"ip\":\"169.150.218.2\",\"time\":\"2025-07-14T16:56:25Z\"}', '169.150.218.2', '2025-07-15 10:53:20'),
(71, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-07-15 13:39:19'),
(72, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.109.242\"}', '102.176.109.242', '2025-07-15 13:40:52'),
(73, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-07-15 13:50:20'),
(74, 3, 'logout', 'user', 3, '{\"ip\":\"102.176.109.242\",\"time\":\"2025-07-14T16:56:25Z\"}', '102.176.109.242', '2025-07-15 13:57:38'),
(75, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-07-15 14:52:54'),
(76, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-07-15 15:03:31'),
(77, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-07-15 16:29:09'),
(78, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-07-15 16:31:55'),
(79, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.160.90.118\"}', '154.160.90.118', '2025-07-15 16:38:42'),
(80, 3, 'logout', 'user', 3, '{\"ip\":\"154.160.90.118\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.160.90.118', '2025-07-15 16:50:06'),
(81, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-15 19:06:49'),
(82, 3, 'logout', 'user', 3, '{\"ip\":\"102.176.43.168\",\"time\":\"2025-07-14T16:56:25Z\"}', '102.176.43.168', '2025-07-15 19:34:38'),
(83, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-15 19:34:51'),
(84, 3, 'logout', 'user', 3, '{\"ip\":\"102.176.43.168\",\"time\":\"2025-07-14T16:56:25Z\"}', '102.176.43.168', '2025-07-15 19:51:24'),
(85, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-15 19:51:47'),
(86, 3, 'logout', 'user', 3, '{\"ip\":\"102.176.43.168\",\"time\":\"2025-07-14T16:56:25Z\"}', '102.176.43.168', '2025-07-15 20:49:25'),
(87, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"102.176.43.168\"}', '102.176.43.168', '2025-07-15 20:53:20'),
(88, 3, 'logout', 'user', 3, '{\"ip\":\"102.176.43.168\",\"time\":\"2025-07-14T16:56:25Z\"}', '102.176.43.168', '2025-07-15 21:36:31'),
(89, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.144.44\"}', '154.161.144.44', '2025-07-15 23:19:31'),
(90, 3, 'logout', 'user', 3, '{\"ip\":\"154.161.144.44\",\"time\":\"2025-07-14T16:56:25Z\"}', '154.161.144.44', '2025-07-16 00:04:37'),
(91, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"154.161.144.44\"}', '154.161.144.44', '2025-07-16 00:04:56'),
(92, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-16 07:52:59'),
(93, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-16 14:55:42'),
(94, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-16 14:55:46'),
(95, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-16T17:00:21+02:00\"}', '::1', '2025-07-16 15:00:21'),
(96, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-16 15:00:25'),
(97, 3, 'create', 'role', 9, '{\"name\":\"Sample Role\",\"permissions\":[\"1\",\"8\"]}', NULL, '2025-07-16 15:14:58'),
(98, 3, 'delete', 'role', 9, '', NULL, '2025-07-16 15:15:06'),
(99, NULL, 'login_failed', 'user', NULL, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-16 17:06:08'),
(100, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-16 17:06:16'),
(101, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-16 17:34:12'),
(102, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-16 20:25:34'),
(103, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 16:27:46'),
(104, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-18 17:33:06'),
(105, NULL, 'login_failed', 'user', NULL, '{\"username\":\"samtom@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 17:33:18'),
(106, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 17:33:26'),
(107, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"1\",\"2\",\"4\",\"6\",\"7\",\"8\",\"9\",\"11\",\"12\",\"16\",\"17\",\"18\",\"33\",\"34\",\"35\",\"73\",\"50\",\"52\",\"55\",\"56\",\"57\",\"63\"]}', NULL, '2025-07-18 17:37:51'),
(108, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-18 19:12:24'),
(109, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 19:12:36'),
(110, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 19:12:48'),
(111, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:25:31'),
(112, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:26:01'),
(113, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:29:27'),
(114, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:32:15'),
(115, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:33:20'),
(116, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:38:36'),
(117, 38, 'login_failed', 'user', NULL, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:46:27'),
(118, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:46:36'),
(119, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:47:13'),
(120, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 20:54:59'),
(121, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-18 21:13:18'),
(122, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-18 21:13:29'),
(123, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"310\"]}', NULL, '2025-07-18 21:46:03'),
(124, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 17:13:54'),
(125, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\"]}', NULL, '2025-07-19 17:15:04'),
(126, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"310\"]}', NULL, '2025-07-19 17:15:30'),
(127, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"218\",\"310\"]}', NULL, '2025-07-19 17:16:14'),
(128, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"218\",\"225\",\"310\"]}', NULL, '2025-07-19 17:17:01'),
(129, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[]}', NULL, '2025-07-19 17:17:46'),
(130, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 17:28:03'),
(131, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 17:28:14'),
(132, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 17:28:28'),
(133, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\"]}', NULL, '2025-07-19 17:30:07'),
(134, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 17:30:22'),
(135, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:00:52'),
(136, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:11:44'),
(137, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:12:06'),
(138, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:13:22'),
(139, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:25:02'),
(140, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:25:19'),
(141, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 18:29:53'),
(142, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:30:03'),
(143, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:30:59'),
(144, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"310\"]}', NULL, '2025-07-19 18:34:12'),
(145, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 18:34:32'),
(146, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 19:35:47'),
(147, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 19:39:22'),
(148, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 20:06:39'),
(149, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 22:00:35'),
(150, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"98\",\"99\",\"100\",\"310\"]}', NULL, '2025-07-19 22:03:03'),
(151, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 22:03:11'),
(152, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 22:03:20'),
(153, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 22:08:25'),
(154, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 22:08:34'),
(155, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"79\",\"98\",\"99\",\"100\",\"310\"]}', NULL, '2025-07-19 22:17:57'),
(156, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 22:18:05'),
(157, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 22:18:14'),
(158, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"78\",\"79\",\"98\",\"99\",\"100\",\"310\"]}', NULL, '2025-07-19 22:27:31'),
(159, 3, 'create', 'permission', 316, '{\"name\":\"pending_members_list\"}', NULL, '2025-07-19 22:28:39'),
(160, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 22:37:43'),
(161, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 22:37:51'),
(162, NULL, 'update', 'role', 5, '{\"name\":\"Class Leader\",\"permissions\":[\"77\",\"78\",\"79\",\"98\",\"99\",\"100\",\"107\",\"110\",\"117\",\"118\",\"310\"]}', NULL, '2025-07-19 22:39:06'),
(163, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 22:39:15'),
(164, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 22:39:25'),
(165, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 23:10:14'),
(166, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 23:10:22'),
(167, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-19 23:13:03'),
(168, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-19 23:13:11'),
(169, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 15:34:51'),
(170, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 15:35:01'),
(171, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 15:35:18'),
(172, NULL, 'update', 'role', 2, '{\"name\":\"Admin\",\"permissions\":[77,310]}', NULL, '2025-07-20 15:44:14'),
(173, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 15:55:20'),
(174, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 15:56:09'),
(175, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 15:57:50'),
(176, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 15:58:36'),
(177, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 15:58:46'),
(178, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 16:11:45'),
(179, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 16:24:00'),
(180, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 16:50:22'),
(181, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 16:50:33'),
(182, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 16:52:30'),
(183, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 16:52:38'),
(184, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 16:54:04'),
(185, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 16:54:14'),
(186, 3, 'logout', 'user', 3, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 16:56:28'),
(187, 3, 'login_success', 'user', 3, '{\"username\":\"ekowme@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 16:56:31'),
(188, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 16:57:11'),
(189, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 16:57:22'),
(190, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 17:01:03'),
(191, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 17:01:11'),
(192, 38, 'logout', 'user', 38, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-20 17:02:01'),
(193, 38, 'login_success', 'user', 38, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-20 17:02:09');

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
(7, NULL, 'REV. THOMAS BIRCH FREEMAN 01', 'F01', 38, 7),
(8, NULL, 'REV. THOMAS BIRCH FREEMAN 02', 'F02', NULL, 7),
(9, NULL, 'SUNDAY SCHOOL 01', 'S01', NULL, 7),
(10, NULL, 'OPAYIN DUNTU 02', 'D02', NULL, 7),
(11, NULL, 'OPAYIN KOOMSON 02', 'K02', NULL, 7),
(12, NULL, 'OPAYIN KOOMSON 03', 'K03', NULL, 7),
(13, NULL, 'KOOMSON 04', 'K04', NULL, 7),
(14, NULL, 'FREEMAN 04', 'F04', NULL, 7),
(15, NULL, 'FREEMAN 06', 'F06', NULL, 7),
(16, NULL, 'FREEMAN 08', 'F08', NULL, 7),
(17, NULL, 'ABEDU 01', 'A01', NULL, 7),
(18, NULL, 'ABEDU 02', 'A02', NULL, 7),
(19, NULL, 'ABEDU 03', 'A03', NULL, 7),
(20, NULL, 'ABEDU 07', 'A07', NULL, 7),
(21, NULL, 'KOOMSON 06', 'K06', NULL, 7),
(22, NULL, 'Test', '01', NULL, 7);

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
(4, NULL, 'DUNTU'),
(5, NULL, 'freeman'),
(6, NULL, 'koomson'),
(7, NULL, 'abedu'),
(8, NULL, 'freeman'),
(9, NULL, 'DUNTU'),
(10, NULL, 'dkdkd');

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
(9, 81, NULL, '{\"weight\":\"58\",\"temperature\":\"32\",\"bp_systolic\":\"25\",\"bp_diastolic\":\"26\",\"bp_status\":\"low\",\"sugar\":\"-1.9\",\"sugar_status\":\"low\",\"hepatitis_b\":\"Positive\",\"malaria\":\"Negative\",\"bp\":\"25\\/26\"}', 'SEE YOU DOCTOR', '2025-07-15 01:07:00', 3),
(10, 80, NULL, '{\"weight\":\"56\",\"temperature\":\"31\",\"bp_systolic\":\"12\",\"bp_diastolic\":\"52\",\"bp_status\":\"low\",\"sugar\":\"10\",\"sugar_status\":\"high\",\"hepatitis_b\":\"Positive\",\"malaria\":\"Negative\",\"bp\":\"12\\/52\"}', 'SEE YOUR DOCTOR', '2025-07-15 08:35:00', 3),
(12, 72, NULL, '{\"weight\":\"120\",\"temperature\":\"80\",\"bp_systolic\":\"120\",\"bp_diastolic\":\"90\",\"bp_status\":\"high\",\"sugar\":\"5\",\"sugar_status\":\"normal\",\"hepatitis_b\":\"Positive\",\"malaria\":\"Positive\",\"bp\":\"120\\/90\"}', 'rEFERRED', '2025-07-17 12:48:00', 3),
(19, NULL, 8, '{\"weight\":\"80\",\"temperature\":\"75\",\"bp_systolic\":\"120\",\"bp_diastolic\":\"80\",\"bp_status\":\"normal\",\"sugar\":\"4\",\"sugar_status\":\"normal\",\"hepatitis_b\":\"Positive\",\"malaria\":\"Negative\",\"bp\":\"120\\/80\"}', '', '2025-07-17 13:16:00', 3),
(20, NULL, 7, '{\"weight\":\"89\",\"temperature\":\"89\",\"bp_systolic\":\"89\",\"bp_diastolic\":\"89\",\"bp_status\":\"low\",\"sugar\":\"8\",\"sugar_status\":\"high\",\"hepatitis_b\":\"Positive\",\"malaria\":\"Negative\",\"bp\":\"89\\/89\"}', 'dkdldlakd', '2025-07-19 00:25:00', 3);

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
(72, 'Ekow', 'Paa', 'Mensah', 7, 7, 'FMC-F0101-KM', NULL, '2010-07-14', 'kjhkhk', 'Wednesday', 'Male', '0545644749', '', 'ekowme@gmail.com', '', '', 'Married', 'jkhkjhk', 'Savannah', 'member_6877d58922a24.jpg', 'de-activated', '2025-07-16 19:24:02', '$2y$10$6S67x.UMi7h4ryYhx1DFcOnzucrBNtl.E9NNlX1GBQ1EUE9YeirDO', '2025-07-10 14:30:29', NULL, 'Formal', '', 'Yes', 'Yes', '2025-07-11', '2025-07-11', '', '0000-00-00', 0),
(73, 'Grace', '', 'Dadson', 7, 8, 'FMC-F0202-KM', NULL, '2025-07-01', 'kasoa', 'Tuesday', 'Female', '0545644748', '', 'ekowme@gmail.com', 'b241, owusu kofi str', '', 'Single', 'accra', 'Central', '', 'active', '', '$2y$10$Z6vOvRsCF5FtF/avqEJiDuuNYeNEq/naIfiYskfFotviGGtV/Baqa', '2025-07-10 14:35:32', NULL, 'Formal', '', 'Yes', 'Yes', '2025-07-11', '2025-07-15', '', '0000-00-00', 0),
(79, 'Thomas Sam', '', '', 7, 7, 'FMC-F0104-KM', NULL, NULL, NULL, NULL, NULL, '0554828663', NULL, 'tomsam@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '', '', '2025-07-14 09:56:36', '71db16d8a3251debcd9e087f7fd94920', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(80, 'JACOB', 'F', 'AYIEI', 7, 17, 'FMC-A0101-KM', NULL, '2014-05-12', 'town', 'Monday', 'Male', '0551756789', '', '', 'TAKORADI', 'ws-125-4785', 'Single', 'KIKAM', 'Western', '', 'active', '', '$2y$10$Ktu0o6W7kKP5ThYPvD39j./np2YrOKicGVWtdmSsU.n72V/gPVnSi', '2025-07-14 21:08:51', NULL, 'Self Employed', 'cook', 'Yes', 'Yes', '0000-00-00', '0000-00-00', '', '2020-05-26', 0),
(81, 'BARNABAS', '', 'QUAYSON-OTOO', 7, 14, 'FMC-F0401-KM', NULL, '1990-07-15', 'Kwesimintsim', 'Sunday', 'Male', '0242363905', '02334556679', 'barnasco4uallgh@gmail.com', 'Kwesimintsim', 'Ws-789-5678', 'Married', 'Takoradi', 'Western', 'member_68759a32852e4.png', 'active', '', '$2y$10$imBj4hXYo4UisjpsqxH8bOw.d7FbZ5P8oiKtyDuJnClCUEOjoxARO', '2025-07-14 21:11:19', NULL, 'Formal', 'Accoutrement', 'Yes', 'Yes', '0000-00-00', '0000-00-00', '', '2020-03-08', 0),
(82, 'GLADYS', 'F', 'KANKAM', 7, 18, 'FMC-A0201-KM', NULL, '2010-09-15', 'new site', 'Wednesday', 'Female', '0544842820', '', '', 'tanokrom', 'ws-455-7895', 'Divorced', 'HOTOPO', 'Volta', 'member_687579301c61b.png', 'active', '', '$2y$10$fzPvn8JyXyfh1ff/tcgRveDHqSFSofPqy.C/nazXHZpuBfDI7JD4e', '2025-07-14 21:12:21', NULL, 'Self Employed', 'show maker', 'No', 'No', '0000-00-00', '0000-00-00', '', '2021-12-10', 0),
(83, 'MERCY', 'ABA', 'YAWSON', 7, 8, 'FMC-F0203-KM', NULL, '2022-01-03', 'kwesimintsim', 'Monday', 'Female', '0557295848', '025896451', '', 'takoradi', 'ws-125-4785', 'Single', 'HOTOPO', 'Western', 'member_687577b426d89.png', 'active', '', '$2y$10$OJ9PU/J795r258zklWAz6ehaNhMdo0dzMAu1UWRT2oFA4NXmP2ASe', '2025-07-14 21:12:50', NULL, 'Informal', 'driver', 'Yes', 'No', '0000-00-00', '0000-00-00', '', '2024-06-03', 0),
(84, 'COMFORT', '', 'AIDOO', 7, 19, 'FMC-A0301-KM', NULL, '1994-11-23', 'kuntu', 'Wednesday', 'Female', '0550318628', '', '', '', '', 'Married', 'AGONA', 'Central', 'member_6875951bdedf7.png', 'active', '', '$2y$10$8/rcWvXPDWrPsGiX/B.QhuHLRyDJJhwspTmTj7yU5W4zwlNrFHmlC', '2025-07-14 22:54:24', NULL, 'Formal', 'BANKER', 'Yes', 'Yes', '0000-00-00', '0000-00-00', '', '1982-12-29', 0),
(85, 'SARAH', '', 'JOHNSON', 7, 12, 'FMC-K0301-KM', NULL, '2011-03-18', 'TARWAA', 'Friday', 'Female', '0564789369', '', '', '', '', 'Single', 'KIMBU', 'Greater Accra', 'member_6875922927c23.png', 'active', '', '$2y$10$wDL/sk3hM6SVh4HeB4QtV.SlwUzO0y9u7GOoJ3LO9zQ8Apl4sL.IS', '2025-07-14 23:08:07', NULL, 'Formal', 'COOK', 'Yes', 'Yes', '0000-00-00', '0000-00-00', '', '2017-10-20', 0),
(86, 'FIIFI', '', 'NASH', 7, 11, 'FMC-K0201-KM', NULL, '2014-05-12', 'YAWA', 'Monday', 'Male', '0356987415', '', '', 'TITIKO', 'WH-145-5879', 'Widowed', 'KIKAM', 'Bono', 'member_687590dee00b8.png', 'active', '', '$2y$10$ntCIFGuhhUhMtYZkmohzuOFxfVnVR2Llx/booTzeLG6/j/wwJqpC.', '2025-07-14 23:12:27', NULL, 'Informal', 'MICH', 'No', 'Yes', '0000-00-00', '0000-00-00', '', '2012-06-06', 0),
(87, 'NANA', '', 'YAW', 7, 20, 'FMC-A0701-KM', NULL, '2014-12-30', 'KOW', 'Tuesday', 'Male', '0275115851', '', '', 'TAKORADI', 'ws-125-4785', 'Divorced', 'KIKAM', 'Bono', 'member_687788a18ea80.jpg', 'de-activated', '2025-07-16 12:04:49', '$2y$10$cJrn8rSBim27jP3FH3za9.6hviQO8L3k6aJPwQ1Vo5gPDjHoudkH6', '2025-07-14 23:13:49', NULL, 'Self Employed', 'TEACHER', 'No', 'No', '0000-00-00', '0000-00-00', '', '2008-05-12', 0),
(88, 'NANA', '', 'KWEKU', 7, 21, 'FMC-K0601-KM', NULL, '2013-02-04', 'Kasoa', 'Monday', 'Male', '0275115850', '', '', '42 Mapple St', '', 'Married', 'Tonawanda', 'Central', 'member_6877869e02fb0.jpg', 'active', '', '$2y$10$81DucBfMTbj7.Q.wAzvH8.AweIXwOywImNRa5jsAQWvc0pNoPRc4u', '2025-07-14 23:45:43', NULL, 'Formal', '', 'No', 'Yes', '0000-00-00', '2025-07-15', '', '2024-12-31', 0),
(90, 'James', '', 'Evens', 7, 20, 'FMC-A0702-KM', NULL, NULL, NULL, NULL, NULL, '7162381865', NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '', '', '2025-07-16 19:24:19', '7db1aabd52b44f6ca1572b93faa5aa97', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(91, 'Thomas Sam', '', '', 7, 7, 'FMC-F0105-KM', NULL, NULL, NULL, NULL, NULL, '0554828662', NULL, 'tomsam@gmail.comm', NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '', '', '2025-07-18 15:49:02', '56753cdc9dcd42b5ee88ffb5c34771ee', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(92, 'Test', '', 'Test', 7, 19, 'FMC-A0302-KM', NULL, '2024-07-09', 'kdfladskjlfsda', 'Tuesday', 'Male', '0383884844', '', '', '', '', 'Married', 'adifalfkdjfa', 'Oti', '', 'active', '', '$2y$10$cy/HU5EM4JuScA6iVUyfDeRVgx0AKndOQodY1IPfG0iiTES5pY5A6', '2025-07-18 22:17:52', NULL, 'Formal', '', 'No', 'No', '0000-00-00', '0000-00-00', '', '0000-00-00', 0),
(93, 'Ekow', '', 'Mensah', 7, 20, 'FMC-A0703-KM', NULL, NULL, NULL, NULL, NULL, '05000000', NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '', '', '2025-07-20 16:19:03', 'd09e794d6cc5da99364bcf6a7a726a01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0);

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
(65, 69, 'dafsdfsd', '45343534', 'fadsfadsf'),
(72, 70, 'tito', '3838383', 'ddldkfld'),
(73, 71, 'tito', '4848484848', 'broooo'),
(77, 73, 'FATIMATU AWUDU', '0554828663', 'sis'),
(82, 83, 'GLADYS KANKAM', '0544842820', 'sister'),
(83, 80, 'GLADYS KANKAM', '0544842820', 'sister'),
(84, 82, 'JACOB F AYIEI', '0551756789', 'brother'),
(86, 86, 'FIIFI YAWSON', '0242363905', 'BRO'),
(87, 85, 'NAAYAW', '01254789633', 'FATHER'),
(88, 84, 'ABA MENS', '02587899663', 'MOTHER'),
(89, 81, 'Nana Nkeyah', '0277384201', 'Son'),
(90, 88, 'Tito Nash', '0545644749', 'Brother'),
(91, 87, 'FIIFI YAWSON', '0242363905', 'BRO'),
(92, 89, 'ghjgj', '7675765756', 'jhgjhgjh'),
(93, 72, 'kjkhkh', '79798977', 'bkkjk'),
(94, 92, 'tito', '05456474484', 'brother');

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
(57, 1, 54, NULL, NULL),
(58, 2, 54, NULL, NULL),
(61, 2, 69, NULL, NULL),
(65, 1, 70, NULL, NULL),
(66, 2, 71, NULL, NULL),
(76, 2, 83, NULL, NULL),
(77, 7, 83, NULL, NULL),
(78, 4, 83, NULL, NULL),
(79, 7, 80, NULL, NULL),
(80, 4, 82, NULL, NULL),
(81, 6, 82, NULL, NULL),
(85, 4, 86, NULL, NULL),
(86, 4, 85, NULL, NULL),
(87, 6, 85, NULL, NULL),
(88, 2, 84, NULL, NULL),
(89, 4, 81, NULL, NULL),
(90, 5, 81, NULL, NULL),
(91, 6, 81, NULL, NULL),
(92, 2, 88, NULL, NULL),
(93, 1, 88, NULL, NULL),
(94, 3, 87, NULL, NULL),
(95, 7, 87, NULL, NULL),
(96, 4, 87, NULL, NULL),
(97, 2, 72, NULL, NULL),
(98, 3, 92, NULL, NULL),
(99, 8, 92, NULL, NULL);

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
(70, 1),
(70, 2),
(71, 2),
(72, 1),
(80, 6),
(80, 8),
(81, 2),
(81, 3),
(81, 7),
(81, 9),
(82, 2),
(82, 5),
(82, 6),
(83, 2),
(83, 6),
(83, 7),
(84, 3),
(85, 9),
(86, 2),
(86, 6),
(87, 2),
(87, 7),
(87, 8),
(88, 2),
(88, 9),
(92, 2);

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

--
-- Dumping data for table `member_transfers`
--

INSERT INTO `member_transfers` (`id`, `member_id`, `from_class_id`, `to_class_id`, `transfer_date`, `transferred_by`, `old_crn`) VALUES
(17, 72, 8, 7, '2025-07-10', 3, 'FMC-F0201-KM'),
(18, 72, 7, 8, '2025-07-10', 3, 'FMC-F0101-KM'),
(19, 72, 8, 7, '2025-07-10', 3, 'FMC-F0101-KM');

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
(73, 'view_church_list', 'Church List', 'fas fa-church', 'views/church_list.php', 'Churches', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

CREATE TABLE `organizations` (
  `id` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `organizations`
--

INSERT INTO `organizations` (`id`, `church_id`, `name`, `description`) VALUES
(1, 7, 'Girls Guild', 'Girls Guild Group'),
(2, 7, 'choir', ''),
(3, 7, 'brigade', ''),
(4, 7, 'MYF', ''),
(5, 7, 'singing band', ''),
(6, 7, 'SUWMA', ''),
(7, 7, 'GIRLS FF', ''),
(8, 7, 'ddkdsk', 'dksdlkd');

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
  `payment_date` date DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
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

INSERT INTO `payments` (`id`, `member_id`, `sundayschool_id`, `church_id`, `payment_type_id`, `amount`, `payment_date`, `recorded_by`, `mode`, `description`, `reversal_requested_at`, `reversal_requested_by`, `reversal_approved_at`, `reversal_approved_by`, `reversal_undone_at`, `reversal_undone_by`) VALUES
(144, 72, NULL, 7, 1, 10.00, '2025-07-14', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(145, 81, NULL, NULL, 1, 10.00, '2025-07-15', NULL, 'Cash', 'june', '2025-07-14 20:17:51', 3, '2025-07-14 20:18:06', 3, NULL, NULL),
(146, 84, NULL, NULL, 4, 100.00, '2025-07-15', NULL, 'Cash', 'june', NULL, NULL, NULL, NULL, NULL, NULL),
(147, 80, NULL, NULL, 3, 150.00, '2025-07-15', NULL, 'Cash', 'july', NULL, NULL, NULL, NULL, NULL, NULL),
(148, 86, NULL, NULL, 6, 250.00, '2025-07-15', NULL, 'Cash', 'feb', NULL, NULL, NULL, NULL, NULL, NULL),
(149, 80, NULL, 7, 1, 30.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(150, 80, NULL, 7, 4, 50.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(151, 80, NULL, 7, 5, 20.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(152, 80, NULL, 7, 6, 100.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(153, 81, NULL, 7, 1, 250.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(154, 81, NULL, 7, 4, 150.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(155, 81, NULL, 7, 5, 20.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(156, 81, NULL, 7, 6, 120.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(157, 83, NULL, 7, 1, 25.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(158, 83, NULL, 7, 3, 200.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(159, 83, NULL, 7, 4, 75.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(160, 84, NULL, 7, 1, 150.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(161, 84, NULL, 7, 3, 20.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(162, 84, NULL, 7, 4, 130.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(163, 84, NULL, 7, 5, 60.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(164, 73, NULL, 7, 3, 20.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(165, 73, NULL, 7, 4, 30.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(167, 73, NULL, 7, 6, 60.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(168, 81, NULL, 7, 1, 210.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(169, 81, NULL, 7, 3, 75.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(170, 81, NULL, 7, 4, 55.00, '2025-07-15', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(193, 72, NULL, 7, 4, 50.00, '2025-07-17', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(194, 72, NULL, 7, 6, 100.00, '2025-07-17', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(195, NULL, 8, NULL, 6, 100.00, '2025-07-17', NULL, 'Cash', 'Single SS', NULL, NULL, NULL, NULL, NULL, NULL),
(202, 80, NULL, 7, 4, 9.00, '2025-07-17', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(218, NULL, 8, NULL, 6, 100.00, '2025-07-18', NULL, 'Cash', 'single', NULL, NULL, NULL, NULL, NULL, NULL),
(227, 72, NULL, 7, 4, 200.00, '2025-07-18', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(228, 72, NULL, 7, 6, 100.00, '2025-07-18', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(229, 72, NULL, 7, 4, 200.00, '2025-07-18', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(230, 72, NULL, 7, 6, 100.00, '2025-07-18', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(244, 79, NULL, 7, 6, 10.00, '2025-07-18', NULL, 'Cash', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(245, NULL, 8, 7, 3, 67.00, '2025-07-18', NULL, 'Cash', 'offertory', NULL, NULL, NULL, NULL, NULL, NULL),
(246, NULL, 8, 7, 4, 98.00, '2025-07-18', NULL, 'Cash', 'harvest', NULL, NULL, NULL, NULL, NULL, NULL),
(248, 72, NULL, 7, 6, 10.00, '2025-07-18', NULL, 'Cash', '', NULL, NULL, NULL, NULL, NULL, NULL),
(249, 72, NULL, NULL, 6, 8.00, '2025-07-18', NULL, 'Cash', 'Educational Fund', NULL, NULL, NULL, NULL, NULL, NULL),
(250, 72, NULL, NULL, 6, 10.00, '2025-07-18', NULL, 'Cash', 'Test', NULL, NULL, NULL, NULL, NULL, NULL),
(251, 79, NULL, 7, 4, 10.00, '2025-07-18', NULL, 'Cash', '', NULL, NULL, NULL, NULL, NULL, NULL),
(252, 72, NULL, NULL, 6, 10.00, '2025-07-20', NULL, 'Cash', 'paa kow', NULL, NULL, NULL, NULL, NULL, NULL);

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
(10, 145, 'request', 3, '2025-07-14 20:17:51', 'Requested by user'),
(11, 145, 'approve', 3, '2025-07-14 20:18:06', 'Approved by admin');

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
(1, 'Tithe', '', 1),
(3, 'Offertory', '', 1),
(4, 'harvest', '', 1),
(5, 'welfare', '', 1),
(6, 'education fund', '', 1);

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
(316, 'pending_members_list', NULL, NULL);

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
(1117, 38, 'deny', 'user', 38, NULL, '2025-07-20 17:02:12', 'denied');

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
  `name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`) VALUES
(1, 'Super Admin'),
(2, 'Admin'),
(3, 'Steward'),
(4, 'Rev. Ministers'),
(5, 'Class Leader'),
(6, 'Organisational Leader'),
(7, 'Cashier'),
(8, 'Health');

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
(1, 'Reverend Minister', '', '2025-07-09 15:38:48', '2025-07-09 15:38:48'),
(2, 'Bible Class Leader', '', '2025-07-09 15:54:04', '2025-07-09 15:54:04'),
(3, 'STEWARDS', '', '2025-07-14 20:23:44', '2025-07-14 20:23:44'),
(4, 'ORGANIZATIONAL LEARDER', '', '2025-07-14 20:24:18', '2025-07-14 20:24:18'),
(5, 'ORGANIZATIONAL executive', '', '2025-07-14 20:24:42', '2025-07-14 20:24:42'),
(6, 'cleaner', '', '2025-07-14 20:25:01', '2025-07-14 20:25:01'),
(7, 'bands man', '', '2025-07-14 20:25:14', '2025-07-14 20:25:14'),
(8, 'media team', '', '2025-07-14 20:25:27', '2025-07-14 20:25:27'),
(9, 'cashers', '', '2025-07-14 20:25:46', '2025-07-14 20:25:46'),
(10, 'Hour', 'ffsfs', '2025-07-18 22:04:10', '2025-07-18 22:04:10');

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
(2, 77),
(2, 310),
(5, 77),
(5, 78),
(5, 79),
(5, 80),
(5, 81),
(5, 82),
(5, 83),
(5, 84),
(5, 85),
(5, 86),
(5, 87),
(5, 88),
(5, 89),
(5, 90),
(5, 91),
(5, 92),
(5, 93),
(5, 94),
(5, 95),
(5, 96),
(5, 97),
(5, 98),
(5, 99),
(5, 100),
(5, 107),
(5, 108),
(5, 109),
(5, 110),
(5, 111),
(5, 112),
(5, 113),
(5, 114),
(5, 115),
(5, 116),
(5, 117),
(5, 118),
(5, 119),
(5, 120),
(5, 121),
(5, 122),
(5, 123),
(5, 124),
(5, 125),
(5, 126),
(5, 127),
(5, 128),
(5, 129),
(5, 130),
(5, 131),
(5, 132),
(5, 133),
(5, 134),
(5, 135),
(5, 136),
(5, 137),
(5, 138),
(5, 139),
(5, 140),
(5, 141),
(5, 142),
(5, 143),
(5, 144),
(5, 145),
(5, 146),
(5, 147),
(5, 148),
(5, 149),
(5, 150),
(5, 151),
(5, 152),
(5, 153),
(5, 154),
(5, 155),
(5, 156),
(5, 157),
(5, 158),
(5, 159),
(5, 160),
(5, 161),
(5, 162),
(5, 267),
(5, 268),
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
(5, 298),
(5, 310);

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
(58, NULL, '0545644749', 'Hi FATIMATU, complete your registration here: http://localhost/myfreeman/views/complete_registration.php?token=264b042087e78dbe84a4ee1b008e05a8', NULL, 'registration', 'success', 'arkesel', '2025-07-10 13:21:58', '{\n    \"data\": [\n        {\n            \"id\": \"a55db606-4b14-42c8-870d-23b0e9bffc96\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(59, NULL, '0545644749', 'Hi Ekow, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=beea87a7a8d25a4fd0362093865d75f8', NULL, 'registration', 'success', 'arkesel', '2025-07-10 13:25:48', '{\n    \"data\": [\n        {\n            \"id\": \"9b31bcb4-583c-4ad7-ac33-46f1853f27f7\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(60, NULL, '0545644749', 'Hi James, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=5deed3fa05d36650fb61eead0701008a', NULL, 'registration', 'success', 'arkesel', '2025-07-10 13:29:59', '{\n    \"data\": [\n        {\n            \"id\": \"728405a2-caee-4c3f-b2e3-780b01b44b85\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(61, NULL, '0545644749', 'Hi James, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=5deed3fa05d36650fb61eead0701008a', NULL, 'registration', 'success', 'arkesel', '2025-07-10 13:32:06', '{\n    \"data\": [\n        {\n            \"id\": \"7646106d-3e0a-4cc9-a495-864df8a249a1\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(62, NULL, '0545644749', 'Hi James, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=5deed3fa05d36650fb61eead0701008a', NULL, 'registration', 'success', 'arkesel', '2025-07-10 13:32:25', '{\n    \"data\": [\n        {\n            \"id\": \"f719ef0a-b9e6-4c60-aeef-0c1f36890658\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(63, NULL, '0545644749', 'Hi James, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=5deed3fa05d36650fb61eead0701008a', NULL, 'registration', 'success', 'arkesel', '2025-07-10 13:43:27', '{\n    \"data\": [\n        {\n            \"id\": \"a5d32bba-5ebc-40d7-ad06-fb522147d0ad\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(64, NULL, '0545644749', 'Hi James, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=5deed3fa05d36650fb61eead0701008a', NULL, 'registration', 'success', 'arkesel', '2025-07-10 14:21:36', '{\n    \"data\": [\n        {\n            \"id\": \"91e736b4-ef0e-4e15-b3b8-8975e0f0d095\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(65, NULL, '0545644749', 'Hi Ekow, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=d9fb586b7892f8d0fc79a98be7341a92', NULL, 'registration', 'success', 'arkesel', '2025-07-10 14:27:14', '{\n    \"data\": [\n        {\n            \"id\": \"0f752a99-053a-4eeb-98b0-9fe294c131f0\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(66, NULL, '0545644749', 'Hi Ekow, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=a2bdc466489fd8a10f41e885b6e2304e', NULL, 'registration', 'success', 'arkesel', '2025-07-10 14:30:35', '{\n    \"data\": [\n        {\n            \"id\": \"339baa0a-167b-4800-9fff-f3a0e5cc908f\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(67, NULL, '0545644749', 'Hi, Grace, you have been converted to be a member. Follow the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=6395b30a3140c483b1133bedb5fd14fc', NULL, 'registration', 'success', 'arkesel', '2025-07-10 14:35:39', '{\n    \"data\": [\n        {\n            \"id\": \"4ed63107-4341-48a7-a80a-9bdff8a29e1b\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(68, NULL, '0545644749', 'Dear Ekow  Mensah, your payment of ₵100.00 has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-10 14:39:19', '{\n    \"data\": [\n        {\n            \"id\": \"bf8e2163-bb17-46c8-9cda-0e361e647d3c\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(69, NULL, '0545644749', 'Hi how are you', NULL, 'manual', 'fail', 'arkesel', '2025-07-12 11:42:47', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-12 13:42:47\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": 3,\n            \"message\": \"Hi how are you\",\n            \"recipients\": [\n                \"0545644749\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Sat, 12 Jul 2025 11:42:47 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1499\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=oFXFRdL4kEIGQyTSHb3KTONP00JbxofW1R3Cw97S; expires=Sat, 12-Jul-2025 13:42:47 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"The use of a numeric sender id is not allowed\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 1.546083,\n            \"connect_time\": 0.621584,\n            \"namelookup_time\": 0.325564,\n            \"pretransfer_time\": 1.253129,\n            \"starttransfer_time\": 1.546053,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 67,\n            \"size_download\": 76,\n            \"speed_download\": 49,\n            \"speed_upload\": 43,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 67,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 67\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Sat, 12 Jul 2025 11:42:47 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1499\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=oFXFRdL4kEIGQyTSHb3KTONP00JbxofW1R3Cw97S; expires=Sat, 12-Jul-2025 13:42:47 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(70, NULL, '0545644749', 'hi', NULL, 'manual', 'fail', 'arkesel', '2025-07-12 11:42:57', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-12 13:42:57\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": 3,\n            \"message\": \"hi\",\n            \"recipients\": [\n                \"0545644749\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Sat, 12 Jul 2025 11:42:57 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=XtgN5Slzzno9ZXdN8Jx0M3SKx8qJJlONOYZ21LKA; expires=Sat, 12-Jul-2025 13:42:57 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"The use of a numeric sender id is not allowed\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 0.83113,\n            \"connect_time\": 0.201558,\n            \"namelookup_time\": 0.002963,\n            \"pretransfer_time\": 0.544155,\n            \"starttransfer_time\": 0.831091,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 55,\n            \"size_download\": 76,\n            \"speed_download\": 91,\n            \"speed_upload\": 66,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 55,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 55\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Sat, 12 Jul 2025 11:42:57 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=XtgN5Slzzno9ZXdN8Jx0M3SKx8qJJlONOYZ21LKA; expires=Sat, 12-Jul-2025 13:42:57 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(71, NULL, '0545644749', 'HI', NULL, NULL, 'sent', 'unknown', '2025-07-12 11:47:23', '{\"data\":[{\"id\":\"62450555-1da1-4d47-ba3a-9adf951ace27\",\"recipient\":\"233545644749\"}],\"status\":\"success\"}'),
(72, NULL, '0545644749', 'hi', NULL, 'manual', 'fail', 'arkesel', '2025-07-12 11:48:15', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-12 13:48:15\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": 3,\n            \"message\": \"hi\",\n            \"recipients\": [\n                \"233545644749\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Sat, 12 Jul 2025 11:48:16 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=OoqnT53WmGDqqdRRI4YNQAJBm8bG1H2eGWko6Jy7; expires=Sat, 12-Jul-2025 13:48:16 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"The use of a numeric sender id is not allowed\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 1.171524,\n            \"connect_time\": 0.520398,\n            \"namelookup_time\": 0.293147,\n            \"pretransfer_time\": 0.861286,\n            \"starttransfer_time\": 1.1715,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 57,\n            \"size_download\": 76,\n            \"speed_download\": 64,\n            \"speed_upload\": 48,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 57,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 57\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Sat, 12 Jul 2025 11:48:16 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=OoqnT53WmGDqqdRRI4YNQAJBm8bG1H2eGWko6Jy7; expires=Sat, 12-Jul-2025 13:48:16 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(73, NULL, '0545644749', 'hi', NULL, 'manual', 'fail', 'arkesel', '2025-07-12 11:50:07', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-12 13:50:07\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": 3,\n            \"message\": \"hi\",\n            \"recipients\": [\n                \"233545644749\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Sat, 12 Jul 2025 11:50:07 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1499\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=CktybLdkf0mkrqLneTStDnbozPBRdaq4QO8nXxTA; expires=Sat, 12-Jul-2025 13:50:07 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"The use of a numeric sender id is not allowed\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 0.863495,\n            \"connect_time\": 0.253637,\n            \"namelookup_time\": 0.004019,\n            \"pretransfer_time\": 0.563544,\n            \"starttransfer_time\": 0.863415,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 57,\n            \"size_download\": 76,\n            \"speed_download\": 88,\n            \"speed_upload\": 66,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 57,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 57\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Sat, 12 Jul 2025 11:50:07 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1499\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=CktybLdkf0mkrqLneTStDnbozPBRdaq4QO8nXxTA; expires=Sat, 12-Jul-2025 13:50:07 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(74, NULL, '0545644749', 'Hello, {name}', 'Birthday', NULL, 'sent', 'unknown', '2025-07-12 11:51:03', '{\"data\":[{\"id\":\"2e981ead-b99d-4706-8de4-5895ec17f645\",\"recipient\":\"233545644749\"}],\"status\":\"success\"}'),
(75, NULL, '0545644749', 'Hello, {name}', 'Birthday', NULL, 'fail', 'unknown', '2025-07-12 11:52:47', '{\"status\":\"error\",\"message\":\"SMS API key not configured\"}'),
(76, NULL, '0545644749', 'Hi', NULL, NULL, 'fail', 'unknown', '2025-07-12 11:53:04', '{\"status\":\"error\",\"message\":\"SMS API key not configured\"}'),
(77, NULL, '0545644749', 'hi', NULL, NULL, 'sent', 'unknown', '2025-07-12 11:53:59', '{\"data\":[{\"id\":\"93f23f31-03b7-4055-8fd2-1a0da0e7bc69\",\"recipient\":\"233545644749\"}],\"status\":\"success\"}'),
(78, NULL, '0545644749', 'Hello', NULL, 'manual', 'fail', 'arkesel', '2025-07-12 11:54:53', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-12 13:54:53\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": 3,\n            \"message\": \"Hello\",\n            \"recipients\": [\n                \"233545644749\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Sat, 12 Jul 2025 11:54:53 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=WUSmSwZyJFyNVDyeoO8pOyr07JqUHE3dum7KUznJ; expires=Sat, 12-Jul-2025 13:54:53 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"The use of a numeric sender id is not allowed\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 1.126413,\n            \"connect_time\": 0.500323,\n            \"namelookup_time\": 0.003612,\n            \"pretransfer_time\": 0.831497,\n            \"starttransfer_time\": 1.126381,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 60,\n            \"size_download\": 76,\n            \"speed_download\": 67,\n            \"speed_upload\": 53,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 60,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 60\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Sat, 12 Jul 2025 11:54:53 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=WUSmSwZyJFyNVDyeoO8pOyr07JqUHE3dum7KUznJ; expires=Sat, 12-Jul-2025 13:54:53 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(79, NULL, '0545644749', 'Hey', NULL, 'manual', 'success', 'arkesel', '2025-07-12 12:00:24', '{\n    \"data\": [\n        {\n            \"id\": \"ed21fbe1-8bd7-44b0-bba7-328b12f0855d\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(80, NULL, '0242109740', 'Hi Sample, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=251521d68dbeaf3bce938661f640fa80', NULL, 'registration', 'success', 'arkesel', '2025-07-12 12:02:58', '{\n    \"data\": [\n        {\n            \"id\": \"d61e5e33-9245-464d-97ff-1d5888477b15\",\n            \"recipient\": \"233242109740\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(81, NULL, '0242109741', 'Hi Sample, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=4070eea1be6b843e6dc5cf5304ec24d5', NULL, 'registration', 'success', 'arkesel', '2025-07-12 12:04:45', '{\n    \"data\": [\n        {\n            \"id\": \"a49e625a-d178-428e-83ab-54f67b23078a\",\n            \"recipient\": \"233242109741\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(82, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵100.00 for Sample Offertory has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-12 15:14:54', '{\n    \"data\": [\n        {\n            \"id\": \"3787a43e-ac21-46a7-82f8-052d35185cd7\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(83, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵200.00 for Cash has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-12 15:16:39', '{\n    \"data\": [\n        {\n            \"id\": \"ed196995-26f3-49e5-a29e-cb0f3eb96a1e\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(84, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵100.00 has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-12 17:36:08', '{\n    \"data\": [\n        {\n            \"id\": \"4e2ae755-e0b6-4d09-a401-7882d9d54a8f\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(85, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵100.00 for Something has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-13 18:28:16', '{\n    \"data\": [\n        {\n            \"id\": \"c9223f98-8434-4872-83cc-c3db4ecc5fd0\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(86, NULL, '0545644749', 'Hello, {name}', 'Birthday', NULL, 'sent', 'unknown', '2025-07-14 17:48:52', '{\"data\":[{\"id\":\"0f62eb9a-adec-449c-a77f-e3c6f9efb1d0\",\"recipient\":\"233545644749\"}],\"status\":\"success\"}'),
(87, NULL, '0551756789', 'Hi JACOB, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=50ba56480ec53214a9f225c519eeb3e1', NULL, 'registration', 'success', 'arkesel', '2025-07-14 21:08:51', '{\n    \"data\": [\n        {\n            \"id\": \"47df5a1c-def1-4e0f-b5c2-1dabe0f188b2\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(88, NULL, '0242363905', 'Hi BARNABAS, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=b7bb943d6434164dafdac8a93660369b', NULL, 'registration', 'success', 'arkesel', '2025-07-14 21:11:20', '{\n    \"data\": [\n        {\n            \"id\": \"d14e43db-19ae-4ad0-8140-cd22eef1a037\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(89, NULL, '0544842820', 'Hi GLADYS, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=7c37cb96538b6daafc03eec384178372', NULL, 'registration', 'success', 'arkesel', '2025-07-14 21:12:21', '{\n    \"data\": [\n        {\n            \"id\": \"f7de2056-bf6b-4852-9c4f-df55b7e116ca\",\n            \"recipient\": \"233544842820\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(90, NULL, '0557295848', 'Hi MERCY, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=31ef4ff2d6786787b0fa4c2f6221c5e4', NULL, 'registration', 'success', 'arkesel', '2025-07-14 21:12:50', '{\n    \"data\": [\n        {\n            \"id\": \"86fb3324-7b7d-490c-bb23-71db32040eca\",\n            \"recipient\": \"233557295848\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(91, NULL, '0242363905', 'hope to see you again', NULL, 'manual', 'success', 'arkesel', '2025-07-14 21:42:00', '{\n    \"data\": [\n        {\n            \"id\": \"cb62fc90-640b-44bc-b983-4defe12927c2\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(92, NULL, '0550318628', 'Hi COMFORT, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=e27aa7e855f0d77699525d252592bd29', NULL, 'registration', 'success', 'arkesel', '2025-07-14 22:54:24', '{\n    \"data\": [\n        {\n            \"id\": \"438df3ff-3bcf-416f-a000-d364ff1592f5\",\n            \"recipient\": \"233550318628\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(93, NULL, '0564789369', 'Hi SARAH, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=1379bc7e28d51437eadd3f5aa3f8263a', NULL, 'registration', 'success', 'arkesel', '2025-07-14 23:08:07', '{\n    \"data\": [\n        {\n            \"id\": \"1128ecc5-d80b-4907-ad6e-9a3dac135223\",\n            \"recipient\": \"233564789369\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(94, NULL, '0356987415', 'Hi FIIFI, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=d4514941528511c88f658061a72b656c', NULL, 'registration', 'fail', 'arkesel', '2025-07-14 23:12:27', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-14 23:12:27\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi FIIFI, click on the link to complete your registration: https:\\/\\/myfreeman.mensweb.xyz\\/views\\/complete_registration.php?token=d4514941528511c88f658061a72b656c\",\n            \"recipients\": [\n                \"233356987415\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Mon, 14 Jul 2025 23:12:27 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=pXDE68LplTlnMoOChoMpRmZ1KUSmw8cfJ0BlC7HQ; expires=Tue, 15-Jul-2025 01:12:27 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 0.24035200000000001008260142043582163751125335693359375,\n            \"connect_time\": 0.042160999999999997089883407852539676241576671600341796875,\n            \"namelookup_time\": 1.3999999999999999789990039189557791132756392471492290496826171875e-5,\n            \"pretransfer_time\": 0.09022099999999999564437302979058586061000823974609375,\n            \"starttransfer_time\": 0.240329999999999988080645607624319382011890411376953125,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 228,\n            \"size_download\": 61,\n            \"speed_download\": 254,\n            \"speed_upload\": 950,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 228,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"* Hostname sms.arkesel.com was found in DNS cache\\n*   Trying 66.175.211.30...\\n* TCP_NODELAY set\\n* Connected to sms.arkesel.com (66.175.211.30) port 443 (#0)\\n* ALPN, offering http\\/1.1\\n* successfully set certificate verify locations:\\n*   CAfile: \\/etc\\/pki\\/tls\\/certs\\/ca-bundle.crt\\n  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN, server accepted to use http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 228\\r\\n\\r\\n* upload completely sent off: 228 out of 228 bytes\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Mon, 14 Jul 2025 23:12:27 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=pXDE68LplTlnMoOChoMpRmZ1KUSmw8cfJ0BlC7HQ; expires=Tue, 15-Jul-2025 01:12:27 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(95, NULL, '0275115851', 'Hi NANA, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=218baceee2d34dfb63abd2d6614f0165', NULL, 'registration', 'success', 'arkesel', '2025-07-14 23:13:50', '{\n    \"data\": [\n        {\n            \"id\": \"a868092b-2de1-4338-8dbd-70a523c64398\",\n            \"recipient\": \"233275115851\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(96, NULL, '027511585', 'Hi NANA, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=50ec52f52c8e8600d6ccbdeb3b4ca75b', NULL, 'registration', 'fail', 'arkesel', '2025-07-14 23:45:44', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-14 23:45:44\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi NANA, click on the link to complete your registration: https:\\/\\/myfreeman.mensweb.xyz\\/views\\/complete_registration.php?token=50ec52f52c8e8600d6ccbdeb3b4ca75b\",\n            \"recipients\": [\n                \"027511585\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Mon, 14 Jul 2025 23:45:44 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=ME3GKyd7hvvhASMJ7ro4IJmGeLfn2GhjGu4QJifv; expires=Tue, 15-Jul-2025 01:45:44 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 0.256601000000000023515411839980515651404857635498046875,\n            \"connect_time\": 0.042249000000000001830979812211808166466653347015380859375,\n            \"namelookup_time\": 1.900000000000000104603825601401467793039046227931976318359375e-5,\n            \"pretransfer_time\": 0.09486999999999999599875621925093582831323146820068359375,\n            \"starttransfer_time\": 0.2565749999999999975131004248396493494510650634765625,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 224,\n            \"size_download\": 61,\n            \"speed_download\": 238,\n            \"speed_upload\": 875,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 224,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"* Hostname sms.arkesel.com was found in DNS cache\\n*   Trying 66.175.211.30...\\n* TCP_NODELAY set\\n* Connected to sms.arkesel.com (66.175.211.30) port 443 (#0)\\n* ALPN, offering http\\/1.1\\n* successfully set certificate verify locations:\\n*   CAfile: \\/etc\\/pki\\/tls\\/certs\\/ca-bundle.crt\\n  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN, server accepted to use http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 224\\r\\n\\r\\n* upload completely sent off: 224 out of 224 bytes\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Mon, 14 Jul 2025 23:45:44 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=ME3GKyd7hvvhASMJ7ro4IJmGeLfn2GhjGu4QJifv; expires=Tue, 15-Jul-2025 01:45:44 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(97, NULL, '0242363905', 'Dear BARNABAS  QUAYSON-OTOO, your payment of â‚µ10.00 for june has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-15 00:17:24', '{\n    \"data\": [\n        {\n            \"id\": \"24fb7015-cfb2-4192-8999-447d8fcebb0b\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(98, NULL, '0550318628', 'Dear COMFORT  AIDOO, your payment of â‚µ100.00 for june has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-15 00:30:54', '{\n    \"data\": [\n        {\n            \"id\": \"02f81ad5-96ac-4e6d-ad6d-a88484681565\",\n            \"recipient\": \"233550318628\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(99, NULL, '0551756789', 'Dear JACOB F AYIEI, your payment of â‚µ150.00 for july has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-15 00:31:58', '{\n    \"data\": [\n        {\n            \"id\": \"8541ac27-9ba2-473a-aedc-9cb4495382a4\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(100, NULL, '0356987415', 'Dear FIIFI  NASH, your payment of â‚µ250.00 for feb has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'fail', 'arkesel', '2025-07-15 00:34:54', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-15 00:34:54\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Dear FIIFI  NASH, your payment of \\u20b5250.00 for feb has been received by Freeman Methodist Church - KM. Thank you.\",\n            \"recipients\": [\n                \"233356987415\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Tue, 15 Jul 2025 00:34:54 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=HS2NGVHmpGYgEjAqwKRrghqMobV1vFCbbpj4DJdS; expires=Tue, 15-Jul-2025 02:34:54 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 0.25657099999999999351274482251028530299663543701171875,\n            \"connect_time\": 0.040551999999999997770228077342835604213178157806396484375,\n            \"namelookup_time\": 1.69999999999999998659926114807916519566788338124752044677734375e-5,\n            \"pretransfer_time\": 0.0912590000000000067803540559907560236752033233642578125,\n            \"starttransfer_time\": 0.25655200000000000226663132707471959292888641357421875,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 182,\n            \"size_download\": 61,\n            \"speed_download\": 238,\n            \"speed_upload\": 710,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 182,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"* Hostname sms.arkesel.com was found in DNS cache\\n*   Trying 66.175.211.30...\\n* TCP_NODELAY set\\n* Connected to sms.arkesel.com (66.175.211.30) port 443 (#0)\\n* ALPN, offering http\\/1.1\\n* successfully set certificate verify locations:\\n*   CAfile: \\/etc\\/pki\\/tls\\/certs\\/ca-bundle.crt\\n  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN, server accepted to use http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 182\\r\\n\\r\\n* upload completely sent off: 182 out of 182 bytes\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Tue, 15 Jul 2025 00:34:54 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=HS2NGVHmpGYgEjAqwKRrghqMobV1vFCbbpj4DJdS; expires=Tue, 15-Jul-2025 02:34:54 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(101, NULL, '0551756789', 'Dear JACOB F AYIEI, your payment of â‚µ1.00 has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-15 07:27:05', '{\n    \"data\": [\n        {\n            \"id\": \"c8d73b2f-a0bf-4101-ae5c-76881f787eb9\",\n            \"recipient\": \"233551756789\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(102, NULL, '0242363905', 'Hi FIIFI, click on the link to complete your registration: https://myfreeman.mensweb.xyz/views/complete_registration.php?token=e2c22550c72bffe8389e7f7e88b319d1', NULL, 'registration', 'success', 'arkesel', '2025-07-16 00:19:00', '{\n    \"data\": [\n        {\n            \"id\": \"290d6c78-8cc3-4fc8-b179-10086a4dc422\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(103, NULL, '0554828663', 'hiii', NULL, 'manual', 'success', 'arkesel', '2025-07-16 16:37:42', '{\n    \"data\": [\n        {\n            \"id\": \"794c4827-5126-4112-9bfd-decfa916db9b\",\n            \"recipient\": \"233554828663\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(104, NULL, '0545644749', 'Test Message', NULL, 'manual', 'success', 'arkesel', '2025-07-16 19:23:01', '{\n    \"data\": [\n        {\n            \"id\": \"829ccbae-39d8-4e36-a7de-e962c3260371\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}');
INSERT INTO `sms_logs` (`id`, `member_id`, `phone`, `message`, `template_name`, `type`, `status`, `provider`, `sent_at`, `response`) VALUES
(105, NULL, '7162381865', 'Hi James, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=7db1aabd52b44f6ca1572b93faa5aa97', NULL, 'registration', 'fail', 'arkesel', '2025-07-16 19:24:21', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-16 21:24:21\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi James, click on the link to complete your registration: http:\\/\\/localhost\\/myfreeman\\/views\\/complete_registration.php?token=7db1aabd52b44f6ca1572b93faa5aa97\",\n            \"recipients\": [\n                \"7162381865\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Wed, 16 Jul 2025 19:24:21 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=uNbsxnLEW6Qqi21o1XQ8YwSVvB63j3CdsB6LXrjr; expires=Wed, 16-Jul-2025 21:24:21 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 0.905284,\n            \"connect_time\": 0.241457,\n            \"namelookup_time\": 0.014795,\n            \"pretransfer_time\": 0.558726,\n            \"starttransfer_time\": 0.905249,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 224,\n            \"size_download\": 61,\n            \"speed_download\": 67,\n            \"speed_upload\": 247,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 224,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 224\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Wed, 16 Jul 2025 19:24:21 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=uNbsxnLEW6Qqi21o1XQ8YwSVvB63j3CdsB6LXrjr; expires=Wed, 16-Jul-2025 21:24:21 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(106, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵10.00 for sample has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-16 19:56:41', '{\n    \"data\": [\n        {\n            \"id\": \"e10803bc-7be5-4074-83cb-a2fc7899c664\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(107, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵60.00 for sunday school has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-17 12:19:45', '{\n    \"data\": [\n        {\n            \"id\": \"531303f3-32bb-4511-a0ff-e9df74c3fd5b\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(108, NULL, '0242363905', 'Dear ROSEZALIN ama duntu, your payment of ₵3.00 for sunday school has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-17 13:09:21', '{\n    \"data\": [\n        {\n            \"id\": \"e089c038-c496-465b-9485-63f3a8beb900\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(109, NULL, '0242363905', 'Dear ROSEZALIN ama duntu, your payment of ₵100.00 for Sunday School has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-17 13:10:07', '{\n    \"data\": [\n        {\n            \"id\": \"93651839-9fe7-4c7c-a0d1-73aa2b4c7516\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(110, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵40.00 has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-17 13:10:39', '{\n    \"data\": [\n        {\n            \"id\": \"ba603411-40f5-4804-a233-e36afce1f5c8\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(111, NULL, '0242363905', 'Dear ROSEZALIN ama duntu, your payment of ₵29.00 for SUNDAY SCHOOL 1 has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-17 13:25:43', '{\n    \"data\": [\n        {\n            \"id\": \"a8e7da10-7208-4919-b50c-c0a388e1d4a2\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(112, NULL, '0242363905', 'Dear ROSEZALIN ama duntu, your payment of ₵100.00 for Single SS has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-17 13:54:05', '{\n    \"data\": [\n        {\n            \"id\": \"675ed611-65ac-4ded-9e6c-95ee413d0218\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(113, NULL, '0242363905', 'Dear ROSEZALIN ama duntu, your payment of ₵100.00 for single has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-18 11:20:19', '{\n    \"data\": [\n        {\n            \"id\": \"6c31ce1e-f9ad-44f4-919c-7e69cf4580a7\",\n            \"recipient\": \"233242363905\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(114, NULL, '0545644749', 'Hi test Bible Class message', NULL, NULL, 'sent', 'unknown', '2025-07-18 16:03:59', '{\"data\":[{\"id\":\"76a1f6a5-66a9-439d-8db4-8909d5821bb3\",\"recipient\":\"233545644749\"}],\"status\":\"success\"}'),
(115, NULL, '0554828663', 'Hi test Bible Class message', NULL, NULL, 'sent', 'unknown', '2025-07-18 16:04:01', '{\"data\":[{\"id\":\"ff8fb551-540f-4abd-98bf-e65bcee07864\",\"recipient\":\"233554828663\"}],\"status\":\"success\"}'),
(116, NULL, '0554828662', 'Hi test Bible Class message', NULL, NULL, 'sent', 'unknown', '2025-07-18 16:04:02', '{\"data\":[{\"id\":\"a53787a6-5fba-4c63-ab11-eb88277cec0d\",\"recipient\":\"233554828662\"}],\"status\":\"success\"}'),
(117, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵8.00 for Educational Fund has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-18 18:00:36', '{\n    \"data\": [\n        {\n            \"id\": \"dd4b1875-5818-4140-b867-2a03168ee897\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(118, NULL, '0545644749', 'Hi Test', NULL, NULL, 'sent', 'unknown', '2025-07-18 21:53:21', '{\"data\":[{\"id\":\"9296ff49-7f36-4ab0-9605-42ebd9d3c9b8\",\"recipient\":\"233545644749\"}],\"status\":\"success\"}'),
(119, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵10.00 for Test has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-18 21:57:32', '{\n    \"data\": [\n        {\n            \"id\": \"a34d7ceb-10b7-4f2e-ae22-f0d9f6c78f27\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(120, NULL, '0383884844', 'Hi Test, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=2f8a04a7904f3e505129504e2667ccf1', NULL, 'registration', 'fail', 'arkesel', '2025-07-18 22:17:54', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-19 00:17:54\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi Test, click on the link to complete your registration: http:\\/\\/localhost\\/myfreeman\\/views\\/complete_registration.php?token=2f8a04a7904f3e505129504e2667ccf1\",\n            \"recipients\": [\n                \"233383884844\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Fri, 18 Jul 2025 22:17:55 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=kXiUEN7gQYtkFsiIifDVAtki6l04BzkEpJub6F7b; expires=Sat, 19-Jul-2025 00:17:55 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 1.03024,\n            \"connect_time\": 0.252148,\n            \"namelookup_time\": 0.001775,\n            \"pretransfer_time\": 0.649804,\n            \"starttransfer_time\": 1.030214,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 225,\n            \"size_download\": 61,\n            \"speed_download\": 59,\n            \"speed_upload\": 218,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 225,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 225\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Fri, 18 Jul 2025 22:17:55 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=kXiUEN7gQYtkFsiIifDVAtki6l04BzkEpJub6F7b; expires=Sat, 19-Jul-2025 00:17:55 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}'),
(121, NULL, '0545644749', 'Dear Ekow Paa Mensah, your payment of ₵10.00 for paa kow has been received by Freeman Methodist Church - KM. Thank you.', NULL, 'payment', 'success', 'arkesel', '2025-07-20 15:56:58', '{\n    \"data\": [\n        {\n            \"id\": \"979a3907-b4fb-45cd-8edf-0c320113e168\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}'),
(122, NULL, '05000000', 'Hi Ekow, click on the link to complete your registration: http://localhost/myfreeman/views/complete_registration.php?token=d09e794d6cc5da99364bcf6a7a726a01', NULL, 'registration', 'fail', 'arkesel', '2025-07-20 16:19:06', '{\n    \"status\": \"error\",\n    \"message\": \"HTTP Error: 422\",\n    \"http_code\": 422,\n    \"debug\": {\n        \"time\": \"2025-07-20 18:19:06\",\n        \"url\": \"https:\\/\\/sms.arkesel.com\\/api\\/v2\\/sms\\/send\",\n        \"payload\": {\n            \"sender\": \"MyFreeman\",\n            \"message\": \"Hi Ekow, click on the link to complete your registration: http:\\/\\/localhost\\/myfreeman\\/views\\/complete_registration.php?token=d09e794d6cc5da99364bcf6a7a726a01\",\n            \"recipients\": [\n                \"05000000\"\n            ]\n        },\n        \"request_headers\": [\n            \"api-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\",\n            \"Content-Type: application\\/json\",\n            \"Accept: application\\/json\"\n        ],\n        \"http_status\": 422,\n        \"response_headers\": \"HTTP\\/1.1 422 Unprocessable Content\\r\\nServer: nginx\\r\\nContent-Type: application\\/json\\r\\nTransfer-Encoding: chunked\\r\\nConnection: keep-alive\\r\\nCache-Control: no-cache, private\\r\\nDate: Sun, 20 Jul 2025 16:19:06 GMT\\r\\nX-RateLimit-Limit: 1500\\r\\nX-RateLimit-Remaining: 1498\\r\\nAccess-Control-Allow-Origin: *\\r\\nSet-Cookie: arkesel_sms_messenger_session=VIW5GqmEmS2icbebRrYjbF2AEclCfoqmhjtJ6sG5; expires=Sun, 20-Jul-2025 18:19:06 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\nX-Frame-Options: SAMEORIGIN\\r\\n\\r\\n\",\n        \"response_body\": \"{\\\"message\\\":\\\"No valid number in recipients!\\\",\\\"status\\\":\\\"error\\\"}\",\n        \"curl_error\": \"\",\n        \"curl_info\": {\n            \"total_time\": 1.311258,\n            \"connect_time\": 0.239807,\n            \"namelookup_time\": 0.003481,\n            \"pretransfer_time\": 0.973489,\n            \"starttransfer_time\": 1.311189,\n            \"redirect_time\": 0,\n            \"redirect_count\": 0,\n            \"size_upload\": 221,\n            \"size_download\": 61,\n            \"speed_download\": 46,\n            \"speed_upload\": 168,\n            \"download_content_length\": -1,\n            \"upload_content_length\": 221,\n            \"content_type\": \"application\\/json\"\n        },\n        \"verbose_log\": \"*   Trying 66.175.211.30:443...\\n* Connected to sms.arkesel.com (66.175.211.30) port 443\\n* ALPN: curl offers h2,http\\/1.1\\n*  CAfile: C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt\\n*  CApath: none\\n* SSL connection using TLSv1.3 \\/ TLS_AES_256_GCM_SHA384\\n* ALPN: server accepted http\\/1.1\\n* Server certificate:\\n*  subject: CN=sms.arkesel.com\\n*  start date: Jun  4 06:14:43 2025 GMT\\n*  expire date: Sep  2 06:14:42 2025 GMT\\n*  subjectAltName: host \\\"sms.arkesel.com\\\" matched cert\'s \\\"sms.arkesel.com\\\"\\n*  issuer: C=US; O=Let\'s Encrypt; CN=E6\\n*  SSL certificate verify ok.\\n* using HTTP\\/1.1\\n> POST \\/api\\/v2\\/sms\\/send HTTP\\/1.1\\r\\nHost: sms.arkesel.com\\r\\napi-key: cHZtY1B3SW5sZ05iUEJOVmZ1QXA\\r\\nContent-Type: application\\/json\\r\\nAccept: application\\/json\\r\\nContent-Length: 221\\r\\n\\r\\n< HTTP\\/1.1 422 Unprocessable Content\\r\\n< Server: nginx\\r\\n< Content-Type: application\\/json\\r\\n< Transfer-Encoding: chunked\\r\\n< Connection: keep-alive\\r\\n< Cache-Control: no-cache, private\\r\\n< Date: Sun, 20 Jul 2025 16:19:06 GMT\\r\\n< X-RateLimit-Limit: 1500\\r\\n< X-RateLimit-Remaining: 1498\\r\\n< Access-Control-Allow-Origin: *\\r\\n< Set-Cookie: arkesel_sms_messenger_session=VIW5GqmEmS2icbebRrYjbF2AEclCfoqmhjtJ6sG5; expires=Sun, 20-Jul-2025 18:19:06 GMT; Max-Age=7200; path=\\/; secure; httponly; samesite=lax\\r\\n< X-Frame-Options: SAMEORIGIN\\r\\n< \\r\\n* Connection #0 to host sms.arkesel.com left intact\\n\"\n    }\n}');

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
  `transferred_to_member_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sunday_school`
--

INSERT INTO `sunday_school` (`id`, `srn`, `church_id`, `class_id`, `photo`, `last_name`, `middle_name`, `first_name`, `dob`, `contact`, `gps_address`, `residential_address`, `organization`, `school_attend`, `father_name`, `father_contact`, `father_occupation`, `mother_name`, `mother_contact`, `mother_occupation`, `father_member_id`, `father_is_member`, `mother_member_id`, `mother_is_member`, `created_at`, `updated_at`, `transferred_at`, `transferred_to_member_id`) VALUES
(7, 'FMC-S0101-KM', 7, 9, 'ss_687a8e7bdcbcadaniel.jpg', 'Mensah', '', 'Ekow', '2025-07-09', '0545647477', '', '', '', 'METHODIST PRIMARY SCHOOL', 'John Kuma', '0545644748', 'Teacher', 'EUNICE', '0242109740', 'TRADER', 86, 'yes', NULL, 'no', '2025-07-12 10:49:58', '2025-07-18 18:12:11', NULL, NULL),
(8, 'FMC-S0102-KM', 7, 9, 'ss_6878d2df7b6f111passport.jpg', 'duntu', 'ama', 'ROSEZALIN', '2021-06-28', '0242363905', 'ST. JUDE STREET MUSSEY APOWA', 'WS', 'junior choir', 'ST. ANTHONY', '', '', '', '', '', '', NULL, 'yes', NULL, 'no', '2025-07-14 21:21:50', '2025-07-17 10:39:27', NULL, NULL);

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
(34, 91, 7, 'Thomas Sam', 'tomsam@gmail.comm', '0554828662', '$2y$10$ugZhwtYirAYvlVwDw6Aza.MGDmbYT5r29r25.JlQlcm9Zq3KisESK', 'active', '2025-07-18 15:49:02', NULL),
(38, 72, 7, 'Thomas Sam', 'tomsam@gmail.com', '0545644749', '$2y$10$SCgAbrUVq4V/VOJ3Hpjqbu5OgQ.vqQUkbTDate.v2Js0pZrmEMjJ.', 'active', '2025-07-18 16:10:34', NULL);

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
(34, 2),
(38, 5);

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
(17, 7, 'Ekow Mensah', '0545644749', 'ekowme@gmail.com', 'B241, Owusu Kofi Street, Odorkor', 'For a Wedding Engagement and serve yourself. For a Wedding Engagement and serve yourself. For a Wedding Engagement and serve yourself.', 'Male', 'Accra', 'Ahafo', '', 'Single', 'Yes', '2025-07-12', 72, '2025-07-12 13:06:45'),
(19, 7, 'FATIMATU AWUDU', '0545644741', '', 'NEW TOWM- SOUJA MAN JUNCTION', 'dkldjadkljlkjlksdsa', 'Male', 'BUDUBURAM', 'Greater Accra', '', '', 'Yes', '2025-07-12', 72, '2025-07-12 13:33:53'),
(20, 7, 'FIIFI YAWSON', '0242363905', '', 'TAKORADI', 'TO WORSHIP WITH US', 'Male', 'KIKAM', 'Western North', 'TRADER', 'Married', 'Yes', '2025-07-07', 73, '2025-07-14 21:25:05');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_session_member` (`session_id`,`member_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `marked_by` (`marked_by`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `church_id` (`church_id`),
  ADD KEY `idx_is_recurring` (`is_recurring`),
  ADD KEY `idx_recurrence_type` (`recurrence_type`),
  ADD KEY `idx_recurrence_day` (`recurrence_day`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `entity_type` (`entity_type`),
  ADD KEY `entity_id` (`entity_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `bible_classes`
--
ALTER TABLE `bible_classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_group_id` (`class_group_id`),
  ADD KEY `idx_bible_classes_leader_id` (`leader_id`);

--
-- Indexes for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `church_id` (`church_id`),
  ADD KEY `event_type_id` (`event_type_id`);

--
-- Indexes for table `cashier_denomination_entries`
--
ALTER TABLE `cashier_denomination_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cashier_date` (`cashier_id`,`entry_date`);

--
-- Indexes for table `churches`
--
ALTER TABLE `churches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `church_code` (`church_code`);

--
-- Indexes for table `class_groups`
--
ALTER TABLE `class_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `church_id` (`church_id`);

--
-- Indexes for table `deleted_members`
--
ALTER TABLE `deleted_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `crn` (`crn`),
  ADD KEY `church_id` (`church_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_type_id` (`event_type_id`);

--
-- Indexes for table `event_registrations`
--
ALTER TABLE `event_registrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `member_id` (`member_id`);

--
-- Indexes for table `event_types`
--
ALTER TABLE `event_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `health_records`
--
ALTER TABLE `health_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_health_sundayschool_id` (`sundayschool_id`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `crn` (`crn`),
  ADD KEY `church_id` (`church_id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `member_emergency_contacts`
--
ALTER TABLE `member_emergency_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`);

--
-- Indexes for table `member_feedback`
--
ALTER TABLE `member_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`);

--
-- Indexes for table `member_feedback_thread`
--
ALTER TABLE `member_feedback_thread`
  ADD PRIMARY KEY (`id`),
  ADD KEY `feedback_id` (`feedback_id`);

--
-- Indexes for table `member_organizations`
--
ALTER TABLE `member_organizations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `organization_id` (`organization_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `idx_member_organizations_organization_id` (`organization_id`),
  ADD KEY `idx_member_organizations_member_id` (`member_id`);

--
-- Indexes for table `member_roles_of_serving`
--
ALTER TABLE `member_roles_of_serving`
  ADD PRIMARY KEY (`member_id`,`role_id`),
  ADD KEY `fk_member_roles_of_serving_role` (`role_id`);

--
-- Indexes for table `member_transfers`
--
ALTER TABLE `member_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `from_class_id` (`from_class_id`),
  ADD KEY `to_class_id` (`to_class_id`),
  ADD KEY `transferred_by` (`transferred_by`);

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
  ADD PRIMARY KEY (`id`),
  ADD KEY `church_id` (`church_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `token` (`token`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `church_id` (`church_id`),
  ADD KEY `payment_type_id` (`payment_type_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `payments_ibfk_1` (`member_id`),
  ADD KEY `fk_payments_sundayschool` (`sundayschool_id`);

--
-- Indexes for table `payment_reversal_log`
--
ALTER TABLE `payment_reversal_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `actor_id` (`actor_id`);

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
-- Indexes for table `permission_audit_log`
--
ALTER TABLE `permission_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `actor_user_id` (`actor_user_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `permission_templates`
--
ALTER TABLE `permission_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles_of_serving`
--
ALTER TABLE `roles_of_serving`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sms_templates`
--
ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `sunday_school`
--
ALTER TABLE `sunday_school`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `srn` (`srn`),
  ADD KEY `fk_sundayschool_church` (`church_id`),
  ADD KEY `fk_sundayschool_class` (`class_id`),
  ADD KEY `fk_sundayschool_father` (`father_member_id`),
  ADD KEY `fk_sundayschool_mother` (`mother_member_id`);

--
-- Indexes for table `template_permissions`
--
ALTER TABLE `template_permissions`
  ADD PRIMARY KEY (`template_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_users_phone` (`phone`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `church_id` (`church_id`),
  ADD KEY `fk_users_member_id` (`member_id`);

--
-- Indexes for table `user_audit`
--
ALTER TABLE `user_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`user_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `user_permission_requests`
--
ALTER TABLE `user_permission_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `permission_id` (`permission_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_role` (`role_id`);

--
-- Indexes for table `visitors`
--
ALTER TABLE `visitors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `church_id` (`church_id`),
  ADD KEY `fk_visitors_invited_by` (`invited_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=194;

--
-- AUTO_INCREMENT for table `bible_classes`
--
ALTER TABLE `bible_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `class_groups`
--
ALTER TABLE `class_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `deleted_members`
--
ALTER TABLE `deleted_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `event_registrations`
--
ALTER TABLE `event_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `event_types`
--
ALTER TABLE `event_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `health_records`
--
ALTER TABLE `health_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

--
-- AUTO_INCREMENT for table `member_emergency_contacts`
--
ALTER TABLE `member_emergency_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT for table `member_feedback`
--
ALTER TABLE `member_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `member_feedback_thread`
--
ALTER TABLE `member_feedback_thread`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `member_organizations`
--
ALTER TABLE `member_organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `member_transfers`
--
ALTER TABLE `member_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=253;

--
-- AUTO_INCREMENT for table `payment_reversal_log`
--
ALTER TABLE `payment_reversal_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `payment_types`
--
ALTER TABLE `payment_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=317;

--
-- AUTO_INCREMENT for table `permission_audit_log`
--
ALTER TABLE `permission_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1118;

--
-- AUTO_INCREMENT for table `permission_templates`
--
ALTER TABLE `permission_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `roles_of_serving`
--
ALTER TABLE `roles_of_serving`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;

--
-- AUTO_INCREMENT for table `sms_templates`
--
ALTER TABLE `sms_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sunday_school`
--
ALTER TABLE `sunday_school`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `user_audit`
--
ALTER TABLE `user_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_permission_requests`
--
ALTER TABLE `user_permission_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visitors`
--
ALTER TABLE `visitors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`),
  ADD CONSTRAINT `attendance_records_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `attendance_records_ibfk_3` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `attendance_sessions_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`);

--
-- Constraints for table `bible_classes`
--
ALTER TABLE `bible_classes`
  ADD CONSTRAINT `bible_classes_ibfk_1` FOREIGN KEY (`class_group_id`) REFERENCES `class_groups` (`id`),
  ADD CONSTRAINT `fk_leader_id` FOREIGN KEY (`leader_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`),
  ADD CONSTRAINT `calendar_events_ibfk_2` FOREIGN KEY (`event_type_id`) REFERENCES `event_types` (`id`);

--
-- Constraints for table `class_groups`
--
ALTER TABLE `class_groups`
  ADD CONSTRAINT `class_groups_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`);

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`event_type_id`) REFERENCES `event_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `event_registrations`
--
ALTER TABLE `event_registrations`
  ADD CONSTRAINT `event_registrations_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `fk_event_registrations_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `health_records`
--
ALTER TABLE `health_records`
  ADD CONSTRAINT `fk_health_sundayschool` FOREIGN KEY (`sundayschool_id`) REFERENCES `sunday_school` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `health_records_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `health_records_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `members`
--
ALTER TABLE `members`
  ADD CONSTRAINT `members_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`),
  ADD CONSTRAINT `members_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `bible_classes` (`id`);

--
-- Constraints for table `member_emergency_contacts`
--
ALTER TABLE `member_emergency_contacts`
  ADD CONSTRAINT `member_emergency_contacts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `member_feedback`
--
ALTER TABLE `member_feedback`
  ADD CONSTRAINT `member_feedback_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`);

--
-- Constraints for table `member_organizations`
--
ALTER TABLE `member_organizations`
  ADD CONSTRAINT `member_organizations_ibfk_1` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`),
  ADD CONSTRAINT `member_organizations_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`);

--
-- Constraints for table `member_roles_of_serving`
--
ALTER TABLE `member_roles_of_serving`
  ADD CONSTRAINT `fk_member_roles_of_serving_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_member_roles_of_serving_role` FOREIGN KEY (`role_id`) REFERENCES `roles_of_serving` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `member_transfers`
--
ALTER TABLE `member_transfers`
  ADD CONSTRAINT `member_transfers_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `member_transfers_ibfk_2` FOREIGN KEY (`from_class_id`) REFERENCES `bible_classes` (`id`),
  ADD CONSTRAINT `member_transfers_ibfk_3` FOREIGN KEY (`to_class_id`) REFERENCES `bible_classes` (`id`),
  ADD CONSTRAINT `member_transfers_ibfk_4` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `organizations`
--
ALTER TABLE `organizations`
  ADD CONSTRAINT `organizations_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payment_type_id` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payments_sundayschool` FOREIGN KEY (`sundayschool_id`) REFERENCES `sunday_school` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`),
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`id`),
  ADD CONSTRAINT `payments_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `payment_reversal_log`
--
ALTER TABLE `payment_reversal_log`
  ADD CONSTRAINT `payment_reversal_log_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`),
  ADD CONSTRAINT `payment_reversal_log_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `permission_audit_log`
--
ALTER TABLE `permission_audit_log`
  ADD CONSTRAINT `permission_audit_log_ibfk_1` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `permission_audit_log_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`);

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`);

--
-- Constraints for table `sunday_school`
--
ALTER TABLE `sunday_school`
  ADD CONSTRAINT `fk_sundayschool_church` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sundayschool_class` FOREIGN KEY (`class_id`) REFERENCES `bible_classes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sundayschool_father` FOREIGN KEY (`father_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sundayschool_mother` FOREIGN KEY (`mother_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `template_permissions`
--
ALTER TABLE `template_permissions`
  ADD CONSTRAINT `template_permissions_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `permission_templates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `template_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`);

--
-- Constraints for table `user_audit`
--
ALTER TABLE `user_audit`
  ADD CONSTRAINT `user_audit_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_permission_requests`
--
ALTER TABLE `user_permission_requests`
  ADD CONSTRAINT `user_permission_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permission_requests_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permission_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `visitors`
--
ALTER TABLE `visitors`
  ADD CONSTRAINT `fk_visitors_invited_by` FOREIGN KEY (`invited_by`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `visitors_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `churches` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
