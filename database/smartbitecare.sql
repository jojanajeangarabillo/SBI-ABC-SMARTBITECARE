-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 09, 2026 at 05:17 PM
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

--
-- Dumping data for table `animal_bite_cases`
--

INSERT INTO `animal_bite_cases` (`case_id`, `patient_id`, `branch_id`, `animal_type`, `bite_location`, `bite_category`, `animal_status`, `date_of_bite`, `case_status`, `remarks`, `admin_staff_id`, `created_at`) VALUES
(11, 14, 'SBI-002', 'Dog', '', NULL, 'Alive/Healthy', NULL, 'Ongoing', '', 16, '2026-07-07 08:50:00'),
(12, 15, 'SBI-002', 'Dog', '', NULL, 'Alive/Healthy', NULL, 'Ongoing', '', 16, '2026-07-07 08:50:17'),
(13, 16, 'SBI-002', 'Dog', '', NULL, 'Alive/Healthy', '2026-07-07', 'Ongoing', '', 16, '2026-07-07 08:56:20'),
(15, 18, 'SBI-002', 'Dog', '', NULL, 'Alive/Healthy', NULL, 'Ongoing', '', 16, '2026-07-07 09:17:21'),
(18, 24, 'SBI-002', 'Dog', '', NULL, 'Alive/Healthy', NULL, 'Ongoing', '', 8, '2026-07-08 07:40:22'),
(25, 31, 'SBI-002', 'Cat', 'RIGHT LEG', NULL, 'Alive/Healthy', '2026-07-09', 'Ongoing', NULL, 8, '2026-07-08 22:22:55'),
(27, 33, 'SBI-002', 'Cat', 'RIGHT LEG', NULL, 'Alive/Healthy', '2026-07-08', 'Ongoing', NULL, 8, '2026-07-09 02:23:52');

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
(107, 11, 'SBI-002', 'Logout: User \'Jean Montero\' (Role: Inventory Officer) (IP: ::1)', 'Login System', '2026-07-05 14:57:47'),
(108, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: ::1)', 'Login System', '2026-07-05 16:18:39'),
(109, 1, 'SBI-001', 'Created new branch admin: branchadmin (ID: 15) for branch ID: SBI-003', 'Branch Admin Management', '2026-07-05 16:19:47'),
(110, 1, 'SBI-001', 'Welcome email sent to new branch admin: branchadmin (ID: 15, Email: sheyn.cacho@gmail.com)', 'Branch Admin Management', '2026-07-05 16:19:53'),
(111, 15, 'SBI-003', 'Login Success: User \'branchadmin\' - Role: Branch Admin, Branch: Pasig Branch (IP: ::1)', 'Login System', '2026-07-05 16:20:54'),
(112, 15, 'SBI-003', 'Logout: User \'branchadmin\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-05 16:22:14'),
(113, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: ::1)', 'Login System', '2026-07-05 16:22:42'),
(114, 1, 'SBI-001', 'Logout: User \'superadmin\' (Role: Super Admin) (IP: ::1)', 'Login System', '2026-07-05 16:25:53'),
(115, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-05 16:26:17'),
(116, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-05 16:26:49'),
(117, 8, 'SBI-002', 'Login Success: User \'Shane Cacho\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-05 16:27:30'),
(118, 8, 'SBI-002', 'Added patient: Shane Ella Mae Franco Cacho', 'Patient Records', '2026-07-05 22:28:52'),
(119, 8, 'SBI-002', 'Added patient: ken allen rosales', 'Patient Records', '2026-07-05 22:30:21'),
(120, 8, 'SBI-002', 'Added patient: Michelle Batacan', 'Patient Records', '2026-07-05 22:45:50'),
(121, 8, 'SBI-002', 'Added patient: Michelle Batacan', 'Patient Records', '2026-07-05 22:45:50'),
(122, 8, 'SBI-002', 'Deleted patient: Michelle Batacan (ID: 4)', 'Patient Records', '2026-07-05 22:52:13'),
(123, 8, 'SBI-002', 'Deleted patient: ken allen rosales (ID: 2)', 'Patient Records', '2026-07-05 23:15:51'),
(124, 8, 'SBI-002', 'Login Success: User \'Shane Cacho\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-06 11:50:47'),
(125, 8, 'SBI-002', 'Added Admin Staff patient record: 26-0001', 'Admin Staff Patient Records', '2026-07-06 11:52:40'),
(126, 8, 'SBI-002', 'Added Admin Staff patient record: 26-0002', 'Admin Staff Patient Records', '2026-07-06 12:09:16'),
(127, 8, 'SBI-002', 'Logout: User \'Shane Cacho\' (Role: Administrative Staff) (IP: ::1)', 'Login System', '2026-07-06 12:10:34'),
(128, 15, 'SBI-003', 'Login Success: User \'branchadmin\' - Role: Branch Admin, Branch: Pasig Branch (IP: ::1)', 'Login System', '2026-07-06 12:11:26'),
(129, 15, 'SBI-003', 'Logout: User \'branchadmin\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-06 12:11:41'),
(130, 8, 'SBI-002', 'Login Success: User \'Shane Cacho\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-06 12:12:00'),
(131, 8, 'SBI-002', 'Added Admin Staff patient record: 26-0003', 'Admin Staff Patient Records', '2026-07-06 12:15:51'),
(132, 8, 'SBI-002', 'Deleted Admin Staff patient record: 26-0001', 'Admin Staff Patient Records', '2026-07-06 12:20:33'),
(133, 8, 'SBI-002', 'Logout: User \'Shane Cacho\' (Role: Administrative Staff) (IP: ::1)', 'Login System', '2026-07-06 12:35:08'),
(134, 8, 'SBI-002', 'Login Success: User \'Shane Cacho\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-06 12:35:24'),
(135, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-07 06:23:15'),
(136, 6, 'SBI-002', 'Added new user: cachosheyn (ID: 16)', 'User Management', '2026-07-07 06:24:22'),
(137, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-07 06:26:46'),
(138, 16, 'SBI-002', 'Login Success: User \'cachosheyn\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-07 06:27:17'),
(139, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-07 06:27:54'),
(140, 6, 'SBI-002', 'Updated user: cachosheyn (ID: 16)', 'User Management', '2026-07-07 06:28:09'),
(141, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-07 06:28:11'),
(142, 16, 'SBI-002', 'Login Success: User \'cachosheyn\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-07 06:28:17'),
(143, 16, 'SBI-002', 'Updated case 26-0001', 'Patient Record', '2026-07-07 08:30:41'),
(144, 16, 'SBI-002', 'Updated case 26-0001', 'Patient Record', '2026-07-07 08:42:25'),
(145, 16, 'SBI-002', 'Updated case 26-0001', 'Patient Record', '2026-07-07 08:42:26'),
(146, 16, 'SBI-002', 'Updated case 26-0004', 'Patient Record', '2026-07-07 08:42:41'),
(147, 16, 'SBI-002', 'Deleted patient record: ddd (Case: 26-0004)', 'Patient Record', '2026-07-07 08:43:30'),
(148, 16, 'SBI-002', 'Deleted patient record: sHANE CACHO (Case: 26-0001)', 'Patient Record', '2026-07-07 08:45:21'),
(149, 16, 'SBI-002', 'Deleted patient record: Ken Allen Rosales (Case: 26-0003)', 'Patient Record', '2026-07-07 08:45:39'),
(150, 16, 'SBI-002', 'Deleted patient record: Ken Allen Rosales (Case: 26-0002)', 'Patient Record', '2026-07-07 08:45:41'),
(151, 16, 'SBI-002', 'Deleted patient record: sHANE CACHO (Case: 26-0001)', 'Patient Record', '2026-07-07 08:49:43'),
(152, 16, 'SBI-002', 'Deleted patient record: sHANE CACHO (Case: 26-0001)', 'Patient Record', '2026-07-07 08:49:45'),
(153, 16, 'SBI-002', 'Updated patient record: SHANE CACHO (Case: 26-0001)', 'Patient Record', '2026-07-07 08:50:01'),
(154, 16, 'SBI-002', 'Updated patient record: ELLA MAE (Case: 26-0002)', 'Patient Record', '2026-07-07 08:50:17'),
(155, 16, 'SBI-002', 'Updated patient record: ddd (Case: 26-0003)', 'Patient Record', '2026-07-07 08:56:21'),
(156, 16, 'SBI-002', 'Updated patient record: ddd (Case: 26-0004)', 'Patient Record', '2026-07-07 08:56:55'),
(157, 16, 'SBI-002', 'Deleted patient record: ddd (Case: 26-0004)', 'Patient Record', '2026-07-07 08:59:56'),
(158, 16, 'SBI-002', 'Updated patient record: ken (Case: 26-0004)', 'Patient Record', '2026-07-07 09:17:21'),
(159, 16, 'SBI-002', 'Updated patient record: sHANE CACHO (Case: 26-0005)', 'Patient Record', '2026-07-07 09:24:08'),
(160, 16, 'SBI-002', 'Deleted patient record: sHANE CACHO (Case: 26-0005)', 'Patient Record', '2026-07-07 09:24:29'),
(161, 16, 'SBI-002', 'Logout: User \'cachosheyn\' (Role: Administrative Staff) (IP: ::1)', 'Login System', '2026-07-07 09:51:39'),
(162, 16, 'SBI-002', 'Login Success: User \'cachosheyn\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-07 09:51:56'),
(163, 16, 'SBI-002', 'Logout: User \'cachosheyn\' (Role: Administrative Staff) (IP: ::1)', 'Login System', '2026-07-07 10:12:01'),
(164, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:22:43'),
(165, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:22:49'),
(166, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:22:52'),
(167, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:22:56'),
(168, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:22:56'),
(169, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:23:24'),
(170, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:23:31'),
(171, 8, 'SBI-002', 'Login Failed: User \'Shane Cacho\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-08 02:24:21'),
(172, 8, 'SBI-002', 'Login Success: User \'Shane Cacho\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-08 02:24:39'),
(173, 8, 'SBI-002', 'Logout: User \'Shane Cacho\' (Role: Administrative Staff) (IP: ::1)', 'Login System', '2026-07-08 02:39:03'),
(174, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-08 02:39:11'),
(175, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-08 02:41:33'),
(176, 8, 'SBI-002', 'Login Success: User \'Shane Cacho\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-08 02:41:45'),
(177, 8, 'SBI-002', 'Updated patient record: sHANE CACHO (Case: 26-0005)', 'Patient Record', '2026-07-08 03:45:34'),
(178, 8, 'SBI-002', 'Updated patient record: SHANE CACHO (Case: 26-0006)', 'Patient Record', '2026-07-08 07:40:22'),
(179, 8, 'SBI-002', 'Deleted patient record: sHANE CACHO (Case: 26-0005)', 'Patient Record', '2026-07-08 07:40:27'),
(180, 8, 'SBI-002', 'Updated patient record: SHANE CACHO (Case: 26-0007)', 'Patient Record', '2026-07-08 22:22:55'),
(181, 8, 'SBI-002', 'Updated patient record: SHANE CACHO (Case: 26-0007)', 'Patient Record', '2026-07-08 22:23:31'),
(182, 8, 'SBI-002', 'Updated patient record: SHELLA MAE RUIZ (Case: 26-0010)', 'Patient Record', '2026-07-08 22:36:42'),
(183, 8, 'SBI-002', 'Login Success: User \'Shane Cacho\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 02:09:42'),
(184, 8, 'SBI-002', 'Updated patient record: Antonio, Luiz (Case: 26-0012)', 'Patient Record', '2026-07-09 02:23:52'),
(185, 8, 'SBI-002', 'Updated patient record: Cruz, Ariane (Case: 26-0013)', 'Patient Record', '2026-07-09 02:29:36'),
(186, 8, 'SBI-002', 'Updated patient record: Lala, MoveAnne (Case: 23-0015)', 'Patient Record', '2026-07-09 02:33:32'),
(187, 8, 'SBI-002', 'Updated patient record: Lala, MoveAnne (Case: 23-0015)', 'Patient Record', '2026-07-09 02:40:47'),
(188, 8, 'SBI-002', 'Updated patient record: Cruz, Ariane (Case: 26-0013)', 'Patient Record', '2026-07-09 02:44:51'),
(189, 8, 'SBI-002', 'Deleted patient record: Lala, MoveAnne (Case: 23-0015)', 'Patient Record', '2026-07-09 04:03:35'),
(190, 8, 'SBI-002', 'Deleted patient record: Cruz, Ariane (Case: 26-0013)', 'Patient Record', '2026-07-09 05:25:45'),
(191, 8, 'SBI-002', 'Deleted patient record: SHELLA MAE RUIZ (Case: 26-0010)', 'Patient Record', '2026-07-09 05:28:38'),
(192, 8, 'SBI-002', 'Archived patient record: Antonio, Luiz (Case: 26-0012)', 'Patient Record', '2026-07-09 05:48:53'),
(193, 8, 'SBI-002', 'Updated patient record: Antonio, Luiz (Case: 26-0005)', 'Patient Record', '2026-07-09 06:18:46'),
(194, 8, 'SBI-002', 'Deleted patient record: Antonio, Luiz (Case: 26-0005)', 'Patient Record', '2026-07-09 06:18:55'),
(195, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: ::1)', 'Login System', '2026-07-09 08:16:00'),
(196, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 08:20:53'),
(197, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-09 08:32:00'),
(198, 15, 'SBI-003', 'Login Failed: User \'Mae Ben\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-09 08:32:19'),
(199, 15, 'SBI-003', 'Login Failed: User \'Mae Ben\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-09 08:33:01'),
(200, 15, 'SBI-003', 'Login Failed: User \'Mae Ben\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-09 08:33:07'),
(201, 15, 'SBI-003', 'Login Failed: User \'Mae Ben\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-09 08:33:46'),
(202, 16, 'SBI-002', 'Login Success: User \'Ella Franco\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 08:34:19'),
(203, 15, 'SBI-003', 'Login Failed: User \'Mae Ben\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-09 08:37:00'),
(204, 15, 'SBI-003', 'Login Failed: User \'Mae Ben\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-09 08:37:10'),
(205, 15, 'SBI-003', 'Login Success: User \'Mae Ben\' - Role: Branch Admin, Branch: Pasig Branch (IP: ::1)', 'Login System', '2026-07-09 08:37:18'),
(206, 15, 'SBI-003', 'Logout: User \'Mae Ben\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-09 08:41:57'),
(207, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 08:42:18'),
(208, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-09 09:08:30'),
(209, 15, 'SBI-003', 'Login Success: User \'Mae Ben\' - Role: Branch Admin, Branch: Pasig Branch (IP: ::1)', 'Login System', '2026-07-09 09:08:59'),
(210, 15, 'SBI-003', 'Logout: User \'Mae Ben\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-09 09:09:21'),
(211, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 09:12:45'),
(212, 6, 'SBI-002', 'Generated case report - Branch: Cainta Branch, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:23:26'),
(213, 6, 'SBI-002', 'Generated vaccination report - Branch: Cainta Branch, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:23:37'),
(214, 6, 'SBI-002', 'Generated vaccination report - Branch: Cainta Branch, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:28:23'),
(215, 6, 'SBI-002', 'Generated patient report - Branch: Cainta Branch, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:38:25'),
(216, 6, 'SBI-002', 'Generated patient report - Branch: Cainta Branch, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:43:05'),
(217, 1, 'SBI-001', 'Generated audit_logs report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:47:33'),
(218, 1, 'SBI-001', 'Generated branch_admin report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:47:57'),
(219, 1, 'SBI-001', 'Generated audit_logs report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:48:06'),
(220, 1, 'SBI-001', 'Generated audit_logs report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:51:41'),
(221, 1, 'SBI-001', 'Generated user report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:55:58'),
(222, 1, 'SBI-001', 'Generated audit_logs report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:56:08'),
(223, 1, 'SBI-001', 'Generated branch_admin report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:58:08'),
(224, 1, 'SBI-001', 'Generated branch_performance report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 09:58:58'),
(225, 1, 'SBI-001', 'Generated audit_logs report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 10:04:45'),
(226, 1, 'SBI-001', 'Generated audit_logs report - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 10:12:45'),
(227, 1, 'SBI-001', 'Viewed Branch Performance Monitoring - Metric: Total Cases, Date: This Month', 'Performance Monitoring', '2026-07-09 11:07:32'),
(228, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 11:31:34'),
(229, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-09 11:33:32'),
(230, 15, 'SBI-003', 'Login Success: User \'Mae Ben\' - Role: Branch Admin, Branch: Pasig Branch (IP: ::1)', 'Login System', '2026-07-09 11:33:48'),
(231, 15, 'SBI-003', 'Logout: User \'Mae Ben\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-09 11:34:21'),
(232, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 11:34:34'),
(233, 1, 'SBI-001', 'Viewed Branch Performance Monitoring - Metric: Total Cases, Date: This Month', 'Performance Monitoring', '2026-07-09 11:43:31'),
(234, 1, 'SBI-001', 'Generated user report (csv) - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 12:03:39'),
(235, 1, 'SBI-001', 'Generated branch_performance report (pdf) - Branch: All Branches, Date Range: 2026-06-09 to 2026-07-09', 'Reports', '2026-07-09 12:03:51'),
(236, 9, 'SBI-002', 'Login Success: User \'Marc Beringuela\' - Role: Nurse, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 12:05:12'),
(237, 1, 'SBI-001', 'Viewed Branch Performance Monitoring - Metric: Total Cases, Date: This Month', 'Performance Monitoring', '2026-07-09 15:05:18'),
(238, 16, 'SBI-002', 'Logout: User \'Ella Franco\' (Role: Administrative Staff) (IP: ::1)', 'Login System', '2026-07-09 15:10:57'),
(239, 11, 'SBI-002', 'Login Success: User \'Jean Montero\' - Role: Inventory Officer, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 15:11:17');

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

--
-- Dumping data for table `inventory_items`
--

INSERT INTO `inventory_items` (`item_id`, `category_id`, `unit_id`, `item_name`, `minimum_stock`, `description`, `is_predictable`) VALUES
(1, 1, 1, 'Rabies Vaccine (Default)', 10, 'Default vaccine item for vaccination records', 1);

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
-- Table structure for table `medical_documents`
--

CREATE TABLE `medical_documents` (
  `document_id` int(11) NOT NULL,
  `branch_id` varchar(10) NOT NULL,
  `case_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `document_type` enum('Medical Certificate','Vaccination Certificate','Referral Letter','Other') NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `status` enum('Active','Archived') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_documents`
--

INSERT INTO `medical_documents` (`document_id`, `branch_id`, `case_id`, `patient_id`, `document_type`, `document_name`, `file_name`, `file_path`, `file_type`, `file_size`, `description`, `uploaded_by`, `uploaded_at`, `updated_at`, `status`) VALUES
(1, 'SBI-002', NULL, NULL, 'Medical Certificate', 'Group 1- Chat\'s Eatery', 'Group 1- Chat\'s Eatery.pdf', 'uploads/documents/1783483810_Group1-ChatsEatery.pdf', 'application/pdf', 530386, 'eme', 8, '2026-07-08 04:10:10', '2026-07-08 04:10:53', 'Active'),
(2, 'SBI-002', NULL, NULL, 'Referral Letter', 'dd', 'MedCert-Cainta.pdf', 'uploads/documents/1783483920_MedCert-Cainta.pdf', 'application/pdf', 136030, '', 8, '2026-07-08 04:12:00', NULL, 'Active'),
(3, 'SBI-002', 13, 16, 'Medical Certificate', 'Medical Certificate_ddd_2026-07-09', 'Medical Certificate_ddd_2026-07-09.pdf', 'documents/Medical Certificate_ddd_2026-07-09.pdf', NULL, NULL, NULL, 9, '2026-07-09 14:34:53', NULL, 'Active');

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `notification_type`, `is_read`, `created_at`) VALUES
(1, 8, 'Patient record saved', 'Case 26-0001 for Shane Cacho was saved.', 'admin_staff_patient_record', 0, '2026-07-06 11:52:40'),
(2, 8, 'Patient record saved', 'Case 26-0002 for Ken Allen Rosales was saved.', 'admin_staff_patient_record', 0, '2026-07-06 12:09:16'),
(3, 8, 'Patient record saved', 'Case 26-0003 for Ken Allen Rosales was saved.', 'admin_staff_patient_record', 0, '2026-07-06 12:15:51'),
(4, 8, 'Patient record deleted', 'Case 26-0001 for Shane Cacho was deleted.', 'admin_staff_patient_record', 0, '2026-07-06 12:20:33');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `branch_id` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`patient_id`, `full_name`, `email`, `contact_number`, `birthday`, `gender`, `address`, `created_at`, `branch_id`) VALUES
(1, 'Shane Ella Mae Franco Cacho', 'sheyn.cacho@gmail.com', '09993808837', '2005-10-03', 'Female', '14', '2026-07-05 22:28:50', 'SBI-002'),
(3, 'Michelle Batacan', 'michelle@gmail.com', '09942478935', '2004-02-09', 'Female', 'Brookside Cainta', '2026-07-05 22:45:48', 'SBI-002'),
(6, 'Ken Allen Rosales', NULL, '0994 47186244', '2004-11-26', 'Male', 'Blk 123 Street', '2026-07-06 12:09:16', 'SBI-002'),
(7, 'sHANE CACHO', NULL, '', NULL, '', '', '2026-07-07 08:30:41', 'SBI-002'),
(9, 'sHANE CACHO', NULL, '', NULL, '', '', '2026-07-07 08:42:25', 'SBI-002'),
(10, 'sHANE CACHO', NULL, '', NULL, '', '', '2026-07-07 08:42:26', 'SBI-002'),
(11, 'ddd', NULL, '', NULL, '', 'dddd', '2026-07-07 08:42:41', 'SBI-002'),
(14, 'SHANE CACHO', NULL, '', '2005-10-03', 'Female', '14, Saint Catherine Street Rodfer 3 A-prime', '2026-07-07 08:50:00', 'SBI-002'),
(15, 'ELLA MAE', NULL, '', NULL, '', '3', '2026-07-07 08:50:17', 'SBI-002'),
(16, 'ddd', NULL, '', '2020-07-02', 'Female', '', '2026-07-07 08:56:20', 'SBI-002'),
(17, 'ddd', NULL, '', NULL, '', '', '2026-07-07 08:56:55', 'SBI-002'),
(18, 'ken', NULL, '0994146223', NULL, '', '', '2026-07-07 09:17:21', 'SBI-002'),
(19, 'sHANE CACHO', NULL, '', NULL, '', '', '2026-07-07 09:24:08', 'SBI-002'),
(23, 'sHANE CACHO', NULL, '', NULL, '', '', '2026-07-08 03:45:33', 'SBI-002'),
(24, 'SHANE CACHO', NULL, '', NULL, '', '', '2026-07-08 07:40:22', 'SBI-002'),
(31, 'SHANE CACHO', NULL, '', '2005-10-03', 'Female', '14, Saint Catherine Street Rodfer 3 A-prime', '2026-07-08 22:22:53', 'SBI-002'),
(32, 'SHELLA MAE RUIZ', NULL, '0994146223', '2020-07-09', 'Female', '14, Saint Catherine Street Rodfer 3 A-prime', '2026-07-08 22:36:40', 'SBI-002'),
(33, 'Antonio, Luiz', NULL, '0994146223', '2008-09-11', 'Female', '14, Saint Catherine Street Rodfer 3 A-prime', '2026-07-09 02:23:52', 'SBI-002'),
(34, 'Cruz, Ariane', NULL, '0994146223', '2004-05-05', 'Female', 'Cainta Rizal', '2026-07-09 02:29:34', 'SBI-002'),
(35, 'Lala, MoveAnne', NULL, '0993808837', '2025-07-08', 'Female', 'Pasig', '2026-07-09 02:33:32', 'SBI-002'),
(49, 'Antonio, Luiz', NULL, '0994146223', '2000-10-03', 'Female', '14Saint Catherine Street Rodfer 3 A-prime', '2026-07-09 06:18:46', 'SBI-002');

-- --------------------------------------------------------

--
-- Table structure for table `philhealth_records`
--

CREATE TABLE `philhealth_records` (
  `philhealth_record_id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `has_philhealth` enum('Yes','No') DEFAULT 'No',
  `philhealth_membership` varchar(50) DEFAULT NULL,
  `status` enum('For Writing','For Screening','For Signing/Transmittal','Completed') DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `philhealth_records`
--

INSERT INTO `philhealth_records` (`philhealth_record_id`, `case_id`, `has_philhealth`, `philhealth_membership`, `status`, `remarks`, `updated_by`, `updated_at`) VALUES
(11, 11, 'No', NULL, 'For Writing', '', 16, '2026-07-07 16:50:01'),
(12, 12, 'No', NULL, 'For Writing', '', 16, '2026-07-07 16:50:17'),
(13, 13, 'No', NULL, 'For Writing', '', 16, '2026-07-07 16:56:20'),
(15, 15, 'No', NULL, 'For Writing', '', 16, '2026-07-07 17:17:21'),
(18, 18, 'No', NULL, 'For Writing', '', 8, '2026-07-08 15:40:22'),
(25, 25, 'No', NULL, 'For Writing', NULL, 8, '2026-07-09 06:23:30'),
(27, 27, 'No', 'Sponsored', '', NULL, 8, '2026-07-09 10:23:52');

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
  `dose_d21` tinyint(1) DEFAULT 0,
  `dose_d28_30` tinyint(1) DEFAULT 0,
  `booster` tinyint(1) DEFAULT 0,
  `contact_number` varchar(30) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registry_records`
--

INSERT INTO `registry_records` (`registry_id`, `case_id`, `registry_number`, `status_of_biting_animal`, `erig`, `ats`, `tt`, `active_regimen`, `dose_d0`, `dose_d3`, `dose_d7`, `dose_d14`, `dose_d21`, `dose_d28_30`, `booster`, `contact_number`, `remarks`, `updated_by`, `updated_at`) VALUES
(11, 11, '26-0001', 'Alive/Healthy', 0, 0, 0, 'PVRV TRC SPEEDA', 0, 0, 0, 0, 0, 0, 0, '', '', 16, '2026-07-07 16:50:01'),
(12, 12, '26-0002', 'Alive/Healthy', 0, 0, 0, 'PVRV TRC SPEEDA', 0, 0, 0, 0, 0, 0, 0, '', '', 16, '2026-07-07 16:50:17'),
(13, 13, '26-0003', 'Alive/Healthy', 0, 0, 0, 'PVRV TRC SPEEDA', 0, 0, 0, 0, 0, 0, 0, '', '', 16, '2026-07-07 16:56:20'),
(15, 15, '26-0004', 'Alive/Healthy', 0, 0, 0, 'PVRV TRC SPEEDA', 1, 1, 0, 0, 0, 0, 0, '0994146223', '', 16, '2026-07-07 17:17:21'),
(18, 18, '26-0006', 'Alive/Healthy', 0, 0, 0, 'PVRV TRC SPEEDA', 0, 0, 0, 0, 0, 0, 0, '', '', 8, '2026-07-08 15:40:22'),
(25, 25, '26-0007', 'Alive/Healthy', 0, 0, 1, 'PVRV TRC SPEEDA', 1, 1, 1, 1, 0, 1, 0, '', NULL, 8, '2026-07-09 06:23:30'),
(27, 27, '26-0012', 'Alive/Healthy', 0, 0, 0, 'PVRV TRC SPEEDA', 1, 0, 0, 0, 0, 0, 0, '0994146223', NULL, 8, '2026-07-09 10:23:52');

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
(1, 'SBI-001', 1, 'superadmin', 'garabillo_jojanajean@plpasig.edu.ph', '$2y$10$gTUuk2.GeUd5BNNryWGq8OhJWjQcdqk8rOMPUMJ1VkFaxc7eJajHe', 'Active', '2026-07-04 07:53:47', '2026-07-09 16:16:00'),
(6, 'SBI-002', 2, 'Jojana Garabillo', 'jojanajeangarabillo@gmail.com', '$2y$10$Gundsv.vdvdqOT1M3VIcP.r/8y/MqzNxDPnxfh2qypFd44fL6xZta', 'Active', '2026-07-04 09:59:45', '2026-07-09 19:34:34'),
(8, 'SBI-002', 4, 'Shane Cacho', 'pam066198@gmail.com', '$2a$12$rfw67uNcLetmfn6M2Dv5e.ff49vAitKIHni3Q7iKV.zgMruHVfkIa', 'Active', '2026-07-04 14:41:02', '2026-07-09 10:09:42'),
(9, 'SBI-002', 3, 'Marc Beringuela', 'ruberducky032518@gmail.com', '$2y$10$mRG5TnwyVCEkohLgsXiCe.INa226POltPF/0M4fYuXX8mq925V7kO', 'Active', '2026-07-04 14:55:07', '2026-07-09 20:05:12'),
(11, 'SBI-002', 5, 'Jean Montero', 'joepatlacerna54@gmail.com', '$2y$10$ga5HM6WcD0wQSSvnpCAvue4UdqknCryN93mJVLatoI4GAiEDHctNO', 'Active', '2026-07-04 15:09:39', '2026-07-09 23:11:17'),
(14, 'SBI-003', 2, 'Joepat Lacerna', 'opat09252005@gmail.com', '$2y$10$JB4.S8HI8Zu.IbvLHuMhH.3mnmqmyWMdV4ID/nRxcotOs.tmdCasm', 'Active', '2026-07-05 11:56:22', '2026-07-05 20:04:06'),
(15, 'SBI-003', 2, 'Mae Ben', 'sheyn.cacho@gmail.com', '$2y$10$knOsicB5IV6qD4SqIaF3WOBBnyrFDJYTknP8yqBSXlo35gIp8mLim', 'Active', '2026-07-05 16:19:46', '2026-07-09 19:33:48'),
(16, 'SBI-002', 4, 'Ella Franco', 'cachosheyn@gmail.com', '$2y$10$lLv3F5B3Yu1QGQU1GkAbq./clNj/7RMlMH1noMMwNAqv/45mymWfm', 'Active', '2026-07-07 06:24:22', '2026-07-09 16:34:19');

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
(11, 14, 'e4fd41d41514700ccd436d3eecae0ccba581187dc1462b4ab4003f327fd3b2c7', 'password_reset', '2026-07-06 13:56:22', '2026-07-05 19:57:01', '2026-07-05 11:56:22'),
(12, 15, '22d1c51ac06c0f0b836905855ce30e38617b82e986147a0f268bc19fe7686659', 'password_reset', '2026-07-06 18:19:47', '2026-07-06 00:20:44', '2026-07-05 16:19:47'),
(13, 16, '96e84e270cda335e0c3f52d9a2f8639c397f976f952017e74c928a61ab3a25bf', 'password_reset', '2026-07-08 08:24:22', NULL, '2026-07-07 06:24:22');

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
  `scheduled_date` date DEFAULT NULL,
  `administered_at` varchar(100) DEFAULT NULL,
  `next_schedule` date DEFAULT NULL,
  `vaccination_status` enum('Scheduled','Completed','Missed') DEFAULT 'Scheduled',
  `is_final_dose` tinyint(1) DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `nurse_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vaccination_records`
--

INSERT INTO `vaccination_records` (`vaccination_id`, `patient_id`, `case_id`, `item_id`, `branch_id`, `dose_number`, `date_administered`, `scheduled_date`, `administered_at`, `next_schedule`, `vaccination_status`, `is_final_dose`, `remarks`, `nurse_id`, `created_at`) VALUES
(4, 18, 15, 1, 'SBI-002', 1, '2026-07-07', NULL, NULL, NULL, 'Completed', 0, NULL, 16, '2026-07-07 09:17:21'),
(5, 18, 15, 1, 'SBI-002', 2, '2026-07-10', NULL, NULL, NULL, 'Completed', 0, NULL, 16, '2026-07-07 09:17:21'),
(14, 31, 25, 1, 'SBI-002', 1, '2026-07-09', '2026-07-09', NULL, NULL, 'Completed', 0, '', 8, '2026-07-08 22:23:31'),
(15, 31, 25, 1, 'SBI-002', 2, '2026-07-09', '2026-07-12', NULL, NULL, 'Completed', 0, '', 8, '2026-07-08 22:23:31'),
(16, 31, 25, 1, 'SBI-002', 3, '2026-07-09', NULL, NULL, NULL, 'Completed', 0, '', 8, '2026-07-08 22:23:31'),
(17, 31, 25, 1, 'SBI-002', 4, '2026-07-09', NULL, NULL, NULL, 'Completed', 0, '', 8, '2026-07-08 22:23:31'),
(18, 31, 25, 1, 'SBI-002', 5, '2026-07-09', NULL, NULL, NULL, 'Completed', 0, '', 8, '2026-07-08 22:23:31'),
(19, 31, 25, 1, 'SBI-002', 6, '2026-07-09', NULL, NULL, NULL, 'Completed', 0, '', 8, '2026-07-08 22:23:31'),
(25, 33, 27, 1, 'SBI-002', 1, '2026-07-09', '2026-07-09', NULL, NULL, 'Completed', 0, '', 8, '2026-07-09 02:23:52'),
(26, 33, 27, 1, 'SBI-002', 2, NULL, '2026-07-12', NULL, NULL, 'Scheduled', 0, '', 8, '2026-07-09 02:23:52'),
(27, 33, 27, 1, 'SBI-002', 3, NULL, '2026-07-16', NULL, NULL, 'Scheduled', 0, '', 8, '2026-07-09 02:23:52'),
(28, 33, 27, 1, 'SBI-002', 6, NULL, '2026-08-06', NULL, NULL, 'Scheduled', 0, '', 8, '2026-07-09 02:23:52');

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
-- Indexes for table `medical_documents`
--
ALTER TABLE `medical_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `fk_doc_branch` (`branch_id`),
  ADD KEY `fk_doc_case` (`case_id`),
  ADD KEY `fk_doc_patient` (`patient_id`),
  ADD KEY `fk_doc_uploader` (`uploaded_by`);

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
  MODIFY `case_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=240;

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
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
-- AUTO_INCREMENT for table `medical_documents`
--
ALTER TABLE `medical_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `philhealth_records`
--
ALTER TABLE `philhealth_records`
  MODIFY `philhealth_record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `prediction_results`
--
ALTER TABLE `prediction_results`
  MODIFY `prediction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registry_records`
--
ALTER TABLE `registry_records`
  MODIFY `registry_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

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
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_tokens`
--
ALTER TABLE `user_tokens`
  MODIFY `token_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  MODIFY `vaccination_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

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
-- Constraints for table `medical_documents`
--
ALTER TABLE `medical_documents`
  ADD CONSTRAINT `fk_doc_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`),
  ADD CONSTRAINT `fk_doc_case` FOREIGN KEY (`case_id`) REFERENCES `animal_bite_cases` (`case_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_doc_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_doc_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);

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
