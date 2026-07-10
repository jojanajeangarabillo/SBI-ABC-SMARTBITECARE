-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 10, 2026 at 05:18 PM
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
(27, 33, 'SBI-002', 'Cat', 'RIGHT LEG', NULL, 'Alive/Healthy', '2026-07-08', 'Ongoing', NULL, 8, '2026-07-09 02:23:52'),
(44, 50, 'SBI-002', 'Dog', 'Right Arm', NULL, 'Alive/Healthy', '2026-07-09', 'Ongoing', NULL, 16, '2026-07-10 12:37:59');

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
(239, 11, 'SBI-002', 'Login Success: User \'Jean Montero\' - Role: Inventory Officer, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-09 15:11:17'),
(240, 9, 'SBI-002', 'Logout: User \'Marc Beringuela\' (Role: Nurse) (IP: ::1)', 'Login System', '2026-07-09 15:28:10'),
(241, 11, 'SBI-002', 'Logout: User \'Jean Montero\' (Role: Inventory Officer) (IP: ::1)', 'Login System', '2026-07-09 15:28:12'),
(242, 6, 'SBI-002', 'Logout: User \'Jojana Garabillo\' (Role: Branch Admin) (IP: ::1)', 'Login System', '2026-07-09 15:28:14'),
(243, 1, 'SBI-001', 'Logout: User \'superadmin\' (Role: Super Admin) (IP: ::1)', 'Login System', '2026-07-09 15:29:38'),
(244, 1, 'SBI-001', 'Login Success: User \'superadmin\' - Role: Super Admin, Branch: Antipolo Branch (IP: ::1)', 'Login System', '2026-07-10 10:31:40'),
(245, 1, 'SBI-001', 'Viewed Branch Performance Monitoring - Metric: Total Cases, Date: This Month', 'Performance Monitoring', '2026-07-10 10:42:13'),
(246, 6, 'SBI-002', 'Login Failed: User \'Jojana Garabillo\' - Incorrect password (IP: ::1)', 'Login System', '2026-07-10 10:42:54'),
(247, 6, 'SBI-002', 'Login Success: User \'Jojana Garabillo\' - Role: Branch Admin, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-10 10:43:05'),
(248, 6, 'SBI-002', 'Updated branch information: Cainta Branch', 'Settings', '2026-07-10 11:15:47'),
(249, 11, 'SBI-002', 'Login Success: User \'Jean Montero\' - Role: Inventory Officer, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-10 11:18:21'),
(250, 9, 'SBI-002', 'Login Success: User \'Marc Beringuela\' - Role: Nurse, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-10 11:27:59'),
(251, 11, 'SBI-002', 'Updated unit: \'Packs\' → \'Packs\' (ID: 2)', 'Inventory Categories', '2026-07-10 11:37:44'),
(252, 11, 'SBI-002', 'Updated unit: \'Packs\' → \'Packs\' (ID: 2)', 'Inventory Categories', '2026-07-10 11:37:48'),
(253, 11, 'SBI-002', 'Updated inventory item: Rabies Vaccine (Default) (ID: 1) - Changes: Category: 1 → 2', 'Inventory Items', '2026-07-10 11:55:44'),
(254, 11, 'SBI-002', 'Added new unit: vIALS (ID: 4)', 'Inventory Categories', '2026-07-10 11:57:52'),
(255, 11, 'SBI-002', 'Updated unit: \'vIALS\' → \'Vials\' (ID: 4)', 'Inventory Categories', '2026-07-10 11:57:58'),
(256, 11, 'SBI-002', 'Updated inventory item: Rabies Vaccine (Default) (ID: 1) - Changes: Unit: 1 → 4, Min Stock: 10 → 100', 'Inventory Items', '2026-07-10 11:58:16'),
(257, 16, 'SBI-002', 'Login Success: User \'Ella Franco\' - Role: Administrative Staff, Branch: Cainta Branch (IP: ::1)', 'Login System', '2026-07-10 12:08:16'),
(258, 11, 'SBI-002', 'Updated unit: \'Vials\' → \'Vials\' (ID: 4)', 'Inventory Categories', '2026-07-10 12:21:55'),
(259, 11, 'SBI-002', 'Updated unit: \'Vials\' → \'Vial\' (ID: 4)', 'Inventory Categories', '2026-07-10 12:29:49'),
(260, 16, 'SBI-002', 'Updated patient record: Jojane Baglan (Case: 26-0013)', 'Patient Record', '2026-07-10 12:37:59'),
(261, 11, 'SBI-002', 'Updated inventory item: ERIG (ID: 4) - Changes: Unit: 1 → 4', 'Inventory Items', '2026-07-10 12:50:55'),
(262, 11, 'SBI-002', 'Updated inventory item: ATS (ID: 5) - Changes: Unit: 1 → 5', 'Inventory Items', '2026-07-10 12:51:22'),
(263, 11, 'SBI-002', 'Updated inventory item: BETT (ID: 6) - Changes: Unit: 1 → 5', 'Inventory Items', '2026-07-10 12:51:41'),
(264, 11, 'SBI-002', 'Updated inventory item: ABHAYRAB (ID: 3) - Changes: Unit: 1 → 4', 'Inventory Items', '2026-07-10 12:52:29'),
(265, 11, 'SBI-002', 'Updated inventory item: ABHAYTOX (ID: 7) - Changes: Unit: 1 → 4', 'Inventory Items', '2026-07-10 12:52:34'),
(266, 11, 'SBI-002', 'Updated inventory item: SPEEDA (ID: 2) - Changes: Unit: 1 → 4', 'Inventory Items', '2026-07-10 12:52:44'),
(267, 11, 'SBI-002', 'Updated inventory item: ABHAYRAB (ID: 3) - Changes: Category: 1 → 2', 'Inventory Items', '2026-07-10 13:00:19'),
(268, 11, 'SBI-002', 'Updated inventory item: ABHAYTOX (ID: 7) - Changes: Category: 1 → 2', 'Inventory Items', '2026-07-10 13:00:26'),
(269, 11, 'SBI-002', 'Updated inventory item: ABHAYRAB (ID: 3) - Changes: Category: 2 → 4', 'Inventory Items', '2026-07-10 13:00:41'),
(270, 11, 'SBI-002', 'Updated inventory item: ABHAYTOX (ID: 7) - Changes: Category: 2 → 4', 'Inventory Items', '2026-07-10 13:00:47'),
(271, 11, 'SBI-002', 'Updated inventory item: ABHAYRAB (ID: 3) - Changes: Category: 4 → 2', 'Inventory Items', '2026-07-10 13:01:18'),
(272, 11, 'SBI-002', 'Updated inventory item: ABHAYTOX (ID: 7) - Changes: Category: 4 → 2', 'Inventory Items', '2026-07-10 13:01:22'),
(273, 11, 'SBI-002', 'Updated inventory item: ATS (ID: 5) - Changes: Category: 1 → 2', 'Inventory Items', '2026-07-10 13:01:33'),
(274, 11, 'SBI-002', 'Updated inventory item: ERIG (ID: 4) - Changes: Category: 1 → 2', 'Inventory Items', '2026-07-10 13:01:40'),
(275, 11, 'SBI-002', 'Updated inventory item: Rabies Vaccine (Default) (ID: 1) - No changes made', 'Inventory Items', '2026-07-10 13:01:45'),
(276, 11, 'SBI-002', 'Updated inventory item: Rabies Vaccine (Default) (ID: 1) - No changes made', 'Inventory Items', '2026-07-10 13:01:52'),
(277, 11, 'SBI-002', 'Updated inventory item: SPEEDA (ID: 2) - Changes: Category: 1 → 2', 'Inventory Items', '2026-07-10 13:01:58'),
(278, 6, 'SBI-002', 'Imported training dataset: 717 records', 'Prediction Module', '2026-07-10 13:22:50');

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
('SBI-002', 'Cainta Branch', 'Brgy. San Juan Cainta, Rizal', '091234578', 'sbicainta@gmail.com', 'Active', '2026-07-04 08:30:29'),
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
(1, 2, 4, 'Rabies Vaccine (Default)', 100, 'Default vaccine item for vaccination records', 1),
(2, 2, 4, 'SPEEDA', 50, '', 1),
(3, 2, 4, 'ABHAYRAB', 50, '', 1),
(4, 2, 4, 'ERIG', 20, '', 1),
(5, 2, 5, 'ATS', 20, '', 1),
(6, 1, 5, 'BETT', 20, '', 1),
(7, 2, 4, 'ABHAYTOX', 20, '', 1),
(8, 2, 4, 'SPEEDA', 50, 'Rabies vaccine PVRV TRC SPEEDA', 1),
(9, 2, 5, 'BETT', 30, 'Toxoid BETT', 1),
(10, 2, 4, 'ABHAYTOX', 20, 'ABHAYTOX Tetanus Toxoid', 1),
(11, 2, 4, 'ERIG', 30, 'Equine Rabies Immunoglobulin', 1),
(12, 2, 5, 'ATS', 30, 'Anti-Tetanus Serum', 1),
(17, 2, 7, '0.5 CC', 5, '0.5cc Syringe', 0),
(18, 2, 7, '? CC', 5, '0.5cc Syringe', 0),
(19, 2, 7, '1 CC', 5, '1cc Syringe', 0),
(20, 2, 7, '3 CC', 5, '3cc Syringe', 0),
(21, 2, 7, '5CC', 3, '5cc Syringe', 0),
(22, 2, 7, 'G23', 5, 'G23 Needle', 0),
(23, 2, 7, 'G27', 5, 'G27 Needle', 0),
(30, 2, 7, 'FACEMASK', 5, 'Face mask', 0),
(31, 2, 7, 'GLOVES', 5, 'Medical gloves', 0),
(40, 2, 4, 'SPEEDA', 50, 'Rabies vaccine PVRV TRC SPEEDA', 1),
(41, 2, 5, 'BETT', 30, 'Toxoid BETT', 1),
(42, 2, 4, 'ABHAYTOX', 20, 'ABHAYTOX Tetanus Toxoid', 1),
(43, 2, 4, 'ERIG', 30, 'Equine Rabies Immunoglobulin', 1),
(44, 2, 5, 'ATS', 30, 'Anti-Tetanus Serum', 1),
(45, 2, 7, 'AMOXICILLIN CAPSULE', 5, 'Amoxicillin 500mg Capsule', 0),
(46, 2, 8, 'AMOXICILLIN FOR KIDS', 5, 'Amoxicillin for pediatric use', 0),
(47, 2, 8, 'CEFALEXIN FOR KIDS', 5, 'Cefalexin for pediatric use', 0),
(48, 2, 8, 'CETIRIZINE FOR KIDS', 5, 'Cetirizine for pediatric use', 0),
(49, 2, 7, '0.5 CC', 5, '0.5cc Syringe', 0),
(50, 2, 7, '? CC', 5, '0.5cc Syringe', 0),
(51, 2, 7, '1 CC', 5, '1cc Syringe', 0),
(52, 2, 7, '3 CC', 5, '3cc Syringe', 0),
(53, 2, 7, '5CC', 3, '5cc Syringe', 0),
(54, 2, 7, 'G23', 5, 'G23 Needle', 0),
(55, 2, 7, 'G27', 5, 'G27 Needle', 0),
(56, 2, 7, 'GAUZE PAD', 5, 'Gauze pad', 0),
(57, 2, 9, 'COTTON BALLS', 5, 'Cotton balls', 0),
(58, 2, 10, 'BETADINE', 2, 'Betadine antiseptic', 0),
(59, 2, 10, 'ALCOHOL', 2, 'Isopropyl alcohol', 0),
(60, 2, 7, 'MICROPORE', 3, 'Micropore tape', 0),
(61, 2, 8, 'STERILE WATER', 5, 'Sterile water for injection', 0),
(62, 2, 7, 'FACEMASK', 5, 'Face mask', 0),
(63, 2, 7, 'GLOVES', 5, 'Medical gloves', 0),
(64, 2, 6, 'REF THERMOMETER', 2, 'Refrigerator thermometer', 0),
(65, 2, 6, 'Acrylic Containers', 2, 'Acrylic storage containers', 0),
(66, 2, 6, 'Cooler', 2, 'Cooler box', 0),
(67, 2, 6, 'Kidney Basin', 2, 'Kidney basin', 0),
(68, 2, 6, 'Weighing Scale', 2, 'Weighing scale', 0),
(69, 2, 12, 'Forceps', 2, 'Forceps set', 0),
(70, 2, 6, 'Betadine Container', 2, 'Betadine container', 0),
(71, 2, 6, 'Alcohol Pump Container', 2, 'Alcohol pump dispenser', 0);

