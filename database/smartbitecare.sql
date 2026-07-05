-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 05, 2026 at 04:59 PM
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
-- Database: `smartbitecare`
--

-- --------------------------------------------------------

--
-- Table structure for table `animal_bite_cases`
--

CREATE TABLE `animal_bite_cases` (
  `case_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `animal_type` varchar(100) DEFAULT NULL,
  `bite_location` varchar(255) DEFAULT NULL,
  `bite_category` varchar(50) DEFAULT NULL,
  `animal_status` varchar(100) DEFAULT NULL,
  `date_of_bite` date DEFAULT NULL,
  `case_status` enum('Ongoing','Completed') DEFAULT 'Ongoing',
  `remarks` text DEFAULT NULL,
  `admin_staff_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `action` text DEFAULT NULL,
  `module` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `branch_id`, `action`, `module`, `created_at`) VALUES
(19, 6, 'SBI-002', 'Added new user: Pamela One (ID: 7)', 'User Management', '2026-07-04 14:36:23'),
(20, 6, 'SBI-002', 'Added new user: Pamela One (ID: 8)', 'User Management', '2026-07-04 14:41:02'),
(21, 6, 'SBI-002', 'Updated user: Shane Cacho (ID: 8)', 'User Management', '2026-07-04 14:54:14'),
(22, 6, 'SBI-002', 'Added new user: Marc Beringuela (ID: 9)', 'User Management', '2026-07-04 14:55:07'),
(23, 6, 'SBI-002', 'Added new user: Jean Montero (ID: 11)', 'User Management', '2026-07-04 15:09:39'),
(24, 1, 'SBI-001', 'Added new branch: Montalban Branch (ID: SBI-004)', 'Branch Management', '2026-07-05 11:39:58'),
(25, 1, 'SBI-001', 'Updated branch: SBI-004 (Montalban Branch) - Changes: Contact: \'091234567\' → \'0912345679\'', 'Branch Management', '2026-07-05 11:40:25'),
(26, 1, 'SBI-001', 'Created new branch admin: Joepat Lacerna (ID: 12) for branch: Pasig Branch (ID: SBI-003)', 'Branch Admin Management', '2026-07-05 11:44:12'),
(27, 1, 'SBI-001', 'Sent welcome email to new branch admin: Joepat Lacerna (ID: 12)', 'Branch Admin Management', '2026-07-05 11:44:17'),
(28, 1, 'SBI-001', 'Sent email to branch admin: Jojana Garabillo (ID: 6, Email: jojanajeangarabillo@gmail.com) - Subject: test email', 'Branch Admin Management', '2026-07-05 11:44:50'),
(29, 1, 'SBI-001', 'Created new branch admin: Joepat Lacerna (ID: 13) for branch ID: SBI-003', 'Branch Admin Management', '2026-07-05 11:48:50'),
(30, 1, 'SBI-001', 'Welcome email sent to new branch admin: Joepat Lacerna (ID: 13, Email: opat09252005@gmail.com)', 'Branch Admin Management', '2026-07-05 11:48:54'),
(31, 1, 'SBI-001', 'Created new branch admin: Joepat Lacerna (ID: 14) for branch ID: SBI-003', 'Branch Admin Management', '2026-07-05 11:56:22'),
(32, 1, 'SBI-001', 'Welcome email sent to new branch admin: Joepat Lacerna (ID: 14, Email: opat09252005@gmail.com)', 'Branch Admin Management', '2026-07-05 11:56:26'),
(33, 14, 'SBI-003', 'Login Success: User \'Joepat Lacerna\' - Role: Branch Admin, Branch: Pasig Branch (IP: ::1)', NULL, '2026-07-05 12:04:06'),
(34, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: 127.0.0.1)', NULL, '2026-07-05 12:04:48'),
(35, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-05 12:07:13'),
(36, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-05 12:11:05'),
(37, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: 127.0.0.1)', 'Login System', '2026-07-05 12:14:37'),
(38, 1, 'SBI-001', 'Logout: User \'superadmin\' (Role: Super Admin) (IP: 127.0.0.1)', 'Login System', '2026-07-05 12:14:45'),
(39, 9, 'SBI-002', 'Login Failed: User \'Marc Beringuela\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-05 12:16:32'),
(40, 9, 'SBI-002', 'Login Failed: User \'Marc Beringuela\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-05 12:16:49'),
(41, 9, 'SBI-002', 'Login Success: User \'Marc Beringuela\' - Role: Nurse, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-05 12:17:05'),
(42, 9, 'SBI-002', 'Logout: User \'Marc Beringuela\' (Role: Nurse) (IP: ::1)', 'Login System', '2026-07-05 12:18:47'),
(43, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: 127.0.0.1)', 'Login System', '2026-07-05 12:19:15'),
(44, 1, 'SBI-001', 'Generated user report - Branch: All Branches, Date Range: 2026-06-05 to 2026-07-05', 'Reports', '2026-07-05 12:21:48'),
(45, 1, 'SBI-001', 'Viewed Audit Logs page with filters - Module: All, User: All, Search: None', 'Audit Logs', '2026-07-05 12:24:21'),
(46, 1, 'SBI-001', 'Viewed Audit Logs page with filters - Module: All, User: All, Search: None', 'Audit Logs', '2026-07-05 12:24:40'),
(47, 1, 'SBI-001', 'Viewed Audit Logs page with filters - Module: All, User: All, Search: None', 'Audit Logs', '2026-07-05 12:24:44'),
(48, 1, 'SBI-001', 'Viewed Audit Logs page with filters - Module: Audit Logs, User: All, Search: None', 'Audit Logs', '2026-07-05 12:25:01'),
(49, 1, 'SBI-001', 'Viewed Audit Logs page with filters - Module: Branch Admin Management, User: All, Search: None', 'Audit Logs', '2026-07-05 12:25:03'),
(50, 1, 'SBI-001', 'Viewed Audit Logs page with filters - Module: User Management, User: All, Search: None', 'Audit Logs', '2026-07-05 12:25:10'),
(51, 1, 'SBI-001', 'Viewed Audit Logs page with filters - Module: User Management, User: All, Search: None', 'Audit Logs', '2026-07-05 12:25:58'),
(52, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 12:30:00'),
(53, 1, 'SBI-001', 'Viewed Audit Logs page - Module: Audit Logs, User: All, Action: All', 'Audit Logs', '2026-07-05 12:30:25'),
(54, 1, 'SBI-001', 'Viewed Audit Logs page - Module: Audit Logs, User: All, Action: All', 'Audit Logs', '2026-07-05 12:30:34'),
(55, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 12:30:41'),
(56, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 14, Action: All', 'Audit Logs', '2026-07-05 12:30:47'),
(57, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 6, Action: All', 'Audit Logs', '2026-07-05 12:30:53'),
(58, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 6, Action: All', 'Audit Logs', '2026-07-05 12:30:54'),
(59, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 6, Action: Update', 'Audit Logs', '2026-07-05 12:31:03'),
(60, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 6, Action: Delete', 'Audit Logs', '2026-07-05 12:31:05'),
(61, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 6, Action: Archive', 'Audit Logs', '2026-07-05 12:31:07'),
(62, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 6, Action: Logout', 'Audit Logs', '2026-07-05 12:31:09'),
(63, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: 6, Action: Logout', 'Audit Logs', '2026-07-05 12:31:17'),
(64, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 12:31:18'),
(65, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 12:31:26'),
(66, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 12:31:29'),
(67, 1, 'SBI-001', 'Viewed Audit Logs page - Module: Login System, User: All, Action: All', 'Audit Logs', '2026-07-05 12:32:07'),
(68, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 12:32:45'),
(69, 1, 'SBI-001', 'Logout: User \'superadmin\' (Role: Super Admin) (IP: 127.0.0.1)', 'Login System', '2026-07-05 12:35:00'),
(70, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: 127.0.0.1)', 'Login System', '2026-07-05 13:08:09'),
(71, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 13:08:14'),
(72, 11, 'SBI-002', 'Login Success: User \'Jean Montero\' - Role: Inventory Officer, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-05 13:11:36'),
(73, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 13:11:41'),
(74, 1, 'SBI-001', 'Viewed Super Admin Dashboard', 'Dashboard', '2026-07-05 13:37:46'),
(75, 1, 'SBI-001', 'Viewed Super Admin Dashboard', 'Dashboard', '2026-07-05 13:38:14'),
(76, 1, 'SBI-001', 'Viewed Super Admin Dashboard', 'Dashboard', '2026-07-05 13:38:25'),
(77, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 13:38:36'),
(78, 1, 'SBI-001', 'Viewed Super Admin Dashboard', 'Dashboard', '2026-07-05 13:38:42'),
(79, 1, 'SBI-001', 'Logout: User \'superadmin\' (Role: Super Admin) (IP: 127.0.0.1)', 'Login System', '2026-07-05 13:38:57'),
(80, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: 127.0.0.1)', 'Login System', '2026-07-05 13:39:17'),
(81, 1, 'SBI-001', 'Viewed Super Admin Dashboard', 'Dashboard', '2026-07-05 13:39:17'),
(82, 11, 'SBI-002', 'Logout: User \'Jean Montero\' (Role: Inventory Officer) (IP: ::1)', 'Login System', '2026-07-05 13:39:41'),
(83, 1, 'SBI-001', 'Viewed Super Admin Dashboard', 'Dashboard', '2026-07-05 13:39:46'),
(84, 11, 'SBI-002', 'Login Success: User \'Jean Montero\' - Role: Inventory Officer, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-05 13:40:39'),
(85, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 13:50:56'),
(86, 1, 'SBI-001', 'Viewed Super Admin Dashboard', 'Dashboard', '2026-07-05 13:50:59'),
(87, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 13:52:46'),
(88, 11, 'SBI-002', 'Added new inventory category: Appliances/Electronics (ID: 1) with frequency: Monthly', 'Inventory Categories', '2026-07-05 14:01:09'),
(89, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:01:16'),
(90, 9, 'SBI-002', 'Login Success: User \'Marc Beringuela\' - Role: Nurse, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-05 14:02:08'),
(91, 11, 'SBI-002', 'Added new inventory category: Medical Supplies (ID: 2) with frequency: Daily', 'Inventory Categories', '2026-07-05 14:02:56'),
(92, 11, 'SBI-002', 'Added new inventory category: Logbook/Forms (ID: 3) with frequency: Weekly', 'Inventory Categories', '2026-07-05 14:03:21'),
(93, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:08:33'),
(94, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:24:34'),
(95, 11, 'SBI-002', 'Added new unit: Ream (ID: 1)', 'Inventory Categories', '2026-07-05 14:30:05'),
(96, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:31:10'),
(97, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:31:18'),
(98, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:31:21'),
(99, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:31:25'),
(100, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:31:32'),
(101, 11, 'SBI-002', 'Added new unit: Packs (ID: 2)', 'Inventory Categories', '2026-07-05 14:32:14'),
(102, 11, 'SBI-002', 'Added new unit: Box/s (ID: 3)', 'Inventory Categories', '2026-07-05 14:32:40'),
(103, 9, 'SBI-002', 'Logout: User \'Marc Beringuela\' (Role: Nurse) (IP: ::1)', 'Login System', '2026-07-05 14:56:45'),
(104, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:56:49'),
(105, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:56:56'),
(106, 1, 'SBI-001', 'Viewed Audit Logs page - Module: All, User: All, Action: All', 'Audit Logs', '2026-07-05 14:57:03'),
(107, 11, 'SBI-002', 'Logout: User \'Jean Montero\' (Role: Inventory Officer) (IP: ::1)', 'Login System', '2026-07-05 14:57:47');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `branch_id` varchar(10) NOT NULL,
  `branch_name` varchar(150) NOT NULL,
  `branch_address` text DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`branch_id`, `branch_name`, `branch_address`, `contact_number`, `email`, `status`, `created_at`) VALUES
('SBI-001', 'Antipolo Branch', 'Antipolo City, Rizal', '09123456789', 'antipolo@smartbitecare.com', 'Active', '2026-07-04 07:53:33'),
('SBI-002', 'Cainta Branch', 'Cainta, Rizal', '091234578', 'sbicainta@gmail.com', 'Active', '2026-07-04 08:30:29'),
('SBI-003', 'Pasig Branch', 'Pasig City', '091234578', 'sbipasig@gmail.com', 'Active', '2026-07-04 08:36:45'),
('SBI-004', 'Montalban Branch', 'Montalban, Rizal', '0912345679', 'sbimontalban@gmail.com', 'Active', '2026-07-05 11:39:58');

-- --------------------------------------------------------

--
-- Table structure for table `document_tracking`
--

CREATE TABLE `document_tracking` (
  `document_id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `document_type` enum('Medical Certificate','Referral Letter') DEFAULT NULL,
  `status` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_categories`
--

CREATE TABLE `inventory_categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(150) NOT NULL,
  `monitoring_frequency` enum('Daily','Weekly','Monthly') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_categories`
--

INSERT INTO `inventory_categories` (`category_id`, `category_name`, `monitoring_frequency`) VALUES
(1, 'Appliances/Electronics', 'Monthly'),
(2, 'Medical Supplies', 'Daily'),
(3, 'Logbook/Forms', 'Weekly');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_items`
--

CREATE TABLE `inventory_items` (
  `item_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `minimum_stock` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `is_predictable` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_stocks`
--

CREATE TABLE `inventory_stocks` (
  `stock_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `quantity_available` int(11) NOT NULL DEFAULT 0,
  `expiration_date` date DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_usage_history`
--

CREATE TABLE `inventory_usage_history` (
  `usage_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `usage_date` date NOT NULL,
  `quantity_used` int(11) NOT NULL,
  `patient_count` int(11) NOT NULL,
  `stock_received` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `notification_type` varchar(100) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_minor` tinyint(1) DEFAULT 0,
  `guardian_name` varchar(255) DEFAULT NULL,
  `guardian_relationship` varchar(100) DEFAULT NULL,
  `guardian_contact_number` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `branch_id` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `philhealth_records`
--

CREATE TABLE `philhealth_records` (
  `philhealth_record_id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `status` enum('For Writing','For Screening','For Signing','For Transmittal','Completed') DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prediction_results`
--

CREATE TABLE `prediction_results` (
  `prediction_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `prediction_date` date NOT NULL,
  `probability_score` decimal(5,2) DEFAULT NULL,
  `prediction_status` varchar(100) DEFAULT NULL,
  `recommended_reorder` int(11) DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `predicted_consumption` int(11) DEFAULT NULL,
  `forecast_days` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registry_records`
--

CREATE TABLE `registry_records` (
  `registry_id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `registry_number` varchar(100) DEFAULT NULL,
  `status_of_biting_animal` varchar(100) DEFAULT NULL,
  `erig` tinyint(1) DEFAULT 0,
  `ats` tinyint(1) DEFAULT 0,
  `tt` tinyint(1) DEFAULT 0,
  `active_regimen` varchar(100) DEFAULT NULL,
  `dose_d0` tinyint(1) DEFAULT 0,
  `dose_d3` tinyint(1) DEFAULT 0,
  `dose_d7` tinyint(1) DEFAULT 0,
  `dose_d14` tinyint(1) DEFAULT 0,
  `dose_d28_30` tinyint(1) DEFAULT 0,
  `booster` tinyint(1) DEFAULT 0,
  `contact_number` varchar(30) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(1, 'Super Admin'),
(2, 'Branch Admin'),
(3, 'Nurse'),
(4, 'Administrative Staff'),
(5, 'Inventory Officer');

-- --------------------------------------------------------

--
-- Table structure for table `stock_transactions`
--

CREATE TABLE `stock_transactions` (
  `transaction_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vaccination_id` int(11) DEFAULT NULL,
  `branch_id` varchar(10) NOT NULL,
  `transaction_type` enum('IN','OUT','ADJUSTMENT') DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `training_dataset`
--

CREATE TABLE `training_dataset` (
  `training_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `record_date` date NOT NULL,
  `current_stock` int(11) NOT NULL,
  `quantity_used` int(11) NOT NULL,
  `stock_received` int(11) DEFAULT 0,
  `patient_count` int(11) DEFAULT 0,
  `low_stock_target` tinyint(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `unit_id` int(11) NOT NULL,
  `unit_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`unit_id`, `unit_name`) VALUES
(1, 'Ream'),
(2, 'Packs'),
(3, 'Box/s');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `branch_id` varchar(10) DEFAULT NULL,
  `role_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `branch_id`, `role_id`, `username`, `email`, `password`, `status`, `created_at`, `last_login`) VALUES
(1, 'SBI-001', 1, 'superadmin', 'garabillo_jojanajean@plpasig.edu.ph', '$2y$10$gTUuk2.GeUd5BNNryWGq8OhJWjQcdqk8rOMPUMJ1VkFaxc7eJajHe', 'Active', '2026-07-04 07:53:47', '2026-07-05 21:39:17'),
(6, 'SBI-002', 2, 'Jojana Garabillo', 'jojanajeangarabillo@gmail.com', '$2y$10$Gundsv.vdvdqOT1M3VIcP.r/8y/MqzNxDPnxfh2qypFd44fL6xZta', 'Active', '2026-07-04 09:59:45', '2026-07-05 20:07:13'),
(8, 'SBI-002', 4, 'Shane Cacho', 'pam066198@gmail.com', '$2y$10$C45iuufa/eeIE5.hJT10qeI4zanTZnwQDp5AvIm7lLmYL4x.JMcny', 'Active', '2026-07-04 14:41:02', '2026-07-04 22:42:55'),
(9, 'SBI-002', 3, 'Marc Beringuela', 'ruberducky032518@gmail.com', '$2y$10$mRG5TnwyVCEkohLgsXiCe.INa226POltPF/0M4fYuXX8mq925V7kO', 'Active', '2026-07-04 14:55:07', '2026-07-05 22:02:08'),
(11, 'SBI-002', 5, 'Jean Montero', 'joepatlacerna54@gmail.com', '$2y$10$ga5HM6WcD0wQSSvnpCAvue4UdqknCryN93mJVLatoI4GAiEDHctNO', 'Active', '2026-07-04 15:09:39', '2026-07-05 21:40:39'),
(14, 'SBI-003', 2, 'Joepat Lacerna', 'opat09252005@gmail.com', '$2y$10$JB4.S8HI8Zu.IbvLHuMhH.3mnmqmyWMdV4ID/nRxcotOs.tmdCasm', 'Active', '2026-07-05 11:56:22', '2026-07-05 20:04:06');

-- --------------------------------------------------------

--
-- Table structure for table `user_tokens`
--

CREATE TABLE `user_tokens` (
  `token_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `token_type` enum('email_verification','password_reset') NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_tokens`
--

INSERT INTO `user_tokens` (`token_id`, `user_id`, `token`, `token_type`, `expires_at`, `used_at`, `created_at`) VALUES
(4, 6, 'a41ed9f36314ad54ff4f2554ff008b89f61cb19c55aeaeef8f2cb93d33216e38', 'password_reset', '2026-07-05 11:59:45', '2026-07-04 18:00:58', '2026-07-04 09:59:45'),
(5, 8, '1fcd23abefddcddcf498c21960875660888206697f37e805029be990b73ec67d', 'password_reset', '2026-07-05 16:41:02', '2026-07-04 22:42:31', '2026-07-04 14:41:02'),
(6, 9, '1f334172a21b208140468173e6e71e38dbba07f295e1ef9b600b7048807d937d', 'password_reset', '2026-07-05 16:55:07', '2026-07-04 22:55:41', '2026-07-04 14:55:07'),
(8, 11, '3a855cf3fee53164de087f1b76dff230dbcb461504aa1adf6a775e54e456e3a0', 'password_reset', '2026-07-05 17:09:39', '2026-07-04 23:10:11', '2026-07-04 15:09:39'),
(11, 14, 'e4fd41d41514700ccd436d3eecae0ccba581187dc1462b4ab4003f327fd3b2c7', 'password_reset', '2026-07-06 13:56:22', '2026-07-05 19:57:01', '2026-07-05 11:56:22');

-- --------------------------------------------------------

--
-- Table structure for table `vaccination_records`
--

CREATE TABLE `vaccination_records` (
  `vaccination_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `dose_number` int(11) NOT NULL,
  `date_administered` date DEFAULT NULL,
  `next_schedule` date DEFAULT NULL,
  `vaccination_status` enum('Scheduled','Completed','Missed') DEFAULT 'Scheduled',
  `is_final_dose` tinyint(1) DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `nurse_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `animal_bite_cases`
--
ALTER TABLE `animal_bite_cases`
  ADD PRIMARY KEY (`case_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `admin_staff_id` (`admin_staff_id`),
  ADD KEY `fk_case_branch` (`branch_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_audit_branch` (`branch_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`);

--
-- Indexes for table `document_tracking`
--
ALTER TABLE `document_tracking`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `inventory_categories`
--
ALTER TABLE `inventory_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `unit_id` (`unit_id`);

--
-- Indexes for table `inventory_stocks`
--
ALTER TABLE `inventory_stocks`
  ADD PRIMARY KEY (`stock_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `fk_stock_branch` (`branch_id`);

--
-- Indexes for table `inventory_usage_history`
--
ALTER TABLE `inventory_usage_history`
  ADD PRIMARY KEY (`usage_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `fk_usage_branch` (`branch_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD KEY `fk_patient_branch` (`branch_id`);

--
-- Indexes for table `philhealth_records`
--
ALTER TABLE `philhealth_records`
  ADD PRIMARY KEY (`philhealth_record_id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `prediction_results`
--
ALTER TABLE `prediction_results`
  ADD PRIMARY KEY (`prediction_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `generated_by` (`generated_by`),
  ADD KEY `fk_prediction_branch` (`branch_id`);

--
-- Indexes for table `registry_records`
--
ALTER TABLE `registry_records`
  ADD PRIMARY KEY (`registry_id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`);

--
-- Indexes for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_transaction_branch` (`branch_id`);

--
-- Indexes for table `training_dataset`
--
ALTER TABLE `training_dataset`
  ADD PRIMARY KEY (`training_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `fk_training_branch` (`branch_id`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`unit_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `fk_user_branch` (`branch_id`);

--
-- Indexes for table `user_tokens`
--
ALTER TABLE `user_tokens`
  ADD PRIMARY KEY (`token_id`),
  ADD KEY `fk_user_tokens_user` (`user_id`);

--
-- Indexes for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  ADD PRIMARY KEY (`vaccination_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `case_id` (`case_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `nurse_id` (`nurse_id`),
  ADD KEY `fk_vaccination_branch` (`branch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `animal_bite_cases`
--
ALTER TABLE `animal_bite_cases`
  MODIFY `case_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- AUTO_INCREMENT for table `document_tracking`
--
ALTER TABLE `document_tracking`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_categories`
--
ALTER TABLE `inventory_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_stocks`
--
ALTER TABLE `inventory_stocks`
  MODIFY `stock_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_usage_history`
--
ALTER TABLE `inventory_usage_history`
  MODIFY `usage_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `philhealth_records`
--
ALTER TABLE `philhealth_records`
  MODIFY `philhealth_record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prediction_results`
--
ALTER TABLE `prediction_results`
  MODIFY `prediction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registry_records`
--
ALTER TABLE `registry_records`
  MODIFY `registry_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `training_dataset`
--
ALTER TABLE `training_dataset`
  MODIFY `training_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `user_tokens`
--
ALTER TABLE `user_tokens`
  MODIFY `token_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  MODIFY `vaccination_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `animal_bite_cases`
--
ALTER TABLE `animal_bite_cases`
  ADD CONSTRAINT `animal_bite_cases_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `animal_bite_cases_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `animal_bite_cases_ibfk_3` FOREIGN KEY (`admin_staff_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_case_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `fk_audit_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `document_tracking`
--
ALTER TABLE `document_tracking`
  ADD CONSTRAINT `document_tracking_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `animal_bite_cases` (`case_id`),
  ADD CONSTRAINT `document_tracking_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD CONSTRAINT `inventory_items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`category_id`),
  ADD CONSTRAINT `inventory_items_ibfk_2` FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`);

--
-- Constraints for table `inventory_stocks`
--
ALTER TABLE `inventory_stocks`
  ADD CONSTRAINT `fk_stock_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `inventory_stocks_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  ADD CONSTRAINT `inventory_stocks_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `inventory_usage_history`
--
ALTER TABLE `inventory_usage_history`
  ADD CONSTRAINT `fk_usage_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `inventory_usage_history_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  ADD CONSTRAINT `inventory_usage_history_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `fk_patient_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `fk_patients_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `philhealth_records`
--
ALTER TABLE `philhealth_records`
  ADD CONSTRAINT `philhealth_records_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `animal_bite_cases` (`case_id`),
  ADD CONSTRAINT `philhealth_records_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `prediction_results`
--
ALTER TABLE `prediction_results`
  ADD CONSTRAINT `fk_prediction_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `prediction_results_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  ADD CONSTRAINT `prediction_results_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `prediction_results_ibfk_3` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `registry_records`
--
ALTER TABLE `registry_records`
  ADD CONSTRAINT `registry_records_ibfk_1` FOREIGN KEY (`case_id`) REFERENCES `animal_bite_cases` (`case_id`),
  ADD CONSTRAINT `registry_records_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  ADD CONSTRAINT `fk_transaction_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `stock_transactions_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  ADD CONSTRAINT `stock_transactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `stock_transactions_ibfk_3` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `training_dataset`
--
ALTER TABLE `training_dataset`
  ADD CONSTRAINT `fk_training_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `training_dataset_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  ADD CONSTRAINT `training_dataset_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`);

--
-- Constraints for table `user_tokens`
--
ALTER TABLE `user_tokens`
  ADD CONSTRAINT `fk_user_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  ADD CONSTRAINT `fk_vaccination_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `vaccination_records_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `vaccination_records_ibfk_2` FOREIGN KEY (`case_id`) REFERENCES `animal_bite_cases` (`case_id`),
  ADD CONSTRAINT `vaccination_records_ibfk_3` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`),
  ADD CONSTRAINT `vaccination_records_ibfk_4` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `vaccination_records_ibfk_5` FOREIGN KEY (`nurse_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
