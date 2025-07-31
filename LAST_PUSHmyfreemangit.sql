-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 31, 2025 at 04:03 AM
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
  `sync_source` enum('manual','zkteco','hybrid') DEFAULT 'manual',
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
`id` int(11)
,`session_id` int(11)
,`member_id` int(11)
,`status` enum('present','absent')
,`marked_by` int(11)
,`created_at` timestamp
,`sync_source` enum('manual','zkteco','hybrid')
,`verification_type` varchar(20)
,`device_timestamp` datetime
,`device_id` int(11)
,`device_name` varchar(100)
,`device_location` varchar(255)
,`first_name` varchar(100)
,`last_name` varchar(100)
,`crn` varchar(50)
,`session_title` varchar(255)
,`service_date` date
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
(774, 4, 'login_failed', 'user', 4, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-31 00:04:53'),
(775, 3, 'create', 'role', 0, '{\"name\":\"admina\",\"description\":\"admin\"}', NULL, '2025-07-31 00:58:53'),
(776, 3, 'create', 'role', 0, '{\"name\":\"something\",\"description\":\"\"}', NULL, '2025-07-31 01:04:30'),
(777, 4, 'login_failed', 'user', 4, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-31 01:05:40'),
(778, 3, 'create', 'role', 9, '{\"name\":\"Something\",\"description\":\"\"}', NULL, '2025-07-31 01:12:27'),
(779, 3, 'delete', 'role', 9, '', NULL, '2025-07-31 01:29:55'),
(780, 4, 'login_success', 'user', 4, '{\"username\":\"tomsam@gmail.com\",\"ip\":\"::1\"}', '::1', '2025-07-31 01:31:44'),
(781, 4, 'logout', 'user', 4, '{\"ip\":\"::1\",\"time\":\"2025-07-14T16:56:25Z\"}', '::1', '2025-07-31 01:46:43');

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
(7, NULL, 'REV. THOMAS BIRCH FREEMAN 01', 'F01', NULL, 7),
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
(22, NULL, 'Test', '01', NULL, 7),
(23, NULL, 'duntu 05', 'd05', NULL, 7),
(24, NULL, 'sunday school', 's02', NULL, 7),
(25, NULL, 'abedu 01', 'a09', NULL, 7),
(26, NULL, 'abedu', '02', NULL, 7);

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

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `name`, `event_type_id`, `event_date`, `event_time`, `location`, `description`, `photo`, `gallery`) VALUES
(1, 'Yearly Harvest', 3, '2025-07-31', '20:09:00', 'Freeman Methodist Church', 'Tell a friend to tell a friend.  Test Test', 'event_6884006bf10cd.png', '[]'),
(2, 'MINI HARVEST', 3, '2025-07-31', '06:20:00', 'CHAPEL', '', '', '[]');

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
(72, 'Ekow', 'Paa', 'Mensah', 7, 21, 'FMC-K0602-KM', NULL, '2010-07-29', 'kjhkhk', 'Wednesday', 'Male', '0545644749', '', 'ekowme@gmail.com', 'b241, owusu kofi str', 'dddkdkkd', 'Married', 'jkhkjhk', 'Central', 'member_6877d58922a24.jpg', 'active', '', '$2y$10$AMCxLerRJPccwALQOLrS1OKdpoiLw3mSuEvqBTh5SZuFxi3XHP0LK', '2025-07-10 14:30:29', NULL, 'Formal', 'teacher', 'Yes', 'Yes', '2025-07-11', '2025-07-11', '', '0000-00-00', 0);

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
(65, 69, 'dafsdfsd', '45343534', 'fadsfadsf'),
(72, 70, 'tito', '3838383', 'ddldkfld'),
(73, 71, 'tito', '4848484848', 'broooo'),
(77, 73, 'FATIMATU AWUDU', '0554828663', 'sis'),
(82, 83, 'GLADYS KANKAM', '0544842820', 'sister'),
(83, 80, 'GLADYS KANKAM', '0544842820', 'sister'),
(86, 86, 'FIIFI YAWSON', '0242363905', 'BRO'),
(87, 85, 'NAAYAW', '01254789633', 'FATHER'),
(88, 84, 'ABA MENS', '02587899663', 'MOTHER'),
(92, 89, 'ghjgj', '7675765756', 'jhgjhgjh'),
(95, 81, 'Nana Nkeyah', '0277384201', 'Son'),
(96, 90, 'KWEKU NANA', '0275115850', 'SISTER'),
(97, 90, 'YAWSON MERCY ABA', '0557295848', 'BROTHER'),
(98, 88, 'Tito Nash', '0545644749', 'Brother'),
(99, 94, 'tabitha', '0547257213', 'wife'),
(100, 95, 'Barnabas ', '0243456574', 'Brother '),
(101, 93, 'BARNABAS QUAYSON-OTOO', '0242363905', 'BRO'),
(102, 97, 'James Evens', '7162381865', 'bro'),
(0, 96, 'BARNABAS QUAYSON-OTOO', '0242363905', 'Father'),
(0, 92, 'tito', '05456474484', 'brother'),
(0, 82, 'JACOB F AYIEI', '0551756789', 'brother'),
(0, 87, 'FIIFI YAWSON', '0242363905', 'BRO'),
(0, 72, 'kjkhkh', '79798977', 'bkkjk');

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
(85, 4, 86, NULL, NULL),
(86, 4, 85, NULL, NULL),
(87, 6, 85, NULL, NULL),
(88, 2, 84, NULL, NULL),
(100, 4, 81, NULL, NULL),
(101, 5, 81, NULL, NULL),
(102, 6, 81, NULL, NULL),
(103, 2, 90, NULL, NULL),
(104, 7, 90, NULL, NULL),
(105, 4, 90, NULL, NULL),
(106, 2, 88, NULL, NULL),
(107, 1, 88, NULL, NULL),
(108, 8, 93, NULL, NULL),
(109, 8, 97, NULL, NULL),
(0, 1, 96, NULL, NULL),
(0, 4, 96, NULL, NULL),
(0, 8, 92, NULL, NULL),
(0, 3, 92, NULL, NULL),
(0, 4, 82, NULL, NULL),
(0, 6, 82, NULL, NULL),
(0, 7, 87, NULL, NULL),
(0, 4, 87, NULL, NULL),
(0, 3, 87, NULL, NULL),
(0, 2, 72, NULL, NULL),
(0, 7, 97, NULL, NULL);

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
(80, 6),
(80, 8),
(81, 2),
(81, 3),
(81, 7),
(81, 9),
(83, 2),
(83, 6),
(83, 7),
(84, 3),
(85, 9),
(86, 2),
(86, 6),
(88, 2),
(88, 9),
(90, 6),
(90, 7),
(90, 8),
(97, 2),
(97, 7),
(97, 9),
(92, 2),
(82, 2),
(82, 6),
(82, 5),
(87, 7),
(87, 2),
(87, 8),
(72, 1);

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
(81, '', 'Event Types', '', 'views/eventtype_list.php', 'Events', 5, 1);

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
(1, 7, 'Girls Guild', 'Girls Guild Group', NULL),
(2, 7, 'choir', '', NULL),
(3, 7, 'SUWMA', '', NULL),
(4, 7, 'MYF', 'YOUTH', 44),
(5, 7, 'singing band', 'SINGING GROUP', NULL),
(6, 7, 'SUWMA', '', NULL),
(7, 7, 'GIRLS FF', '', NULL),
(8, 7, 'ddkdsk', 'dksdlkd', NULL);

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
  `payment_date` datetime DEFAULT NULL,
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
(419, 72, NULL, 7, 4, 10.00, '2025-07-31 02:12:37', 3, 'Cash', 'harvest for July', NULL, NULL, NULL, NULL, NULL, NULL);

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
(6, 'education fund', '', 1),
(7, 'Sample Payment', '', 1);

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
(316, 'pending_members_list', NULL, NULL),
(317, 'view_organization_membership_approvals', NULL, 'View pending organization membership approval requests'),
(318, 'approve_organization_memberships', NULL, 'Approve organization membership requests from members'),
(319, 'reject_organization_memberships', NULL, 'Reject organization membership requests from members');

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
(0, 4, 'deny', 'user', 4, 107, '2025-07-31 01:35:24', 'denied');

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
(2, 'Admin', NULL),
(3, 'Steward', NULL),
(4, 'Rev. Ministers', NULL),
(5, 'Class Leader', NULL),
(6, 'Organizational Leader', NULL),
(7, 'Cashier', NULL),
(8, 'Health', NULL);

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
(1, 317),
(1, 318),
(1, 319),
(5, 77),
(5, 78),
(5, 79),
(5, 80),
(5, 81),
(5, 89),
(5, 90),
(5, 91),
(5, 92),
(5, 93),
(5, 94),
(5, 95),
(5, 96),
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
(5, 193),
(5, 197),
(5, 203),
(5, 218),
(5, 219),
(5, 220),
(5, 222),
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
(5, 297),
(5, 298),
(5, 310),
(5, 316),
(11, 77),
(11, 78),
(11, 79),
(11, 80),
(11, 81),
(11, 82),
(11, 83),
(11, 84),
(11, 85),
(11, 86),
(11, 87),
(11, 88),
(11, 89),
(11, 90),
(11, 91),
(11, 92),
(11, 93),
(11, 94),
(11, 95),
(11, 96),
(11, 97),
(11, 98),
(11, 99),
(11, 100),
(11, 101),
(11, 102),
(11, 103),
(11, 104),
(11, 105),
(11, 106),
(11, 107),
(11, 108),
(11, 109),
(11, 110),
(11, 111),
(11, 112),
(11, 113),
(11, 114),
(11, 115),
(11, 116),
(11, 117),
(11, 118),
(11, 119),
(11, 120),
(11, 121),
(11, 122),
(11, 123),
(11, 124),
(11, 125),
(11, 126),
(11, 127),
(11, 128),
(11, 129),
(11, 130),
(11, 131),
(11, 132),
(11, 133),
(11, 134),
(11, 135),
(11, 136),
(11, 137),
(11, 138),
(11, 139),
(11, 140),
(11, 141),
(11, 142),
(11, 143),
(11, 144),
(11, 145),
(11, 146),
(11, 147),
(11, 148),
(11, 149),
(11, 150),
(11, 151),
(11, 152),
(11, 153),
(11, 154),
(11, 155),
(11, 156),
(11, 157),
(11, 158),
(11, 159),
(11, 160),
(11, 161),
(11, 162),
(11, 163),
(11, 164),
(11, 165),
(11, 166),
(11, 167),
(11, 168),
(11, 169),
(11, 170),
(11, 171),
(11, 172),
(11, 173),
(11, 174),
(11, 175),
(11, 176),
(11, 177),
(11, 178),
(11, 179),
(11, 180),
(11, 181),
(11, 182),
(11, 183),
(11, 184),
(11, 185),
(11, 186),
(11, 187),
(11, 188),
(11, 189),
(11, 190),
(11, 191),
(11, 192),
(11, 193),
(11, 194),
(11, 195),
(11, 196),
(11, 197),
(11, 198),
(11, 199),
(11, 200),
(11, 201),
(11, 202),
(11, 203),
(11, 204),
(11, 205),
(11, 206),
(11, 207),
(11, 208),
(11, 209),
(11, 210),
(11, 211),
(11, 212),
(11, 213),
(11, 214),
(11, 215),
(11, 216),
(11, 217),
(11, 218),
(11, 219),
(11, 220),
(11, 221),
(11, 222),
(11, 223),
(11, 224),
(11, 225),
(11, 226),
(11, 227),
(11, 228),
(11, 229),
(11, 230),
(11, 231),
(11, 232),
(11, 233),
(11, 234),
(11, 235),
(11, 236),
(11, 237),
(11, 238),
(11, 239),
(11, 240),
(11, 241),
(11, 242),
(11, 243),
(11, 244),
(11, 245),
(11, 246),
(11, 247),
(11, 248),
(11, 249),
(11, 250),
(11, 251),
(11, 252),
(11, 253),
(11, 254),
(11, 255),
(11, 256),
(11, 257),
(11, 258),
(11, 259),
(11, 260),
(11, 261),
(11, 262),
(11, 263),
(11, 264),
(11, 265),
(11, 266),
(11, 267),
(11, 268),
(11, 269),
(11, 270),
(11, 271),
(11, 272),
(11, 273),
(11, 274),
(11, 275),
(11, 276),
(11, 277),
(11, 278),
(11, 279),
(11, 280),
(11, 281),
(11, 282),
(11, 283),
(11, 284),
(11, 285),
(11, 286),
(11, 287),
(11, 288),
(11, 289),
(11, 290),
(11, 291),
(11, 292),
(11, 293),
(11, 294),
(11, 295),
(11, 296),
(11, 297),
(11, 299),
(11, 300),
(11, 301),
(11, 302),
(11, 303),
(11, 304),
(11, 305),
(11, 306),
(11, 307),
(11, 308),
(11, 309),
(11, 310),
(11, 311),
(11, 312),
(11, 313),
(11, 314),
(11, 315),
(11, 316),
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
(2, 109),
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
(2, 310),
(6, 77),
(6, 115),
(6, 109),
(6, 122),
(6, 298),
(6, 118),
(6, 117),
(6, 119),
(6, 108),
(6, 107),
(6, 114),
(6, 121),
(6, 120),
(6, 180),
(6, 175),
(6, 318),
(6, 319),
(6, 317),
(10, 77),
(10, 85),
(10, 97),
(10, 79),
(10, 86),
(10, 81),
(10, 80),
(10, 92),
(10, 90),
(10, 82),
(10, 83),
(10, 87),
(10, 96),
(10, 88),
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
(10, 110),
(10, 112),
(10, 111),
(10, 115),
(10, 116),
(10, 109),
(10, 122),
(10, 113),
(10, 118),
(10, 124),
(10, 117),
(10, 119),
(10, 108),
(10, 107),
(10, 114),
(10, 121),
(10, 120),
(10, 123),
(10, 136),
(10, 135),
(10, 134),
(10, 138),
(10, 139),
(10, 140),
(10, 141),
(10, 126),
(10, 142),
(10, 143),
(10, 144),
(10, 145),
(10, 146),
(10, 147),
(10, 148),
(10, 127),
(10, 128),
(10, 149),
(10, 129),
(10, 150),
(10, 151),
(10, 152),
(10, 153),
(10, 132),
(10, 154),
(10, 156),
(10, 157),
(10, 155),
(10, 158),
(10, 133),
(10, 159),
(10, 160),
(10, 125),
(10, 161),
(10, 130),
(10, 131),
(10, 162),
(10, 167),
(10, 164),
(10, 166),
(10, 165),
(10, 170),
(10, 168),
(10, 169),
(10, 163),
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
(10, 200),
(10, 199),
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
(10, 234),
(10, 236),
(10, 235),
(10, 233),
(10, 246),
(10, 249),
(10, 241),
(10, 243),
(10, 238),
(10, 245),
(10, 240),
(10, 244),
(10, 239),
(10, 248),
(10, 252),
(10, 247),
(10, 251),
(10, 250),
(10, 242),
(10, 237),
(10, 255),
(10, 257),
(10, 256),
(10, 258),
(10, 253),
(10, 254),
(10, 263),
(10, 267),
(10, 268),
(10, 260),
(10, 264),
(10, 262),
(10, 271),
(10, 261),
(10, 266),
(10, 269),
(10, 265),
(10, 270),
(10, 259),
(10, 301),
(10, 297),
(10, 299),
(10, 310),
(10, 311),
(10, 314),
(10, 313),
(10, 312),
(10, 309),
(10, 308),
(10, 315),
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
(4, 101),
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
(4, 133),
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
(7, 77),
(7, 78),
(7, 94),
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
(7, 298),
(7, 118),
(7, 117),
(7, 119),
(7, 108),
(7, 107),
(7, 114),
(7, 121),
(7, 120),
(7, 167),
(7, 164),
(7, 166),
(7, 165),
(7, 170),
(7, 168),
(7, 169),
(7, 163),
(7, 214),
(7, 310),
(8, 77),
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
(8, 310);

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
(0, NULL, '0545644749', 'Hi Ekow Mensah, your payment of ₵10.00 has been paid to Freeman Methodist Church - KM as harvest for July. Your Total Harvest amount for the year 2025 is ₵10.00', NULL, 'harvest_payment', 'success', 'arkesel', '2025-07-31 00:12:38', '{\n    \"data\": [\n        {\n            \"id\": \"802390c9-61bf-4c6f-bf0a-2a2c47624566\",\n            \"recipient\": \"233545644749\"\n        }\n    ],\n    \"status\": \"success\"\n}');

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
(7, 'FMC-S0101-KM', 7, 9, 'ss_687a8e7bdcbcadaniel.jpg', 'Mensah', '', 'Ekow', '2025-07-09', '0545647477', '', '', '', 'METHODIST PRIMARY SCHOOL', 'John Kuma', '0545644748', 'Teacher', 'EUNICE', '0242109740', 'TRADER', 86, 'yes', NULL, 'no', '2025-07-12 10:49:58', '2025-07-18 18:12:11', NULL, NULL, NULL, NULL),
(8, 'FMC-S0102-KM', 7, 9, 'ss_6878d2df7b6f111passport.jpg', 'Duntu', '', 'ROSEZALIN', '2021-06-28', '0242363905', 'ST. JUDE STREET MUSSEY APOWA', 'WS', 'junior choir', 'ST. ANTHONY', '', '', '', '', '', '', NULL, 'yes', NULL, 'no', '2025-07-14 21:21:50', '2025-07-31 02:02:01', NULL, NULL, NULL, NULL);

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
(4, NULL, 0, 'Thomas Sam', 'tomsam@gmail.com', '1234567890', '$2y$10$gLFYKB90XkrYEFxdwBuKiOtR8uZ1z7fjqjUamW.kMZA8p07bYQM36', 'active', '2025-07-30 23:02:45', NULL);

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
(4, 8);

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

-- --------------------------------------------------------

--
-- Table structure for table `zkteco_devices`
--

CREATE TABLE `zkteco_devices` (
  `id` int(11) NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `ip_address` varchar(15) NOT NULL,
  `port` int(11) DEFAULT 4370,
  `location` varchar(255) DEFAULT NULL,
  `church_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_sync` timestamp NULL DEFAULT NULL,
  `device_model` varchar(50) DEFAULT 'MB460',
  `firmware_version` varchar(50) DEFAULT NULL,
  `total_users` int(11) DEFAULT 0,
  `total_records` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores ZKTeco biometric device configuration and status information';

-- --------------------------------------------------------

--
-- Table structure for table `zkteco_raw_logs`
--

CREATE TABLE `zkteco_raw_logs` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `zk_user_id` varchar(50) NOT NULL,
  `timestamp` datetime NOT NULL,
  `verification_type` enum('fingerprint','face','card','password','unknown') DEFAULT 'unknown',
  `in_out_mode` enum('check_in','check_out','break_out','break_in','overtime_in','overtime_out','unknown') DEFAULT 'unknown',
  `raw_data` text DEFAULT NULL,
  `processed` tinyint(1) DEFAULT 0,
  `processed_at` timestamp NULL DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores raw attendance logs retrieved from ZKTeco devices before processing';

-- --------------------------------------------------------

--
-- Table structure for table `zkteco_session_mapping_rules`
--

CREATE TABLE `zkteco_session_mapping_rules` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `session_pattern` varchar(255) NOT NULL,
  `time_window_before` int(11) DEFAULT 120,
  `time_window_after` int(11) DEFAULT 120,
  `auto_create_session` tinyint(1) DEFAULT 0,
  `default_session_title` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Rules for automatically mapping ZKTeco attendance data to attendance sessions';

-- --------------------------------------------------------

--
-- Table structure for table `zkteco_sync_history`
--

CREATE TABLE `zkteco_sync_history` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `sync_type` enum('manual','automatic','scheduled') DEFAULT 'manual',
  `sync_status` enum('success','partial','failed') DEFAULT 'failed',
  `records_synced` int(11) DEFAULT 0,
  `records_processed` int(11) DEFAULT 0,
  `sync_start` timestamp NOT NULL DEFAULT current_timestamp(),
  `sync_end` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `sync_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sync_details`)),
  `initiated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tracks synchronization history and status for ZKTeco devices';

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
  ADD KEY `idx_zkteco_raw_log` (`zkteco_raw_log_id`);

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
  ADD KEY `idx_member_type_amount_date` (`member_id`,`payment_type_id`,`amount`,`payment_date`);

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
-- Indexes for table `zkteco_devices`
--
ALTER TABLE `zkteco_devices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_ip` (`ip_address`),
  ADD KEY `idx_device_church` (`church_id`),
  ADD KEY `idx_device_active` (`is_active`);

--
-- Indexes for table `zkteco_raw_logs`
--
ALTER TABLE `zkteco_raw_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `zkteco_session_mapping_rules`
--
ALTER TABLE `zkteco_session_mapping_rules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `zkteco_sync_history`
--
ALTER TABLE `zkteco_sync_history`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adherents`
--
ALTER TABLE `adherents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=782;

--
-- AUTO_INCREMENT for table `bible_classes`
--
ALTER TABLE `bible_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `deleted_members`
--
ALTER TABLE `deleted_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

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
-- AUTO_INCREMENT for table `member_transfers`
--
ALTER TABLE `member_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `organization_membership_approvals`
--
ALTER TABLE `organization_membership_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=420;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `zkteco_devices`
--
ALTER TABLE `zkteco_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `zkteco_session_mapping_rules`
--
ALTER TABLE `zkteco_session_mapping_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `zkteco_sync_history`
--
ALTER TABLE `zkteco_sync_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