-- --------------------------------------------------------

--
-- Table structure for table `inventory_stocks`
--

CREATE TABLE `inventory_stocks` (
  `stock_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `branch_id` varchar(10) NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `quantity_available` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `received_date` date DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `low_stock_alert_sent` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `remark` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_stocks`
--

INSERT INTO `inventory_stocks` (`stock_id`, `item_id`, `batch_number`, `branch_id`, `received_by`, `supplier`, `quantity_available`, `unit_cost`, `received_date`, `expiration_date`, `last_updated`, `low_stock_alert_sent`, `is_active`, `remark`) VALUES
(6, 2, 'SPEEDA-2026-001', 'SBI-002', 1, 'DOH Regional Supply', 100, NULL, '2026-01-15', '2026-12-31', '2026-07-10 12:34:15', 0, 1, NULL),
(7, 2, 'SPEEDA-2026-002', 'SBI-002', 1, 'SBI Medical Main Branch', 50, NULL, '2026-06-01', '2027-06-30', '2026-07-10 12:34:15', 0, 1, NULL),
(8, 3, 'ABHAYRAB-2026-001', 'SBI-002', 1, 'DOH Regional Supply', 80, NULL, '2026-02-10', '2026-11-30', '2026-07-10 12:34:15', 0, 1, NULL),
(9, 3, 'ABHAYRAB-2026-002', 'SBI-002', 1, 'SBI Medical Main Branch', 40, NULL, '2026-07-01', '2027-03-31', '2026-07-10 12:34:15', 0, 1, NULL),
(10, 4, 'ERIG-2026-001', 'SBI-002', 1, 'DOH Regional Supply', 30, NULL, '2026-03-20', '2026-10-15', '2026-07-10 12:34:15', 0, 1, NULL),
(11, 4, 'ERIG-2026-002', 'SBI-002', 1, 'SBI Medical Main Branch', 15, NULL, '2026-05-15', '2027-01-31', '2026-07-10 12:34:15', 0, 1, NULL),
(12, 5, 'ATS-2026-001', 'SBI-002', 1, 'DOH Regional Supply', 25, NULL, '2026-02-20', '2026-09-30', '2026-07-10 12:34:15', 0, 1, NULL),
(13, 6, 'BETT-2026-001', 'SBI-002', 1, 'DOH Regional Supply', 20, NULL, '2026-03-10', '2026-08-31', '2026-07-10 12:34:15', 0, 1, NULL),
(14, 7, 'ABHAYTOX-2026-001', 'SBI-002', 1, 'DOH Regional Supply', 20, NULL, '2026-04-01', '2026-12-15', '2026-07-10 12:34:16', 0, 1, NULL);

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
(49, 'Antonio, Luiz', NULL, '0994146223', '2000-10-03', 'Female', '14Saint Catherine Street Rodfer 3 A-prime', '2026-07-09 06:18:46', 'SBI-002'),
(50, 'Jojane Baglan', NULL, '0942358117', '2005-03-23', 'Female', 'Taytay Rizal', '2026-07-10 12:37:59', 'SBI-002');

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
(27, 27, 'No', 'Sponsored', '', NULL, 8, '2026-07-09 10:23:52'),
(31, 44, 'No', NULL, '', NULL, 16, '2026-07-10 20:37:59');

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
(27, 27, '26-0012', 'Alive/Healthy', 0, 0, 0, 'PVRV TRC SPEEDA', 1, 0, 0, 0, 0, 0, 0, '0994146223', NULL, 8, '2026-07-09 10:23:52'),
(31, 44, '26-0013', 'Alive/Healthy', 5, 1, 0, 'PVRV TRC SPEEDA', 0, 0, 0, 0, 0, 0, 0, '0942358117', NULL, 16, '2026-07-10 20:37:59');

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
  `stock_id` int(11) DEFAULT NULL,
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

--
-- Dumping data for table `training_dataset`
--

INSERT INTO `training_dataset` (`training_id`, `item_id`, `branch_id`, `record_date`, `current_stock`, `quantity_used`, `stock_received`, `patient_count`, `low_stock_target`) VALUES
(1, 2, 'SBI-002', '2025-11-14', 50, 13, 161, 86, 0),
(2, 3, 'SBI-002', '2025-11-14', 20, 4, 0, 86, 0),
(3, 4, 'SBI-002', '2025-11-14', 45, 3, 0, 86, 0),
(4, 2, 'SBI-002', '2025-11-15', 197, 2, 0, 17, 0),
(5, 3, 'SBI-002', '2025-11-15', 15, 0, 0, 17, 0),
(6, 4, 'SBI-002', '2025-11-15', 41, 0, 0, 17, 0),
(7, 2, 'SBI-002', '2025-11-16', 195, 4, 0, 24, 0),
(8, 3, 'SBI-002', '2025-11-16', 14, 1, 0, 24, 0),
(9, 4, 'SBI-002', '2025-11-16', 40, 1, 0, 24, 0),
(10, 2, 'SBI-002', '2025-11-17', 191, 9, 0, 73, 0),
(11, 3, 'SBI-002', '2025-11-17', 13, 3, 0, 73, 0),
(12, 4, 'SBI-002', '2025-11-17', 39, 2, 0, 73, 0),
(13, 2, 'SBI-002', '2025-11-18', 181, 14, 110, 87, 0),
(14, 3, 'SBI-002', '2025-11-18', 10, 4, 0, 87, 0),
(15, 4, 'SBI-002', '2025-11-18', 37, 3, 0, 87, 0),
(16, 2, 'SBI-002', '2025-11-19', 277, 7, 183, 56, 0),
(17, 3, 'SBI-002', '2025-11-19', 5, 2, 0, 56, 0),
(18, 4, 'SBI-002', '2025-11-19', 33, 2, 0, 56, 0),
(19, 2, 'SBI-002', '2025-11-20', 453, 4, 0, 31, 0),
(20, 3, 'SBI-002', '2025-11-20', 2, 1, 0, 31, 0),
(21, 4, 'SBI-002', '2025-11-20', 31, 1, 0, 31, 0),
(22, 2, 'SBI-002', '2025-11-21', 448, 9, 0, 57, 0),
(23, 3, 'SBI-002', '2025-11-21', 1, 2, 13, 57, 0),
(24, 4, 'SBI-002', '2025-11-21', 29, 2, 0, 57, 0),
(25, 2, 'SBI-002', '2025-11-22', 439, 15, 0, 97, 0),
(26, 3, 'SBI-002', '2025-11-22', 12, 4, 0, 97, 0),
(27, 4, 'SBI-002', '2025-11-22', 27, 3, 0, 97, 0),
(28, 2, 'SBI-002', '2025-11-23', 424, 2, 0, 18, 0),
(29, 3, 'SBI-002', '2025-11-23', 7, 0, 0, 18, 0),
(30, 4, 'SBI-002', '2025-11-23', 23, 0, 0, 18, 0),
(31, 2, 'SBI-002', '2025-11-24', 422, 10, 0, 64, 0),
(32, 3, 'SBI-002', '2025-11-24', 6, 3, 0, 64, 0),
(33, 4, 'SBI-002', '2025-11-24', 23, 2, 0, 64, 0),
(34, 2, 'SBI-002', '2025-11-25', 411, 14, 193, 88, 0),
(35, 3, 'SBI-002', '2025-11-25', 3, 4, 11, 88, 0),
(36, 4, 'SBI-002', '2025-11-25', 20, 3, 0, 88, 0),
(37, 2, 'SBI-002', '2025-11-26', 591, 7, 0, 51, 0),
(38, 3, 'SBI-002', '2025-11-26', 10, 2, 0, 51, 0),
(39, 4, 'SBI-002', '2025-11-26', 16, 2, 0, 51, 0),
(40, 2, 'SBI-002', '2025-11-27', 583, 7, 0, 52, 0),
(41, 3, 'SBI-002', '2025-11-27', 7, 2, 0, 52, 0),
(42, 4, 'SBI-002', '2025-11-27', 14, 2, 0, 52, 0),
(43, 2, 'SBI-002', '2025-11-28', 576, 13, 0, 85, 0),
(44, 3, 'SBI-002', '2025-11-28', 5, 4, 0, 85, 1),
(45, 4, 'SBI-002', '2025-11-28', 12, 3, 0, 85, 0),
(46, 2, 'SBI-002', '2025-11-29', 562, 6, 0, 44, 0),
(47, 3, 'SBI-002', '2025-11-29', 1, 2, 15, 44, 0),
(48, 4, 'SBI-002', '2025-11-29', 9, 1, 0, 44, 0),
(49, 2, 'SBI-002', '2025-11-30', 556, 5, 0, 37, 0),
(50, 3, 'SBI-002', '2025-11-30', 14, 1, 0, 37, 0),
(51, 4, 'SBI-002', '2025-11-30', 7, 1, 0, 37, 0),
(52, 2, 'SBI-002', '2025-12-01', 550, 1, 0, 13, 0),
(53, 3, 'SBI-002', '2025-12-01', 12, 0, 0, 13, 0),
(54, 4, 'SBI-002', '2025-12-01', 6, 0, 0, 13, 0),
(55, 2, 'SBI-002', '2025-12-02', 548, 4, 167, 24, 0),
(56, 3, 'SBI-002', '2025-12-02', 11, 1, 0, 24, 0),
(57, 4, 'SBI-002', '2025-12-02', 5, 0, 16, 24, 0),
(58, 2, 'SBI-002', '2025-12-03', 711, 6, 0, 41, 0),
(59, 3, 'SBI-002', '2025-12-03', 10, 2, 0, 41, 0),
(60, 4, 'SBI-002', '2025-12-03', 21, 1, 0, 41, 0),
(61, 2, 'SBI-002', '2025-12-04', 705, 14, 186, 98, 0),
(62, 3, 'SBI-002', '2025-12-04', 8, 5, 0, 98, 0),
(63, 4, 'SBI-002', '2025-12-04', 19, 3, 0, 98, 0),
(64, 2, 'SBI-002', '2025-12-05', 877, 12, 0, 94, 0),
(65, 3, 'SBI-002', '2025-12-05', 3, 4, 10, 94, 0),
(66, 4, 'SBI-002', '2025-12-05', 15, 3, 0, 94, 0),
(67, 2, 'SBI-002', '2025-12-06', 865, 4, 164, 31, 0),
(68, 3, 'SBI-002', '2025-12-06', 9, 1, 0, 31, 0),
(69, 4, 'SBI-002', '2025-12-06', 12, 1, 0, 31, 0),
(70, 2, 'SBI-002', '2025-12-07', 1025, 3, 91, 23, 0),
(71, 3, 'SBI-002', '2025-12-07', 7, 1, 0, 23, 0),
(72, 4, 'SBI-002', '2025-12-07', 10, 0, 0, 23, 0),
(73, 2, 'SBI-002', '2025-12-08', 1113, 7, 126, 50, 0),
(74, 3, 'SBI-002', '2025-12-08', 6, 2, 0, 50, 0),
(75, 4, 'SBI-002', '2025-12-08', 10, 2, 0, 50, 0),
(76, 2, 'SBI-002', '2025-12-09', 1231, 13, 0, 89, 0),
(77, 3, 'SBI-002', '2025-12-09', 3, 4, 18, 89, 0),
(78, 4, 'SBI-002', '2025-12-09', 8, 3, 0, 89, 0),
(79, 2, 'SBI-002', '2025-12-10', 1217, 13, 0, 100, 0),
(80, 3, 'SBI-002', '2025-12-10', 18, 5, 0, 100, 0),
(81, 4, 'SBI-002', '2025-12-10', 4, 4, 0, 100, 1),
(82, 2, 'SBI-002', '2025-12-11', 1204, 2, 0, 16, 0),
(83, 3, 'SBI-002', '2025-12-11', 12, 0, 0, 16, 0),
(84, 4, 'SBI-002', '2025-12-11', 0, 0, 6, 16, 0),
(85, 2, 'SBI-002', '2025-12-12', 1202, 16, 0, 94, 0),
(86, 3, 'SBI-002', '2025-12-12', 12, 4, 0, 94, 0),
(87, 4, 'SBI-002', '2025-12-12', 6, 3, 0, 94, 0),
(88, 2, 'SBI-002', '2025-12-13', 1186, 8, 0, 62, 0),
(89, 3, 'SBI-002', '2025-12-13', 7, 3, 0, 62, 0),
(90, 4, 'SBI-002', '2025-12-13', 2, 2, 11, 62, 0),
(91, 2, 'SBI-002', '2025-12-14', 1177, 9, 0, 65, 0),
(92, 3, 'SBI-002', '2025-12-14', 4, 3, 0, 65, 0),
(93, 4, 'SBI-002', '2025-12-14', 11, 2, 11, 65, 0),
(94, 2, 'SBI-002', '2025-12-15', 1168, 7, 0, 48, 0),
(95, 3, 'SBI-002', '2025-12-15', 1, 2, 20, 48, 0),
(96, 4, 'SBI-002', '2025-12-15', 20, 1, 0, 48, 0),
(97, 2, 'SBI-002', '2025-12-16', 1160, 13, 0, 82, 0),
(98, 3, 'SBI-002', '2025-12-16', 19, 4, 0, 82, 0),
(99, 4, 'SBI-002', '2025-12-16', 18, 3, 0, 82, 0),
(100, 2, 'SBI-002', '2025-12-17', 1147, 6, 0, 41, 0),
(101, 3, 'SBI-002', '2025-12-17', 14, 2, 0, 41, 0),
(102, 4, 'SBI-002', '2025-12-17', 14, 1, 13, 41, 0),
(103, 2, 'SBI-002', '2025-12-18', 1140, 8, 0, 60, 0),
(104, 3, 'SBI-002', '2025-12-18', 12, 3, 0, 60, 0),
(105, 4, 'SBI-002', '2025-12-18', 26, 2, 0, 60, 0),
(106, 2, 'SBI-002', '2025-12-19', 1131, 2, 0, 14, 0),
(107, 3, 'SBI-002', '2025-12-19', 9, 0, 0, 14, 0),
(108, 4, 'SBI-002', '2025-12-19', 24, 0, 0, 14, 0),
(109, 2, 'SBI-002', '2025-12-20', 1129, 0, 0, 6, 0),
(110, 3, 'SBI-002', '2025-12-20', 9, 0, 0, 6, 0),
(111, 4, 'SBI-002', '2025-12-20', 23, 0, 0, 6, 0),
(112, 2, 'SBI-002', '2025-12-21', 1128, 6, 0, 43, 0),
(113, 3, 'SBI-002', '2025-12-21', 8, 2, 23, 43, 0),
(114, 4, 'SBI-002', '2025-12-21', 23, 1, 0, 43, 0),
(115, 2, 'SBI-002', '2025-12-22', 1122, 10, 0, 63, 0),
(116, 3, 'SBI-002', '2025-12-22', 29, 3, 0, 63, 0),
(117, 4, 'SBI-002', '2025-12-22', 21, 2, 0, 63, 0),
(118, 2, 'SBI-002', '2025-12-23', 1111, 5, 0, 35, 0),
(119, 3, 'SBI-002', '2025-12-23', 26, 1, 0, 35, 0),
(120, 4, 'SBI-002', '2025-12-23', 19, 1, 18, 35, 0),
(121, 2, 'SBI-002', '2025-12-24', 1106, 10, 0, 69, 0),
(122, 3, 'SBI-002', '2025-12-24', 25, 3, 0, 69, 0),
(123, 4, 'SBI-002', '2025-12-24', 36, 2, 0, 69, 0),
(124, 2, 'SBI-002', '2025-12-25', 1096, 9, 0, 66, 0),
(125, 3, 'SBI-002', '2025-12-25', 21, 3, 0, 66, 0),
(126, 4, 'SBI-002', '2025-12-25', 33, 2, 0, 66, 0),
(127, 2, 'SBI-002', '2025-12-26', 1086, 2, 0, 13, 0),
(128, 3, 'SBI-002', '2025-12-26', 18, 0, 0, 13, 0),
(129, 4, 'SBI-002', '2025-12-26', 30, 0, 0, 13, 0),
(130, 2, 'SBI-002', '2025-12-27', 1084, 8, 0, 62, 0),
(131, 3, 'SBI-002', '2025-12-27', 17, 3, 0, 62, 0),
(132, 4, 'SBI-002', '2025-12-27', 30, 2, 0, 62, 0),
(133, 2, 'SBI-002', '2025-12-28', 1075, 9, 0, 76, 0),
(134, 3, 'SBI-002', '2025-12-28', 14, 3, 0, 76, 0),
(135, 4, 'SBI-002', '2025-12-28', 27, 2, 0, 76, 0),
(136, 2, 'SBI-002', '2025-12-29', 1065, 13, 0, 97, 0),
(137, 3, 'SBI-002', '2025-12-29', 10, 5, 0, 97, 0),
(138, 4, 'SBI-002', '2025-12-29', 24, 3, 0, 97, 0),
(139, 2, 'SBI-002', '2025-12-30', 1052, 8, 129, 57, 0),
(140, 3, 'SBI-002', '2025-12-30', 5, 2, 0, 57, 0),
(141, 4, 'SBI-002', '2025-12-30', 21, 2, 0, 57, 0),
(142, 2, 'SBI-002', '2025-12-31', 1173, 13, 0, 92, 0),
(143, 3, 'SBI-002', '2025-12-31', 3, 4, 7, 92, 0),
(144, 4, 'SBI-002', '2025-12-31', 18, 3, 14, 92, 0),
(145, 2, 'SBI-002', '2026-01-01', 1160, 3, 0, 24, 0),
(146, 3, 'SBI-002', '2026-01-01', 6, 1, 0, 24, 0),
(147, 4, 'SBI-002', '2026-01-01', 29, 1, 0, 24, 0),
(148, 2, 'SBI-002', '2026-01-02', 1156, 9, 0, 60, 0),
(149, 3, 'SBI-002', '2026-01-02', 4, 2, 0, 60, 0),
(150, 4, 'SBI-002', '2026-01-02', 28, 2, 0, 60, 0),
(151, 2, 'SBI-002', '2026-01-03', 1147, 9, 0, 56, 0),
(152, 3, 'SBI-002', '2026-01-03', 1, 2, 18, 56, 0),
(153, 4, 'SBI-002', '2026-01-03', 25, 2, 0, 56, 0),
(154, 2, 'SBI-002', '2026-01-04', 1138, 9, 0, 61, 0),
(155, 3, 'SBI-002', '2026-01-04', 18, 3, 0, 61, 0),
(156, 4, 'SBI-002', '2026-01-04', 23, 2, 16, 61, 0),
(157, 2, 'SBI-002', '2026-01-05', 1128, 14, 0, 91, 0),
(158, 3, 'SBI-002', '2026-01-05', 14, 4, 0, 91, 0),
(159, 4, 'SBI-002', '2026-01-05', 38, 3, 0, 91, 0),
(160, 2, 'SBI-002', '2026-01-06', 1114, 11, 0, 74, 0),
(161, 3, 'SBI-002', '2026-01-06', 10, 3, 0, 74, 0),
(162, 4, 'SBI-002', '2026-01-06', 34, 3, 0, 74, 0),
(163, 2, 'SBI-002', '2026-01-07', 1103, 5, 0, 40, 0),
(164, 3, 'SBI-002', '2026-01-07', 6, 2, 0, 40, 0),
(165, 4, 'SBI-002', '2026-01-07', 31, 1, 0, 40, 0),
(166, 2, 'SBI-002', '2026-01-08', 1097, 1, 143, 11, 0),
(167, 3, 'SBI-002', '2026-01-08', 4, 0, 0, 11, 0),
(168, 4, 'SBI-002', '2026-01-08', 29, 0, 0, 11, 0),
(169, 2, 'SBI-002', '2026-01-09', 1239, 13, 0, 87, 0),
(170, 3, 'SBI-002', '2026-01-09', 4, 4, 5, 87, 0),
(171, 4, 'SBI-002', '2026-01-09', 29, 3, 0, 87, 0),
(172, 2, 'SBI-002', '2026-01-10', 1226, 3, 0, 21, 0),
(173, 3, 'SBI-002', '2026-01-10', 5, 1, 0, 21, 0),
(174, 4, 'SBI-002', '2026-01-10', 25, 0, 0, 21, 0),
(175, 2, 'SBI-002', '2026-01-11', 1222, 3, 156, 27, 0),
(176, 3, 'SBI-002', '2026-01-11', 3, 1, 0, 27, 0),
(177, 4, 'SBI-002', '2026-01-11', 24, 1, 0, 27, 0),
(178, 2, 'SBI-002', '2026-01-12', 1375, 7, 0, 58, 0),
(179, 3, 'SBI-002', '2026-01-12', 2, 3, 7, 58, 0),
(180, 4, 'SBI-002', '2026-01-12', 23, 2, 0, 58, 0),
(181, 2, 'SBI-002', '2026-01-13', 1367, 3, 0, 23, 0),
(182, 3, 'SBI-002', '2026-01-13', 7, 1, 46, 23, 0),
(183, 4, 'SBI-002', '2026-01-13', 21, 0, 0, 23, 0),
(184, 2, 'SBI-002', '2026-01-14', 1363, 5, 0, 36, 0),
(185, 3, 'SBI-002', '2026-01-14', 52, 1, 0, 36, 0),
(186, 4, 'SBI-002', '2026-01-14', 20, 1, 0, 36, 0),
(187, 2, 'SBI-002', '2026-01-15', 1358, 12, 0, 83, 0),
(188, 3, 'SBI-002', '2026-01-15', 50, 4, 0, 83, 0),
(189, 4, 'SBI-002', '2026-01-15', 19, 3, 15, 83, 0),
(190, 2, 'SBI-002', '2026-01-16', 1345, 4, 0, 27, 0),
(191, 3, 'SBI-002', '2026-01-16', 46, 1, 0, 27, 0),
(192, 4, 'SBI-002', '2026-01-16', 31, 1, 0, 27, 0),
(193, 2, 'SBI-002', '2026-01-17', 1341, 14, 0, 95, 0),
(194, 3, 'SBI-002', '2026-01-17', 45, 4, 0, 95, 0),
(195, 4, 'SBI-002', '2026-01-17', 30, 3, 0, 95, 0),
(196, 2, 'SBI-002', '2026-01-18', 1326, 2, 0, 17, 0),
(197, 3, 'SBI-002', '2026-01-18', 40, 0, 0, 17, 0),
(198, 4, 'SBI-002', '2026-01-18', 26, 0, 0, 17, 0),
(199, 2, 'SBI-002', '2026-01-19', 1324, 4, 0, 31, 0),
(200, 3, 'SBI-002', '2026-01-19', 39, 1, 0, 31, 0),
(201, 4, 'SBI-002', '2026-01-19', 26, 1, 0, 31, 0),
(202, 2, 'SBI-002', '2026-01-20', 1319, 3, 0, 27, 0),
(203, 3, 'SBI-002', '2026-01-20', 38, 1, 0, 27, 0),
(204, 4, 'SBI-002', '2026-01-20', 24, 1, 11, 27, 0),
(205, 2, 'SBI-002', '2026-01-21', 1316, 8, 0, 55, 0),
(206, 3, 'SBI-002', '2026-01-21', 36, 2, 0, 55, 0),
(207, 4, 'SBI-002', '2026-01-21', 35, 2, 0, 55, 0),
(208, 2, 'SBI-002', '2026-01-22', 1308, 10, 0, 69, 0),
(209, 3, 'SBI-002', '2026-01-22', 34, 3, 0, 69, 0),
(210, 4, 'SBI-002', '2026-01-22', 33, 2, 0, 69, 0),
(211, 2, 'SBI-002', '2026-01-23', 1297, 12, 0, 84, 0),
(212, 3, 'SBI-002', '2026-01-23', 30, 4, 0, 84, 0),
(213, 4, 'SBI-002', '2026-01-23', 30, 3, 0, 84, 0),
(214, 2, 'SBI-002', '2026-01-24', 1285, 13, 0, 89, 0),
(215, 3, 'SBI-002', '2026-01-24', 26, 4, 0, 89, 0),
(216, 4, 'SBI-002', '2026-01-24', 27, 3, 0, 89, 0),
(217, 2, 'SBI-002', '2026-01-25', 1272, 0, 0, 5, 0),
(218, 3, 'SBI-002', '2026-01-25', 22, 0, 0, 5, 0),
(219, 4, 'SBI-002', '2026-01-25', 23, 0, 0, 5, 0),
(220, 2, 'SBI-002', '2026-01-26', 1271, 3, 0, 21, 0),
(221, 3, 'SBI-002', '2026-01-26', 21, 1, 30, 21, 0),
(222, 4, 'SBI-002', '2026-01-26', 23, 0, 0, 21, 0),
(223, 2, 'SBI-002', '2026-01-27', 1268, 9, 0, 66, 0),
(224, 3, 'SBI-002', '2026-01-27', 51, 3, 36, 66, 0),
(225, 4, 'SBI-002', '2026-01-27', 22, 2, 0, 66, 0),
(226, 2, 'SBI-002', '2026-01-28', 1258, 3, 0, 21, 0),
(227, 3, 'SBI-002', '2026-01-28', 84, 1, 47, 21, 0),
(228, 4, 'SBI-002', '2026-01-28', 19, 0, 0, 21, 0),
(229, 2, 'SBI-002', '2026-01-29', 1254, 6, 0, 47, 0),
(230, 3, 'SBI-002', '2026-01-29', 131, 2, 18, 47, 0),
(231, 4, 'SBI-002', '2026-01-29', 18, 1, 0, 47, 0),
(232, 2, 'SBI-002', '2026-01-30', 1247, 3, 0, 26, 0),
(233, 3, 'SBI-002', '2026-01-30', 147, 1, 0, 26, 0),
(234, 4, 'SBI-002', '2026-01-30', 17, 1, 0, 26, 0),
(235, 2, 'SBI-002', '2026-01-31', 1244, 7, 0, 48, 0),
(236, 3, 'SBI-002', '2026-01-31', 146, 2, 0, 48, 0),
(237, 4, 'SBI-002', '2026-01-31', 16, 1, 0, 48, 0),
(238, 2, 'SBI-002', '2026-02-01', 1237, 6, 0, 41, 0),
(239, 3, 'SBI-002', '2026-02-01', 144, 2, 0, 41, 0),
(240, 4, 'SBI-002', '2026-02-01', 14, 1, 0, 41, 0),
(241, 2, 'SBI-002', '2026-02-02', 1230, 2, 0, 20, 0),
(242, 3, 'SBI-002', '2026-02-02', 141, 1, 0, 20, 0),
(243, 4, 'SBI-002', '2026-02-02', 12, 0, 0, 20, 0),
(244, 2, 'SBI-002', '2026-02-03', 1227, 2, 0, 19, 0),
(245, 3, 'SBI-002', '2026-02-03', 140, 0, 0, 19, 0),
(246, 4, 'SBI-002', '2026-02-03', 11, 0, 0, 19, 0),
(247, 2, 'SBI-002', '2026-02-04', 1224, 11, 0, 87, 0),
(248, 3, 'SBI-002', '2026-02-04', 140, 4, 39, 87, 0),
(249, 4, 'SBI-002', '2026-02-04', 10, 3, 0, 87, 0),
(250, 2, 'SBI-002', '2026-02-05', 1213, 11, 0, 75, 0),
(251, 3, 'SBI-002', '2026-02-05', 175, 3, 0, 75, 0),
(252, 4, 'SBI-002', '2026-02-05', 7, 3, 0, 75, 0),
(253, 2, 'SBI-002', '2026-02-06', 1202, 2, 0, 14, 0),
(254, 3, 'SBI-002', '2026-02-06', 171, 0, 0, 14, 0),
(255, 4, 'SBI-002', '2026-02-06', 4, 0, 0, 14, 0),
(256, 2, 'SBI-002', '2026-02-07', 1200, 1, 0, 10, 0),
(257, 3, 'SBI-002', '2026-02-07', 171, 0, 0, 10, 0),
(258, 4, 'SBI-002', '2026-02-07', 3, 0, 0, 10, 0),
(259, 2, 'SBI-002', '2026-02-08', 1198, 12, 0, 85, 0),
(260, 3, 'SBI-002', '2026-02-08', 170, 4, 0, 85, 0),
(261, 4, 'SBI-002', '2026-02-08', 3, 3, 17, 85, 0),
(262, 2, 'SBI-002', '2026-02-09', 1186, 13, 0, 82, 0),
(263, 3, 'SBI-002', '2026-02-09', 166, 4, 47, 82, 0),
(264, 4, 'SBI-002', '2026-02-09', 17, 3, 0, 82, 0),
(265, 2, 'SBI-002', '2026-02-10', 1172, 10, 0, 76, 0),
(266, 3, 'SBI-002', '2026-02-10', 209, 3, 0, 76, 0),
(267, 4, 'SBI-002', '2026-02-10', 13, 3, 0, 76, 0),
(268, 2, 'SBI-002', '2026-02-11', 1162, 12, 0, 83, 0),
(269, 3, 'SBI-002', '2026-02-11', 205, 4, 19, 83, 0),
(270, 4, 'SBI-002', '2026-02-11', 10, 3, 0, 83, 0),
(271, 2, 'SBI-002', '2026-02-12', 1150, 5, 145, 38, 0),
(272, 3, 'SBI-002', '2026-02-12', 221, 1, 0, 38, 0),
(273, 4, 'SBI-002', '2026-02-12', 7, 1, 0, 38, 0),
(274, 2, 'SBI-002', '2026-02-13', 1289, 5, 0, 40, 0),
(275, 3, 'SBI-002', '2026-02-13', 219, 1, 0, 40, 0),
(276, 4, 'SBI-002', '2026-02-13', 5, 1, 0, 40, 0),
(277, 2, 'SBI-002', '2026-02-14', 1284, 7, 0, 53, 0),
(278, 3, 'SBI-002', '2026-02-14', 217, 2, 0, 53, 0),
(279, 4, 'SBI-002', '2026-02-14', 4, 2, 18, 53, 0),
(280, 2, 'SBI-002', '2026-02-15', 1276, 3, 0, 26, 0),
(281, 3, 'SBI-002', '2026-02-15', 214, 1, 0, 26, 0),
(282, 4, 'SBI-002', '2026-02-15', 20, 1, 0, 26, 0),
(283, 2, 'SBI-002', '2026-02-16', 1272, 3, 175, 21, 0),
(284, 3, 'SBI-002', '2026-02-16', 213, 1, 36, 21, 0),
(285, 4, 'SBI-002', '2026-02-16', 19, 0, 0, 21, 0),
(286, 2, 'SBI-002', '2026-02-17', 1445, 16, 0, 99, 0),
(287, 3, 'SBI-002', '2026-02-17', 248, 5, 0, 99, 0),
(288, 4, 'SBI-002', '2026-02-17', 18, 4, 0, 99, 0),
(289, 2, 'SBI-002', '2026-02-18', 1429, 2, 0, 14, 0),
(290, 3, 'SBI-002', '2026-02-18', 243, 0, 0, 14, 0),
(291, 4, 'SBI-002', '2026-02-18', 14, 0, 0, 14, 0),
(292, 2, 'SBI-002', '2026-02-19', 1427, 2, 0, 16, 0),
(293, 3, 'SBI-002', '2026-02-19', 243, 0, 0, 16, 0),
(294, 4, 'SBI-002', '2026-02-19', 14, 0, 0, 16, 0),
(295, 2, 'SBI-002', '2026-02-20', 1424, 12, 0, 84, 0),
(296, 3, 'SBI-002', '2026-02-20', 242, 4, 0, 84, 0),
(297, 4, 'SBI-002', '2026-02-20', 13, 3, 0, 84, 0),
(298, 2, 'SBI-002', '2026-02-21', 1412, 12, 0, 83, 0),
(299, 3, 'SBI-002', '2026-02-21', 238, 4, 12, 83, 0),
(300, 4, 'SBI-002', '2026-02-21', 10, 3, 0, 83, 0),
(301, 2, 'SBI-002', '2026-02-22', 1400, 8, 0, 62, 0),
(302, 3, 'SBI-002', '2026-02-22', 246, 3, 0, 62, 0),
(303, 4, 'SBI-002', '2026-02-22', 7, 2, 0, 62, 0),
(304, 2, 'SBI-002', '2026-02-23', 1391, 10, 0, 60, 0),
(305, 3, 'SBI-002', '2026-02-23', 243, 3, 0, 60, 0),
(306, 4, 'SBI-002', '2026-02-23', 4, 2, 0, 60, 0),
(307, 2, 'SBI-002', '2026-02-24', 1381, 6, 0, 49, 0),
(308, 3, 'SBI-002', '2026-02-24', 240, 2, 0, 49, 0),
(309, 4, 'SBI-002', '2026-02-24', 2, 2, 0, 49, 1),
(310, 2, 'SBI-002', '2026-02-25', 1374, 15, 0, 94, 0),
(311, 3, 'SBI-002', '2026-02-25', 238, 4, 36, 94, 0),
(312, 4, 'SBI-002', '2026-02-25', 0, 3, 19, 94, 0),
(313, 2, 'SBI-002', '2026-02-26', 1359, 8, 0, 59, 0),
(314, 3, 'SBI-002', '2026-02-26', 269, 3, 0, 59, 0),
(315, 4, 'SBI-002', '2026-02-26', 16, 2, 0, 59, 0),
(316, 2, 'SBI-002', '2026-02-27', 1350, 4, 0, 34, 0),
(317, 3, 'SBI-002', '2026-02-27', 266, 1, 0, 34, 0),
(318, 4, 'SBI-002', '2026-02-27', 13, 1, 0, 34, 0),
(319, 2, 'SBI-002', '2026-02-28', 1345, 11, 0, 76, 0),
(320, 3, 'SBI-002', '2026-02-28', 265, 3, 0, 76, 0),
(321, 4, 'SBI-002', '2026-02-28', 12, 2, 0, 76, 0),
(322, 2, 'SBI-002', '2026-03-01', 1334, 3, 0, 25, 0),
(323, 3, 'SBI-002', '2026-03-01', 261, 1, 0, 25, 0),
(324, 4, 'SBI-002', '2026-03-01', 9, 1, 0, 25, 0),
(325, 2, 'SBI-002', '2026-03-02', 1331, 1, 199, 11, 0),
(326, 3, 'SBI-002', '2026-03-02', 260, 0, 0, 11, 0),
(327, 4, 'SBI-002', '2026-03-02', 8, 0, 0, 11, 0),
(328, 2, 'SBI-002', '2026-03-03', 1528, 5, 0, 34, 0),
(329, 3, 'SBI-002', '2026-03-03', 259, 1, 0, 34, 0),
(330, 4, 'SBI-002', '2026-03-03', 8, 1, 0, 34, 0),
(331, 2, 'SBI-002', '2026-03-04', 1523, 1, 0, 7, 0),
(332, 3, 'SBI-002', '2026-03-04', 258, 0, 0, 7, 0),
(333, 4, 'SBI-002', '2026-03-04', 6, 0, 0, 7, 0),
(334, 2, 'SBI-002', '2026-03-05', 1522, 3, 0, 21, 0),
(335, 3, 'SBI-002', '2026-03-05', 257, 1, 25, 21, 0),
(336, 4, 'SBI-002', '2026-03-05', 6, 0, 0, 21, 0),
(337, 2, 'SBI-002', '2026-03-06', 1518, 12, 0, 81, 0),
(338, 3, 'SBI-002', '2026-03-06', 282, 3, 0, 81, 0),
(339, 4, 'SBI-002', '2026-03-06', 5, 3, 0, 81, 0),
(340, 2, 'SBI-002', '2026-03-07', 1506, 2, 0, 14, 0),
(341, 3, 'SBI-002', '2026-03-07', 278, 0, 0, 14, 0),
(342, 4, 'SBI-002', '2026-03-07', 2, 0, 0, 14, 0),
(343, 2, 'SBI-002', '2026-03-08', 1504, 4, 0, 30, 0),
(344, 3, 'SBI-002', '2026-03-08', 277, 1, 0, 30, 0),
(345, 4, 'SBI-002', '2026-03-08', 1, 1, 0, 30, 0),
(346, 2, 'SBI-002', '2026-03-09', 1499, 3, 146, 22, 0),
(347, 3, 'SBI-002', '2026-03-09', 276, 1, 0, 22, 0),
(348, 4, 'SBI-002', '2026-03-09', 0, 0, 18, 22, 0),
(349, 2, 'SBI-002', '2026-03-10', 1642, 0, 0, 6, 0),
(350, 3, 'SBI-002', '2026-03-10', 274, 0, 0, 6, 0),
(351, 4, 'SBI-002', '2026-03-10', 18, 0, 0, 6, 0),
(352, 2, 'SBI-002', '2026-03-11', 1641, 3, 0, 24, 0),
(353, 3, 'SBI-002', '2026-03-11', 274, 1, 0, 24, 0),
(354, 4, 'SBI-002', '2026-03-11', 17, 1, 0, 24, 0),
(355, 2, 'SBI-002', '2026-03-12', 1638, 15, 0, 91, 0),
(356, 3, 'SBI-002', '2026-03-12', 273, 4, 0, 91, 0),
(357, 4, 'SBI-002', '2026-03-12', 16, 3, 0, 91, 0),
(358, 2, 'SBI-002', '2026-03-13', 1623, 4, 0, 25, 0),
(359, 3, 'SBI-002', '2026-03-13', 269, 1, 0, 25, 0),
(360, 4, 'SBI-002', '2026-03-13', 13, 1, 0, 25, 0),
(361, 2, 'SBI-002', '2026-03-14', 1619, 8, 0, 50, 0),
(362, 3, 'SBI-002', '2026-03-14', 267, 2, 0, 50, 0),
(363, 4, 'SBI-002', '2026-03-14', 12, 2, 0, 50, 0),
(364, 2, 'SBI-002', '2026-03-15', 1610, 1, 71, 14, 0),
(365, 3, 'SBI-002', '2026-03-15', 265, 0, 0, 14, 0),
(366, 4, 'SBI-002', '2026-03-15', 10, 0, 24, 14, 0),
(367, 2, 'SBI-002', '2026-03-16', 1679, 0, 188, 5, 0),
(368, 3, 'SBI-002', '2026-03-16', 264, 0, 0, 5, 0),
(369, 4, 'SBI-002', '2026-03-16', 33, 0, 0, 5, 0),
(370, 2, 'SBI-002', '2026-03-17', 1868, 5, 0, 37, 0),
(371, 3, 'SBI-002', '2026-03-17', 264, 1, 0, 37, 0),
(372, 4, 'SBI-002', '2026-03-17', 33, 1, 0, 37, 0),
(373, 2, 'SBI-002', '2026-03-18', 1862, 6, 0, 42, 0),
(374, 3, 'SBI-002', '2026-03-18', 262, 2, 0, 42, 0),
(375, 4, 'SBI-002', '2026-03-18', 31, 1, 0, 42, 0),
(376, 2, 'SBI-002', '2026-03-19', 1856, 11, 0, 78, 0),
(377, 3, 'SBI-002', '2026-03-19', 260, 3, 28, 78, 0),
(378, 4, 'SBI-002', '2026-03-19', 30, 3, 0, 78, 0),
(379, 2, 'SBI-002', '2026-03-20', 1844, 4, 0, 33, 0),
(380, 3, 'SBI-002', '2026-03-20', 284, 1, 0, 33, 0),
(381, 4, 'SBI-002', '2026-03-20', 27, 1, 17, 33, 0),
(382, 2, 'SBI-002', '2026-03-21', 1840, 6, 0, 47, 0),
(383, 3, 'SBI-002', '2026-03-21', 282, 2, 0, 47, 0),
(384, 4, 'SBI-002', '2026-03-21', 43, 1, 0, 47, 0),
(385, 2, 'SBI-002', '2026-03-22', 1833, 3, 0, 23, 0),
(386, 3, 'SBI-002', '2026-03-22', 280, 1, 0, 23, 0),
(387, 4, 'SBI-002', '2026-03-22', 41, 0, 0, 23, 0),
(388, 2, 'SBI-002', '2026-03-23', 1829, 9, 0, 59, 0),
(389, 3, 'SBI-002', '2026-03-23', 279, 3, 0, 59, 0),
(390, 4, 'SBI-002', '2026-03-23', 40, 2, 0, 59, 0),
(391, 2, 'SBI-002', '2026-03-24', 1820, 9, 149, 65, 0),
(392, 3, 'SBI-002', '2026-03-24', 276, 3, 0, 65, 0),
(393, 4, 'SBI-002', '2026-03-24', 38, 2, 0, 65, 0),
(394, 2, 'SBI-002', '2026-03-25', 1960, 6, 0, 50, 0),
(395, 3, 'SBI-002', '2026-03-25', 273, 2, 30, 50, 0),
(396, 4, 'SBI-002', '2026-03-25', 35, 2, 0, 50, 0),
(397, 2, 'SBI-002', '2026-03-26', 1953, 8, 66, 60, 0),
(398, 3, 'SBI-002', '2026-03-26', 301, 3, 0, 60, 0),
(399, 4, 'SBI-002', '2026-03-26', 33, 2, 0, 60, 0),
(400, 2, 'SBI-002', '2026-03-27', 2011, 7, 197, 57, 0),
(401, 3, 'SBI-002', '2026-03-27', 298, 2, 0, 57, 0),
(402, 4, 'SBI-002', '2026-03-27', 31, 2, 0, 57, 0),
(403, 2, 'SBI-002', '2026-03-28', 2201, 15, 0, 97, 0),
(404, 3, 'SBI-002', '2026-03-28', 295, 4, 0, 97, 0),
(405, 4, 'SBI-002', '2026-03-28', 28, 3, 0, 97, 0),
(406, 2, 'SBI-002', '2026-03-29', 2186, 9, 0, 53, 0),
(407, 3, 'SBI-002', '2026-03-29', 290, 2, 17, 53, 0),
(408, 4, 'SBI-002', '2026-03-29', 25, 2, 0, 53, 0),
(409, 2, 'SBI-002', '2026-03-30', 2176, 2, 0, 19, 0),
(410, 3, 'SBI-002', '2026-03-30', 305, 1, 23, 19, 0),
(411, 4, 'SBI-002', '2026-03-30', 23, 0, 0, 19, 0),
(412, 2, 'SBI-002', '2026-03-31', 2173, 2, 0, 14, 0),
(413, 3, 'SBI-002', '2026-03-31', 327, 0, 0, 14, 0),
(414, 4, 'SBI-002', '2026-03-31', 22, 0, 0, 14, 0),
(415, 2, 'SBI-002', '2026-04-01', 2171, 9, 0, 56, 0),
(416, 3, 'SBI-002', '2026-04-01', 326, 2, 0, 56, 0),
(417, 4, 'SBI-002', '2026-04-01', 21, 2, 0, 56, 0),
(418, 2, 'SBI-002', '2026-04-02', 2162, 15, 0, 100, 0),
(419, 3, 'SBI-002', '2026-04-02', 323, 5, 0, 100, 0),
(420, 4, 'SBI-002', '2026-04-02', 19, 4, 0, 100, 0),
(421, 2, 'SBI-002', '2026-04-03', 2146, 5, 0, 35, 0),
(422, 3, 'SBI-002', '2026-04-03', 318, 1, 0, 35, 0),
(423, 4, 'SBI-002', '2026-04-03', 15, 1, 0, 35, 0),
(424, 2, 'SBI-002', '2026-04-04', 2141, 14, 76, 99, 0),
(425, 3, 'SBI-002', '2026-04-04', 317, 5, 0, 99, 0),
(426, 4, 'SBI-002', '2026-04-04', 14, 4, 17, 99, 0),
(427, 2, 'SBI-002', '2026-04-05', 2203, 3, 196, 23, 0),
(428, 3, 'SBI-002', '2026-04-05', 312, 1, 0, 23, 0),
(429, 4, 'SBI-002', '2026-04-05', 27, 0, 0, 23, 0),
(430, 2, 'SBI-002', '2026-04-06', 2396, 8, 0, 68, 0),
(431, 3, 'SBI-002', '2026-04-06', 310, 3, 0, 68, 0),
(432, 4, 'SBI-002', '2026-04-06', 26, 2, 0, 68, 0),
(433, 2, 'SBI-002', '2026-04-07', 2387, 8, 0, 58, 0),
(434, 3, 'SBI-002', '2026-04-07', 307, 2, 0, 58, 0),
(435, 4, 'SBI-002', '2026-04-07', 23, 2, 0, 58, 0),
(436, 2, 'SBI-002', '2026-04-08', 2379, 10, 0, 67, 0),
(437, 3, 'SBI-002', '2026-04-08', 304, 3, 0, 67, 0),
(438, 4, 'SBI-002', '2026-04-08', 21, 2, 0, 67, 0),
(439, 2, 'SBI-002', '2026-04-09', 2368, 12, 0, 89, 0),
(440, 3, 'SBI-002', '2026-04-09', 301, 4, 0, 89, 0),
(441, 4, 'SBI-002', '2026-04-09', 18, 3, 0, 89, 0),
(442, 2, 'SBI-002', '2026-04-10', 2356, 0, 0, 7, 0),
(443, 3, 'SBI-002', '2026-04-10', 296, 0, 0, 7, 0),
(444, 4, 'SBI-002', '2026-04-10', 15, 0, 0, 7, 0),
(445, 2, 'SBI-002', '2026-04-11', 2355, 10, 143, 65, 0),
(446, 3, 'SBI-002', '2026-04-11', 296, 3, 0, 65, 0),
(447, 4, 'SBI-002', '2026-04-11', 15, 2, 0, 65, 0),
(448, 2, 'SBI-002', '2026-04-12', 2488, 3, 0, 27, 0),
(449, 3, 'SBI-002', '2026-04-12', 293, 1, 0, 27, 0),
(450, 4, 'SBI-002', '2026-04-12', 12, 1, 0, 27, 0),
(451, 2, 'SBI-002', '2026-04-13', 2484, 10, 0, 58, 0),
(452, 3, 'SBI-002', '2026-04-13', 291, 2, 43, 58, 0),
(453, 4, 'SBI-002', '2026-04-13', 11, 2, 0, 58, 0),
(454, 2, 'SBI-002', '2026-04-14', 2474, 0, 0, 6, 0),
(455, 3, 'SBI-002', '2026-04-14', 332, 0, 0, 6, 0),
(456, 4, 'SBI-002', '2026-04-14', 8, 0, 0, 6, 0),
(457, 2, 'SBI-002', '2026-04-15', 2473, 3, 0, 20, 0),
(458, 3, 'SBI-002', '2026-04-15', 332, 0, 0, 20, 0),
(459, 4, 'SBI-002', '2026-04-15', 8, 0, 0, 20, 0),
(460, 2, 'SBI-002', '2026-04-16', 2470, 8, 155, 63, 0),
(461, 3, 'SBI-002', '2026-04-16', 331, 3, 0, 63, 0),
(462, 4, 'SBI-002', '2026-04-16', 7, 2, 0, 63, 0),
(463, 2, 'SBI-002', '2026-04-17', 2617, 2, 0, 16, 0),
(464, 3, 'SBI-002', '2026-04-17', 328, 0, 0, 16, 0),
(465, 4, 'SBI-002', '2026-04-17', 5, 0, 0, 16, 0),
(466, 2, 'SBI-002', '2026-04-18', 2615, 6, 0, 45, 0),
(467, 3, 'SBI-002', '2026-04-18', 327, 2, 49, 45, 0),
(468, 4, 'SBI-002', '2026-04-18', 4, 1, 0, 45, 0),
(469, 2, 'SBI-002', '2026-04-19', 2608, 10, 0, 69, 0),
(470, 3, 'SBI-002', '2026-04-19', 374, 3, 0, 69, 0),
(471, 4, 'SBI-002', '2026-04-19', 3, 2, 0, 69, 1),
(472, 2, 'SBI-002', '2026-04-20', 2598, 11, 0, 81, 0),
(473, 3, 'SBI-002', '2026-04-20', 370, 4, 0, 81, 0),
(474, 4, 'SBI-002', '2026-04-20', 0, 3, 10, 81, 0),
(475, 2, 'SBI-002', '2026-04-21', 2586, 0, 0, 5, 0),
(476, 3, 'SBI-002', '2026-04-21', 366, 0, 0, 5, 0),
(477, 4, 'SBI-002', '2026-04-21', 7, 0, 0, 5, 0),
(478, 2, 'SBI-002', '2026-04-22', 2585, 9, 0, 62, 0),
(479, 3, 'SBI-002', '2026-04-22', 366, 3, 0, 62, 0),
(480, 4, 'SBI-002', '2026-04-22', 7, 2, 0, 62, 0),
(481, 2, 'SBI-002', '2026-04-23', 2575, 1, 0, 11, 0),
(482, 3, 'SBI-002', '2026-04-23', 363, 0, 0, 11, 0),
(483, 4, 'SBI-002', '2026-04-23', 4, 0, 0, 11, 0),
(484, 2, 'SBI-002', '2026-04-24', 2574, 1, 0, 12, 0),
(485, 3, 'SBI-002', '2026-04-24', 362, 0, 0, 12, 0),
(486, 4, 'SBI-002', '2026-04-24', 4, 0, 0, 12, 0),
(487, 2, 'SBI-002', '2026-04-25', 2572, 1, 0, 8, 0),
(488, 3, 'SBI-002', '2026-04-25', 362, 0, 0, 8, 0),
(489, 4, 'SBI-002', '2026-04-25', 3, 0, 0, 8, 0),
(490, 2, 'SBI-002', '2026-04-26', 2571, 3, 0, 21, 0),
(491, 3, 'SBI-002', '2026-04-26', 361, 1, 0, 21, 0),
(492, 4, 'SBI-002', '2026-04-26', 3, 0, 19, 21, 0),
(493, 2, 'SBI-002', '2026-04-27', 2568, 8, 0, 57, 0),
(494, 3, 'SBI-002', '2026-04-27', 360, 3, 0, 57, 0),
(495, 4, 'SBI-002', '2026-04-27', 21, 2, 0, 57, 0),
(496, 2, 'SBI-002', '2026-04-28', 2560, 5, 0, 34, 0),
(497, 3, 'SBI-002', '2026-04-28', 357, 1, 0, 34, 0),
(498, 4, 'SBI-002', '2026-04-28', 19, 1, 0, 34, 0),
(499, 2, 'SBI-002', '2026-04-29', 2554, 10, 0, 77, 0),
(500, 3, 'SBI-002', '2026-04-29', 355, 3, 0, 77, 0),
(501, 4, 'SBI-002', '2026-04-29', 18, 3, 0, 77, 0),
(502, 2, 'SBI-002', '2026-04-30', 2544, 7, 0, 47, 0),
(503, 3, 'SBI-002', '2026-04-30', 352, 2, 0, 47, 0),
(504, 4, 'SBI-002', '2026-04-30', 15, 1, 0, 47, 0),
(505, 2, 'SBI-002', '2026-05-01', 2536, 5, 0, 37, 0),
(506, 3, 'SBI-002', '2026-05-01', 349, 1, 11, 37, 0),
(507, 4, 'SBI-002', '2026-05-01', 13, 1, 0, 37, 0),
(508, 2, 'SBI-002', '2026-05-02', 2531, 11, 165, 81, 0),
(509, 3, 'SBI-002', '2026-05-02', 358, 4, 0, 81, 0),
(510, 4, 'SBI-002', '2026-05-02', 12, 3, 0, 81, 0),
(511, 2, 'SBI-002', '2026-05-03', 2686, 10, 0, 73, 0),
(512, 3, 'SBI-002', '2026-05-03', 354, 3, 0, 73, 0),
(513, 4, 'SBI-002', '2026-05-03', 8, 3, 16, 73, 0),
(514, 2, 'SBI-002', '2026-05-04', 2676, 1, 0, 10, 0),
(515, 3, 'SBI-002', '2026-05-04', 351, 0, 0, 10, 0),
(516, 4, 'SBI-002', '2026-05-04', 22, 0, 26, 10, 0),
(517, 2, 'SBI-002', '2026-05-05', 2674, 16, 0, 99, 0),
(518, 3, 'SBI-002', '2026-05-05', 350, 5, 0, 99, 0),
(519, 4, 'SBI-002', '2026-05-05', 48, 3, 0, 99, 0),
(520, 2, 'SBI-002', '2026-05-06', 2658, 15, 0, 93, 0),
(521, 3, 'SBI-002', '2026-05-06', 345, 4, 25, 93, 0),
(522, 4, 'SBI-002', '2026-05-06', 44, 3, 0, 93, 0),
(523, 2, 'SBI-002', '2026-05-07', 2643, 14, 0, 100, 0),
(524, 3, 'SBI-002', '2026-05-07', 366, 5, 0, 100, 0),
(525, 4, 'SBI-002', '2026-05-07', 41, 4, 11, 100, 0),
(526, 2, 'SBI-002', '2026-05-08', 2628, 6, 0, 39, 0),
(527, 3, 'SBI-002', '2026-05-08', 361, 2, 0, 39, 0),
(528, 4, 'SBI-002', '2026-05-08', 48, 1, 0, 39, 0),
(529, 2, 'SBI-002', '2026-05-09', 2622, 8, 0, 59, 0),
(530, 3, 'SBI-002', '2026-05-09', 359, 3, 0, 59, 0),
(531, 4, 'SBI-002', '2026-05-09', 47, 2, 0, 59, 0),
(532, 2, 'SBI-002', '2026-05-10', 2614, 14, 0, 93, 0),
(533, 3, 'SBI-002', '2026-05-10', 356, 4, 0, 93, 0),
(534, 4, 'SBI-002', '2026-05-10', 44, 3, 16, 93, 0),
(535, 2, 'SBI-002', '2026-05-11', 2599, 12, 0, 90, 0),
(536, 3, 'SBI-002', '2026-05-11', 351, 4, 0, 90, 0),
(537, 4, 'SBI-002', '2026-05-11', 57, 3, 0, 90, 0),
(538, 2, 'SBI-002', '2026-05-12', 2586, 13, 0, 79, 0),
(539, 3, 'SBI-002', '2026-05-12', 346, 3, 47, 79, 0),
(540, 4, 'SBI-002', '2026-05-12', 54, 3, 0, 79, 0),
(541, 2, 'SBI-002', '2026-05-13', 2573, 8, 0, 53, 0),
(542, 3, 'SBI-002', '2026-05-13', 390, 2, 0, 53, 0),
(543, 4, 'SBI-002', '2026-05-13', 51, 2, 19, 53, 0),
(544, 2, 'SBI-002', '2026-05-14', 2565, 3, 88, 25, 0),
(545, 3, 'SBI-002', '2026-05-14', 387, 1, 0, 25, 0),
(546, 4, 'SBI-002', '2026-05-14', 68, 1, 0, 25, 0),
(547, 2, 'SBI-002', '2026-05-15', 2649, 11, 0, 85, 0),
(548, 3, 'SBI-002', '2026-05-15', 386, 4, 0, 85, 0),
(549, 4, 'SBI-002', '2026-05-15', 67, 3, 0, 85, 0),
(550, 2, 'SBI-002', '2026-05-16', 2638, 7, 0, 49, 0),
(551, 3, 'SBI-002', '2026-05-16', 382, 2, 0, 49, 0),
(552, 4, 'SBI-002', '2026-05-16', 64, 1, 0, 49, 0),
(553, 2, 'SBI-002', '2026-05-17', 2630, 11, 0, 68, 0),
(554, 3, 'SBI-002', '2026-05-17', 380, 3, 47, 68, 0),
(555, 4, 'SBI-002', '2026-05-17', 62, 2, 0, 68, 0),
(556, 2, 'SBI-002', '2026-05-18', 2619, 6, 0, 43, 0),
(557, 3, 'SBI-002', '2026-05-18', 424, 2, 0, 43, 0),
(558, 4, 'SBI-002', '2026-05-18', 59, 1, 0, 43, 0),
(559, 2, 'SBI-002', '2026-05-19', 2613, 7, 0, 47, 0),
(560, 3, 'SBI-002', '2026-05-19', 422, 2, 0, 47, 0),
(561, 4, 'SBI-002', '2026-05-19', 58, 1, 0, 47, 0),
(562, 2, 'SBI-002', '2026-05-20', 2606, 2, 0, 18, 0),
(563, 3, 'SBI-002', '2026-05-20', 420, 0, 0, 18, 0),
(564, 4, 'SBI-002', '2026-05-20', 56, 0, 0, 18, 0),
(565, 2, 'SBI-002', '2026-05-21', 2603, 3, 0, 21, 0),
(566, 3, 'SBI-002', '2026-05-21', 419, 1, 0, 21, 0),
(567, 4, 'SBI-002', '2026-05-21', 55, 0, 0, 21, 0),
(568, 2, 'SBI-002', '2026-05-22', 2600, 15, 0, 94, 0),
(569, 3, 'SBI-002', '2026-05-22', 418, 4, 0, 94, 0),
(570, 4, 'SBI-002', '2026-05-22', 54, 3, 0, 94, 0),
(571, 2, 'SBI-002', '2026-05-23', 2584, 14, 0, 95, 0),
(572, 3, 'SBI-002', '2026-05-23', 413, 4, 0, 95, 0),
(573, 4, 'SBI-002', '2026-05-23', 51, 3, 0, 95, 0),
(574, 2, 'SBI-002', '2026-05-24', 2570, 4, 0, 25, 0),
(575, 3, 'SBI-002', '2026-05-24', 408, 1, 0, 25, 0),
(576, 4, 'SBI-002', '2026-05-24', 47, 1, 0, 25, 0),
(577, 2, 'SBI-002', '2026-05-25', 2566, 6, 114, 33, 0),
(578, 3, 'SBI-002', '2026-05-25', 407, 1, 0, 33, 0),
(579, 4, 'SBI-002', '2026-05-25', 46, 1, 0, 33, 0),
(580, 2, 'SBI-002', '2026-05-26', 2674, 10, 0, 66, 0),
(581, 3, 'SBI-002', '2026-05-26', 405, 3, 0, 66, 0),
(582, 4, 'SBI-002', '2026-05-26', 44, 2, 0, 66, 0),
(583, 2, 'SBI-002', '2026-05-27', 2663, 9, 0, 58, 0),
(584, 3, 'SBI-002', '2026-05-27', 402, 3, 0, 58, 0),
(585, 4, 'SBI-002', '2026-05-27', 42, 2, 0, 58, 0),
(586, 2, 'SBI-002', '2026-05-28', 2654, 4, 0, 25, 0),
(587, 3, 'SBI-002', '2026-05-28', 399, 1, 25, 25, 0),
(588, 4, 'SBI-002', '2026-05-28', 40, 1, 0, 25, 0),
(589, 2, 'SBI-002', '2026-05-29', 2649, 7, 0, 55, 0),
(590, 3, 'SBI-002', '2026-05-29', 423, 2, 0, 55, 0),
(591, 4, 'SBI-002', '2026-05-29', 39, 2, 0, 55, 0),
(592, 2, 'SBI-002', '2026-05-30', 2642, 11, 52, 68, 0),
(593, 3, 'SBI-002', '2026-05-30', 420, 3, 0, 68, 0),
(594, 4, 'SBI-002', '2026-05-30', 36, 2, 0, 68, 0),
(595, 2, 'SBI-002', '2026-05-31', 2683, 6, 0, 42, 0),
(596, 3, 'SBI-002', '2026-05-31', 417, 2, 0, 42, 0),
(597, 4, 'SBI-002', '2026-05-31', 34, 1, 0, 42, 0),
(598, 2, 'SBI-002', '2026-06-01', 2677, 13, 0, 96, 0),
(599, 3, 'SBI-002', '2026-06-01', 415, 4, 0, 96, 0),
(600, 4, 'SBI-002', '2026-06-01', 32, 3, 0, 96, 0),
(601, 2, 'SBI-002', '2026-06-02', 2663, 9, 0, 86, 0),
(602, 3, 'SBI-002', '2026-06-02', 410, 4, 0, 86, 0),
(603, 4, 'SBI-002', '2026-06-02', 28, 3, 0, 86, 0),
(604, 2, 'SBI-002', '2026-06-03', 2654, 8, 162, 57, 0),
(605, 3, 'SBI-002', '2026-06-03', 405, 2, 0, 57, 0),
(606, 4, 'SBI-002', '2026-06-03', 25, 2, 0, 57, 0),
(607, 2, 'SBI-002', '2026-06-04', 2808, 1, 0, 11, 0),
(608, 3, 'SBI-002', '2026-06-04', 403, 0, 0, 11, 0),
(609, 4, 'SBI-002', '2026-06-04', 22, 0, 0, 11, 0),
(610, 2, 'SBI-002', '2026-06-05', 2806, 3, 0, 23, 0),
(611, 3, 'SBI-002', '2026-06-05', 402, 1, 0, 23, 0),
(612, 4, 'SBI-002', '2026-06-05', 22, 0, 0, 23, 0),
(613, 2, 'SBI-002', '2026-06-06', 2802, 3, 0, 22, 0),
(614, 3, 'SBI-002', '2026-06-06', 401, 1, 0, 22, 0),
(615, 4, 'SBI-002', '2026-06-06', 21, 0, 0, 22, 0),
(616, 2, 'SBI-002', '2026-06-07', 2799, 10, 0, 87, 0),
(617, 3, 'SBI-002', '2026-06-07', 400, 4, 0, 87, 0),
(618, 4, 'SBI-002', '2026-06-07', 20, 3, 0, 87, 0),
(619, 2, 'SBI-002', '2026-06-08', 2788, 0, 0, 5, 0),
(620, 3, 'SBI-002', '2026-06-08', 395, 0, 0, 5, 0),
(621, 4, 'SBI-002', '2026-06-08', 17, 0, 0, 5, 0),
(622, 2, 'SBI-002', '2026-06-09', 2787, 1, 0, 14, 0),
(623, 3, 'SBI-002', '2026-06-09', 395, 0, 0, 14, 0),
(624, 4, 'SBI-002', '2026-06-09', 17, 0, 0, 14, 0),
(625, 2, 'SBI-002', '2026-06-10', 2785, 7, 0, 44, 0),
(626, 3, 'SBI-002', '2026-06-10', 394, 2, 0, 44, 0),
(627, 4, 'SBI-002', '2026-06-10', 16, 1, 0, 44, 0),
(628, 2, 'SBI-002', '2026-06-11', 2778, 1, 0, 11, 0),
(629, 3, 'SBI-002', '2026-06-11', 392, 0, 0, 11, 0),
(630, 4, 'SBI-002', '2026-06-11', 14, 0, 0, 11, 0),
(631, 2, 'SBI-002', '2026-06-12', 2776, 11, 181, 79, 0),
(632, 3, 'SBI-002', '2026-06-12', 392, 4, 0, 79, 0),
(633, 4, 'SBI-002', '2026-06-12', 14, 3, 0, 79, 0),
(634, 2, 'SBI-002', '2026-06-13', 2946, 11, 0, 72, 0),
(635, 3, 'SBI-002', '2026-06-13', 388, 3, 0, 72, 0),
(636, 4, 'SBI-002', '2026-06-13', 11, 2, 0, 72, 0),
(637, 2, 'SBI-002', '2026-06-14', 2935, 15, 0, 98, 0),
(638, 3, 'SBI-002', '2026-06-14', 384, 4, 0, 98, 0),
(639, 4, 'SBI-002', '2026-06-14', 8, 4, 0, 98, 0),
(640, 2, 'SBI-002', '2026-06-15', 2919, 5, 0, 43, 0),
(641, 3, 'SBI-002', '2026-06-15', 379, 2, 0, 43, 0),
(642, 4, 'SBI-002', '2026-06-15', 4, 1, 0, 43, 0),
(643, 2, 'SBI-002', '2026-06-16', 2914, 11, 80, 71, 0),
(644, 3, 'SBI-002', '2026-06-16', 377, 3, 0, 71, 0),
(645, 4, 'SBI-002', '2026-06-16', 2, 2, 12, 71, 0),
(646, 2, 'SBI-002', '2026-06-17', 2983, 14, 0, 97, 0),
(647, 3, 'SBI-002', '2026-06-17', 373, 4, 16, 97, 0),
(648, 4, 'SBI-002', '2026-06-17', 11, 3, 0, 97, 0),
(649, 2, 'SBI-002', '2026-06-18', 2968, 14, 0, 98, 0),
(650, 3, 'SBI-002', '2026-06-18', 385, 4, 0, 98, 0),
(651, 4, 'SBI-002', '2026-06-18', 8, 3, 0, 98, 0),
(652, 2, 'SBI-002', '2026-06-19', 2953, 10, 0, 69, 0),
(653, 3, 'SBI-002', '2026-06-19', 380, 3, 0, 69, 0),
(654, 4, 'SBI-002', '2026-06-19', 4, 2, 18, 69, 0),
(655, 2, 'SBI-002', '2026-06-20', 2943, 1, 0, 12, 0),
(656, 3, 'SBI-002', '2026-06-20', 377, 0, 47, 12, 0),
(657, 4, 'SBI-002', '2026-06-20', 19, 0, 0, 12, 0),
(658, 2, 'SBI-002', '2026-06-21', 2941, 5, 51, 34, 0),
(659, 3, 'SBI-002', '2026-06-21', 424, 1, 0, 34, 0),
(660, 4, 'SBI-002', '2026-06-21', 19, 1, 0, 34, 0),
(661, 2, 'SBI-002', '2026-06-22', 2987, 10, 0, 71, 0),
(662, 3, 'SBI-002', '2026-06-22', 422, 3, 0, 71, 0),
(663, 4, 'SBI-002', '2026-06-22', 18, 2, 0, 71, 0),
(664, 2, 'SBI-002', '2026-06-23', 2976, 8, 0, 54, 0),
(665, 3, 'SBI-002', '2026-06-23', 419, 2, 0, 54, 0),
(666, 4, 'SBI-002', '2026-06-23', 15, 2, 0, 54, 0),
(667, 2, 'SBI-002', '2026-06-24', 2968, 6, 0, 38, 0),
(668, 3, 'SBI-002', '2026-06-24', 416, 2, 0, 38, 0),
(669, 4, 'SBI-002', '2026-06-24', 13, 1, 0, 38, 0),
(670, 2, 'SBI-002', '2026-06-25', 2962, 1, 0, 10, 0),
(671, 3, 'SBI-002', '2026-06-25', 414, 0, 0, 10, 0),
(672, 4, 'SBI-002', '2026-06-25', 11, 0, 0, 10, 0),
(673, 2, 'SBI-002', '2026-06-26', 2961, 9, 0, 61, 0),
(674, 3, 'SBI-002', '2026-06-26', 413, 3, 0, 61, 0),
(675, 4, 'SBI-002', '2026-06-26', 11, 2, 0, 61, 0),
(676, 2, 'SBI-002', '2026-06-27', 2952, 7, 154, 50, 0),
(677, 3, 'SBI-002', '2026-06-27', 410, 2, 0, 50, 0),
(678, 4, 'SBI-002', '2026-06-27', 8, 2, 0, 50, 0),
(679, 2, 'SBI-002', '2026-06-28', 3099, 1, 0, 10, 0),
(680, 3, 'SBI-002', '2026-06-28', 408, 0, 0, 10, 0),
(681, 4, 'SBI-002', '2026-06-28', 6, 0, 0, 10, 0),
(682, 2, 'SBI-002', '2026-06-29', 3097, 3, 0, 22, 0),
(683, 3, 'SBI-002', '2026-06-29', 407, 1, 0, 22, 0),
(684, 4, 'SBI-002', '2026-06-29', 6, 0, 25, 22, 0),
(685, 2, 'SBI-002', '2026-06-30', 3094, 9, 0, 58, 0),
(686, 3, 'SBI-002', '2026-06-30', 406, 2, 0, 58, 0),
(687, 4, 'SBI-002', '2026-06-30', 31, 2, 0, 58, 0),
(688, 2, 'SBI-002', '2026-07-01', 3085, 8, 0, 55, 0),
(689, 3, 'SBI-002', '2026-07-01', 403, 2, 0, 55, 0),
(690, 4, 'SBI-002', '2026-07-01', 29, 2, 0, 55, 0),
(691, 2, 'SBI-002', '2026-07-02', 3076, 6, 0, 41, 0),
(692, 3, 'SBI-002', '2026-07-02', 401, 2, 0, 41, 0),
(693, 4, 'SBI-002', '2026-07-02', 26, 1, 0, 41, 0),
(694, 2, 'SBI-002', '2026-07-03', 3069, 3, 0, 18, 0),
(695, 3, 'SBI-002', '2026-07-03', 399, 0, 0, 18, 0),
(696, 4, 'SBI-002', '2026-07-03', 25, 0, 0, 18, 0),
(697, 2, 'SBI-002', '2026-07-04', 3066, 2, 0, 14, 0),
(698, 3, 'SBI-002', '2026-07-04', 398, 0, 0, 14, 0),
(699, 4, 'SBI-002', '2026-07-04', 24, 0, 0, 14, 0),
(700, 2, 'SBI-002', '2026-07-05', 3064, 0, 0, 5, 0),
(701, 3, 'SBI-002', '2026-07-05', 397, 0, 0, 5, 0),
(702, 4, 'SBI-002', '2026-07-05', 23, 0, 0, 5, 0),
(703, 2, 'SBI-002', '2026-07-06', 3063, 4, 170, 32, 0),
(704, 3, 'SBI-002', '2026-07-06', 397, 1, 0, 32, 0),
(705, 4, 'SBI-002', '2026-07-06', 23, 1, 0, 32, 0),
(706, 2, 'SBI-002', '2026-07-07', 3229, 3, 0, 29, 0),
(707, 3, 'SBI-002', '2026-07-07', 395, 1, 0, 29, 0),
(708, 4, 'SBI-002', '2026-07-07', 22, 1, 0, 29, 0),
(709, 2, 'SBI-002', '2026-07-08', 3225, 4, 0, 29, 0),
(710, 3, 'SBI-002', '2026-07-08', 394, 1, 0, 29, 0),
(711, 4, 'SBI-002', '2026-07-08', 21, 1, 0, 29, 0),
(712, 2, 'SBI-002', '2026-07-09', 3221, 4, 0, 29, 0),
(713, 3, 'SBI-002', '2026-07-09', 392, 1, 0, 29, 0),
(714, 4, 'SBI-002', '2026-07-09', 20, 1, 0, 29, 0),
(715, 2, 'SBI-002', '2026-07-10', 3217, 3, 0, 22, 0),
(716, 3, 'SBI-002', '2026-07-10', 391, 1, 0, 22, 0),
(717, 4, 'SBI-002', '2026-07-10', 18, 0, 0, 22, 0);

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
(3, 'Box/s'),
(4, 'Vial'),
(5, 'Ampule'),
(6, 'Piece'),
(7, 'Box'),
(8, 'Bottle'),
(9, 'Pack'),
(10, 'Gallon'),
(11, 'Piece'),
(12, 'Set');

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
(1, 'SBI-001', 1, 'superadmin', 'garabillo_jojanajean@plpasig.edu.ph', '$2y$10$gTUuk2.GeUd5BNNryWGq8OhJWjQcdqk8rOMPUMJ1VkFaxc7eJajHe', 'Active', '2026-07-04 07:53:47', '2026-07-10 18:31:40'),
(6, 'SBI-002', 2, 'Jojana Garabillo', 'jojanajeangarabillo@gmail.com', '$2y$10$Gundsv.vdvdqOT1M3VIcP.r/8y/MqzNxDPnxfh2qypFd44fL6xZta', 'Active', '2026-07-04 09:59:45', '2026-07-10 18:43:05'),
(8, 'SBI-002', 4, 'Shane Cacho', 'pam066198@gmail.com', '$2a$12$rfw67uNcLetmfn6M2Dv5e.ff49vAitKIHni3Q7iKV.zgMruHVfkIa', 'Active', '2026-07-04 14:41:02', '2026-07-09 10:09:42'),
(9, 'SBI-002', 3, 'Marc Beringuela', 'ruberducky032518@gmail.com', '$2y$10$mRG5TnwyVCEkohLgsXiCe.INa226POltPF/0M4fYuXX8mq925V7kO', 'Active', '2026-07-04 14:55:07', '2026-07-10 19:27:59'),
(11, 'SBI-002', 5, 'Jean Montero', 'joepatlacerna54@gmail.com', '$2y$10$ga5HM6WcD0wQSSvnpCAvue4UdqknCryN93mJVLatoI4GAiEDHctNO', 'Active', '2026-07-04 15:09:39', '2026-07-10 19:18:21'),
(14, 'SBI-003', 2, 'Joepat Lacerna', 'opat09252005@gmail.com', '$2y$10$JB4.S8HI8Zu.IbvLHuMhH.3mnmqmyWMdV4ID/nRxcotOs.tmdCasm', 'Active', '2026-07-05 11:56:22', '2026-07-05 20:04:06'),
(15, 'SBI-003', 2, 'Mae Ben', 'sheyn.cacho@gmail.com', '$2y$10$knOsicB5IV6qD4SqIaF3WOBBnyrFDJYTknP8yqBSXlo35gIp8mLim', 'Active', '2026-07-05 16:19:46', '2026-07-09 19:33:48'),
(16, 'SBI-002', 4, 'Ella Franco', 'cachosheyn@gmail.com', '$2y$10$lLv3F5B3Yu1QGQU1GkAbq./clNj/7RMlMH1noMMwNAqv/45mymWfm', 'Active', '2026-07-07 06:24:22', '2026-07-10 20:08:16');

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
(28, 33, 27, 1, 'SBI-002', 6, NULL, '2026-08-06', NULL, NULL, 'Scheduled', 0, '', 8, '2026-07-09 02:23:52'),
(45, 50, 44, 1, 'SBI-002', 1, '2026-07-10', '2026-07-10', NULL, NULL, '', 0, '', 16, '2026-07-10 12:37:59'),
(46, 50, 44, 1, 'SBI-002', 2, '2026-07-10', '2026-07-13', NULL, NULL, '', 0, '', 16, '2026-07-10 12:37:59'),
(47, 50, 44, 1, 'SBI-002', 3, '2026-07-10', '2026-07-17', NULL, NULL, '', 0, '', 16, '2026-07-10 12:37:59'),
(48, 50, 44, 1, 'SBI-002', 6, NULL, '2026-08-07', NULL, NULL, '', 0, '', 16, '2026-07-10 12:37:59');

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
  ADD UNIQUE KEY `unique_batch_per_branch_item` (`item_id`,`branch_id`,`batch_number`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `fk_stock_branch` (`branch_id`),
  ADD KEY `idx_stocks_branch_item_expiry` (`branch_id`,`item_id`,`expiration_date`),
  ADD KEY `idx_stocks_expiration` (`expiration_date`),
  ADD KEY `idx_stocks_branch_item` (`branch_id`,`item_id`),
  ADD KEY `idx_stocks_expiry` (`expiration_date`);

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
  ADD KEY `fk_transaction_branch` (`branch_id`),
  ADD KEY `idx_transactions_branch_date` (`branch_id`,`transaction_date`),
  ADD KEY `idx_transactions_item` (`item_id`),
  ADD KEY `idx_transactions_type` (`transaction_type`);

--
-- Indexes for table `training_dataset`
--
ALTER TABLE `training_dataset`
  ADD PRIMARY KEY (`training_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `fk_training_branch` (`branch_id`),
  ADD KEY `idx_training_branch_date` (`branch_id`,`record_date`),
  ADD KEY `idx_training_item` (`item_id`);

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
  MODIFY `case_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=279;

--
-- AUTO_INCREMENT for table `document_tracking`
--
ALTER TABLE `document_tracking`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_categories`
--
ALTER TABLE `inventory_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `inventory_stocks`
--
ALTER TABLE `inventory_stocks`
  MODIFY `stock_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

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
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `philhealth_records`
--
ALTER TABLE `philhealth_records`
  MODIFY `philhealth_record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `prediction_results`
--
ALTER TABLE `prediction_results`
  MODIFY `prediction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registry_records`
--
ALTER TABLE `registry_records`
  MODIFY `registry_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `training_dataset`
--
ALTER TABLE `training_dataset`
  MODIFY `training_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=718;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

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
  MODIFY `vaccination_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

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
