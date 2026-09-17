-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 17, 2026 at 08:41 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vehicle_booking`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role_at_time` varchar(20) DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`log_id`, `user_id`, `role_at_time`, `module`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 09:03:43'),
(2, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 09:03:54'),
(3, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 09:04:40'),
(4, 3, 'User', 'Log Masuk', 'Log Masuk', 'User log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 09:04:49'),
(5, 3, 'User', 'Log Keluar', 'Log Keluar', 'User log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 09:07:47'),
(6, 2, 'Admin', 'Log Masuk', 'Log Masuk', 'Admin log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 09:07:56'),
(7, 2, 'Admin', 'Log Keluar', 'Log Keluar', 'Admin log keluar daripada sistem.', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1', '2026-09-04 10:06:13'),
(8, 1, NULL, 'Log Masuk', 'Log Masuk Gagal', 'Kata laluan salah untuk e-mel: superadmin@selangor.gov.my', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1', '2026-09-04 10:06:22'),
(9, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1', '2026-09-04 10:06:29'),
(10, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 10:41:06'),
(11, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 10:41:15'),
(12, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:03:59'),
(13, 3, 'User', 'Log Masuk', 'Log Masuk', 'User log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:04:08'),
(14, 3, 'User', 'Log Keluar', 'Log Keluar', 'User log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:09:36'),
(15, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:10:47'),
(16, 3, 'User', 'Log Keluar', 'Log Keluar', 'User log keluar daripada sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:11:17'),
(17, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:11:21'),
(18, 1, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Super Admin log keluar daripada sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:13:30'),
(19, 12, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Nureen Afriena log masuk ke sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:13:44'),
(20, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.120.164', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:18:59'),
(21, 12, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Nureen Afriena log keluar daripada sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:44:08'),
(22, 13, 'User', 'Log Masuk', 'Log Masuk', 'Atika log masuk ke sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:44:25'),
(23, 13, 'User', 'Log Keluar', 'Log Keluar', 'Atika log keluar daripada sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:48:44'),
(24, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:48:55'),
(25, 1, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Super Admin log keluar daripada sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:53:43'),
(26, 13, 'User', 'Log Masuk', 'Log Masuk', 'Atika log masuk ke sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:54:03'),
(27, 13, 'User', 'Log Keluar', 'Log Keluar', 'Atika log keluar daripada sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 11:58:43'),
(28, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 12:07:22'),
(29, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 12:13:02'),
(30, 1, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Super Admin log keluar daripada sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 15:13:02'),
(31, 3, 'User', 'Log Masuk', 'Log Masuk', 'User log masuk ke sistem.', '192.168.110.97', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 15:13:06'),
(32, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 16:00:45'),
(33, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 16:16:17'),
(34, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 16:27:57'),
(35, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-04 16:28:05'),
(36, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 09:53:32'),
(37, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '192.168.110.142', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1', '2026-09-07 14:48:49'),
(38, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 15:27:24'),
(39, 3, 'User', 'Log Masuk', 'Log Masuk', 'User log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 15:27:33'),
(40, 3, 'User', 'Log Keluar', 'Log Keluar', 'User log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 15:31:22'),
(41, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 15:31:29'),
(42, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '192.168.110.142', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5.2 Mobile/15E148 Safari/604.1', '2026-09-07 15:56:24'),
(43, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36 Edg/152.0.0.0', '2026-09-07 16:04:14'),
(44, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36 Edg/152.0.0.0', '2026-09-07 16:07:14'),
(45, 7, 'User', 'Log Masuk', 'Log Masuk', 'Mohd Faiz log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36 Edg/152.0.0.0', '2026-09-07 16:07:43'),
(46, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 16:27:48'),
(47, 7, 'User', 'Log Masuk', 'Log Masuk', 'Mohd Faiz log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 16:27:56'),
(48, 7, 'User', 'Log Keluar', 'Log Keluar', 'Mohd Faiz log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 16:31:56'),
(49, 7, 'User', 'Log Masuk', 'Log Masuk', 'Mohd Faiz log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 16:32:53'),
(50, 7, 'User', 'Log Keluar', 'Log Keluar', 'Mohd Faiz log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 16:37:41'),
(51, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-07 16:37:50'),
(52, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 14:31:35'),
(53, 1, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Super Admin log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 15:17:52'),
(54, 3, 'User', 'Log Masuk', 'Log Masuk', 'User log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 15:17:56'),
(55, 3, 'User', 'Log Keluar', 'Log Keluar', 'User log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 15:22:04'),
(56, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 15:22:08'),
(57, 1, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Super Admin log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 15:33:06'),
(58, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 15:33:17'),
(59, 1, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Super Admin log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:03:00'),
(60, 2, 'Admin', 'Log Masuk', 'Log Masuk', 'Admin log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:03:10'),
(61, 2, 'Admin', 'Log Keluar', 'Log Keluar', 'Admin log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:03:21'),
(62, 3, 'User', 'Log Masuk', 'Log Masuk', 'User log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:03:25'),
(63, 3, 'User', 'Log Keluar', 'Log Keluar', 'User log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:05:26'),
(64, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:05:29'),
(65, 1, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Super Admin log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:41:07'),
(66, 3, 'User', 'Log Masuk', 'Log Masuk', 'User log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:41:11'),
(67, 3, 'User', 'Log Keluar', 'Log Keluar', 'User log keluar daripada sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:45:03'),
(68, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.84', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-08 16:45:08'),
(69, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '10.250.10.107', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-09 10:39:07'),
(70, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '10.250.11.242', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-09 11:24:37'),
(71, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:00:49'),
(72, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:23:06'),
(73, 13, NULL, 'Log Masuk', 'Log Masuk Gagal', 'Kata laluan salah untuk e-mel: atika@selangor.gov.my', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:23:25'),
(74, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:23:48'),
(75, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:24:03'),
(76, 13, 'User', 'Log Masuk', 'Log Masuk', 'Atika log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:24:11'),
(77, 13, 'User', 'Log Keluar', 'Log Keluar', 'Atika log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:25:12'),
(78, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 12:25:19'),
(79, 1, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Super Admin log masuk ke sistem.', '192.168.110.141', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-10 16:24:25'),
(80, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-11 09:48:33'),
(81, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-11 15:33:26'),
(82, 11, NULL, 'Log Masuk', 'Log Masuk Gagal', 'Kata laluan salah untuk e-mel: fit@selangor.gov.my', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-11 15:33:31'),
(83, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-11 15:33:44'),
(84, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-15 12:40:48'),
(85, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 08:54:20'),
(86, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 09:22:43'),
(87, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 09:22:50'),
(88, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 09:46:30'),
(89, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 09:46:39'),
(90, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 10:59:17'),
(91, 2, 'Admin', 'Log Masuk', 'Log Masuk', 'Admin log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 10:59:24'),
(92, 2, 'Admin', 'Log Keluar', 'Log Keluar', 'Admin log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 10:59:42'),
(93, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 10:59:49'),
(94, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:00:28'),
(95, 5, 'User', 'Log Masuk', 'Log Masuk', 'Ahmad Firdaus log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:00:35'),
(96, 5, 'User', 'Log Keluar', 'Log Keluar', 'Ahmad Firdaus log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:00:52'),
(97, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:01:00'),
(98, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:04:43'),
(99, 2, 'Admin', 'Log Masuk', 'Log Masuk', 'Admin log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:04:50'),
(100, 5, 'User', 'Log Masuk', 'Log Masuk', 'Ahmad Firdaus log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', '2026-09-17 11:14:29'),
(101, 2, 'Admin', 'Log Keluar', 'Log Keluar', 'Admin log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:16:00'),
(102, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:16:07'),
(103, 11, 'SuperAdmin', 'Log Keluar', 'Log Keluar', 'Fit log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:19:53'),
(104, 2, 'Admin', 'Log Masuk', 'Log Masuk', 'Admin log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:20:00'),
(105, 2, 'Admin', 'Log Keluar', 'Log Keluar', 'Admin log keluar daripada sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:21:54'),
(106, 11, 'SuperAdmin', 'Log Masuk', 'Log Masuk', 'Fit log masuk ke sistem.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '2026-09-17 11:22:04');

-- --------------------------------------------------------

--
-- Table structure for table `booking_history`
--

CREATE TABLE `booking_history` (
  `history_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `module` varchar(30) NOT NULL DEFAULT 'Vehicle',
  `action` varchar(100) NOT NULL,
  `remarks` text DEFAULT NULL,
  `action_by` int(11) NOT NULL,
  `action_datetime` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `booking_history`
--

INSERT INTO `booking_history` (`history_id`, `booking_id`, `module`, `action`, `remarks`, `action_by`, `action_datetime`) VALUES
(1, 1, 'Vehicle', 'Booking Created', 'Booking submitted', 5, '2026-08-04 08:30:00'),
(2, 1, 'Vehicle', 'Booking Approved', 'Vehicle assigned', 2, '2026-08-05 09:00:00'),
(3, 2, 'Vehicle', 'Booking Created', 'Awaiting approval', 6, '2026-08-05 10:15:00'),
(4, 3, 'Vehicle', 'Booking Created', 'Long distance travel request', 5, '2026-08-05 13:00:00'),
(5, 3, 'Vehicle', 'Booking Rejected', 'Vehicle unavailable', 2, '2026-08-06 11:30:00'),
(6, 4, 'Vehicle', 'Booking Completed', 'Trip completed successfully', 2, '2026-08-18 17:00:00'),
(7, 2, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-08-12 15:19:49'),
(8, 8, 'Vehicle', 'Dicipta', 'Tempahan VB2608122EAF7 dicipta.', 3, '2026-08-12 15:33:57'),
(9, 1, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 1, '2026-08-12 15:50:44'),
(10, 8, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-08-12 16:01:29'),
(11, 8, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 1, '2026-08-12 16:01:37'),
(12, 2, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 1, '2026-08-12 16:01:39'),
(13, 9, 'Vehicle', 'Dicipta', 'Tempahan VB2608131275E dicipta.', 1, '2026-08-13 15:25:29'),
(14, 10, 'Vehicle', 'Dicipta', 'Tempahan VB260813A5054 dicipta.', 3, '2026-08-13 15:32:28'),
(15, 9, 'Vehicle', 'Dibatalkan', 'Tempahan dibatalkan.', 1, '2026-08-13 16:04:45'),
(16, 10, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-08-13 16:05:03'),
(17, 11, 'Vehicle', 'Dicipta', 'Tempahan VB26081381D84 dicipta.', 3, '2026-08-13 16:06:39'),
(18, 12, 'Vehicle', 'Dicipta', 'Tempahan VB2608141DE82 dicipta.', 1, '2026-08-14 15:47:49'),
(19, 11, 'Vehicle', 'Dibatalkan', 'Tempahan dibatalkan.', 3, '2026-08-14 15:48:48'),
(20, 12, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-08-17 16:03:29'),
(21, 12, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 1, '2026-08-17 16:03:40'),
(22, 10, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 1, '2026-08-17 16:03:41'),
(23, 13, 'Vehicle', 'Dicipta', 'Tempahan VB2608175330B dicipta.', 3, '2026-08-17 16:08:27'),
(24, 14, 'Vehicle', 'Dicipta', 'Tempahan VB260817ECF72 dicipta.', 1, '2026-08-17 16:44:40'),
(25, 13, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 11, '2026-08-18 15:52:10'),
(26, 14, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 11, '2026-08-18 15:52:15'),
(27, 13, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 11, '2026-08-18 16:12:01'),
(28, 14, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 11, '2026-08-18 16:12:03'),
(29, 15, 'Vehicle', 'Dicipta', 'Tempahan VB260818FB295 dicipta.', 3, '2026-08-18 16:31:04'),
(30, 15, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 11, '2026-08-18 16:32:02'),
(31, 16, 'Vehicle', 'Dicipta', 'Tempahan VB260819C5F38 dicipta.', 3, '2026-08-19 12:12:39'),
(32, 16, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 11, '2026-08-19 12:13:54'),
(33, 15, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 11, '2026-08-19 12:17:59'),
(34, 17, 'Vehicle', 'Dicipta', 'Tempahan VB260819F2DD9 dicipta.', 3, '2026-08-19 15:39:00'),
(35, 17, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 11, '2026-08-19 15:39:09'),
(36, 16, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 11, '2026-08-26 16:43:35'),
(37, 17, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 11, '2026-08-26 16:43:38'),
(38, 18, 'Vehicle', 'Dicipta', 'Tempahan VB260827845A8 dicipta.', 11, '2026-08-27 11:59:44'),
(39, 18, 'Vehicle', 'Ditolak', 'Tempahan ditolak.', 11, '2026-09-02 09:53:51'),
(40, 19, 'Vehicle', 'Dicipta', 'Tempahan VB260902E7DFB dicipta.', 11, '2026-09-02 09:54:59'),
(41, 20, 'Vehicle', 'Dicipta', 'Tempahan VB260902AA835 dicipta.', 3, '2026-09-02 10:00:13'),
(42, 19, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-09-03 16:36:17'),
(43, 20, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-09-03 16:36:25'),
(44, 21, 'Vehicle', 'Dicipta', 'Tempahan VB2609036FB52 dicipta.', 3, '2026-09-03 16:57:41'),
(45, 21, 'Vehicle', 'Dibatalkan', 'Tempahan dibatalkan.', 3, '2026-09-03 16:59:17'),
(46, 22, 'Vehicle', 'Dicipta', 'Tempahan VB2609045C49A dicipta.', 11, '2026-09-04 11:01:39'),
(47, 22, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 11, '2026-09-04 11:02:00'),
(48, 23, 'Vehicle', 'Dicipta', 'Tempahan VB26090450800 dicipta.', 1, '2026-09-04 11:26:28'),
(49, 23, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-09-04 11:28:17'),
(50, 22, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 1, '2026-09-04 11:30:32'),
(51, 24, 'Vehicle', 'Dicipta', 'Tempahan VB260904B6F1C dicipta.', 13, '2026-09-04 11:48:12'),
(52, 24, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 1, '2026-09-04 11:53:20'),
(53, 25, 'Vehicle', 'Dicipta', 'Tempahan VB260904F0E01 dicipta.', 11, '2026-09-04 15:25:33'),
(54, 26, 'Vehicle', 'Dicipta', 'Tempahan VB260907606E5 dicipta.', 11, '2026-09-07 11:26:57'),
(55, 23, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 11, '2026-09-07 12:40:08'),
(56, 27, 'Vehicle', 'Dicipta', 'Tempahan VB260907AFD44 dicipta.', 7, '2026-09-07 16:10:54'),
(57, 27, 'Vehicle', 'Dibatalkan', 'Tempahan dibatalkan.', 7, '2026-09-07 16:11:24'),
(58, 28, 'Vehicle', 'Dicipta', 'Tempahan VB260907C4965 dicipta.', 7, '2026-09-07 16:12:28'),
(59, 26, 'Vehicle', 'Diluluskan', 'Tempahan diluluskan, pemandu & kenderaan ditugaskan.', 11, '2026-09-10 12:11:22'),
(60, 26, 'Vehicle', 'Selesai', 'Perjalanan selesai.', 11, '2026-09-10 12:15:07'),
(61, 29, 'Vehicle', 'Dicipta', 'Tempahan VB2609179274B dicipta.', 11, '2026-09-17 10:54:34'),
(62, 30, 'Vehicle', 'Dicipta', 'Tempahan VB2609170FEEE dicipta.', 2, '2026-09-17 11:08:32'),
(63, 31, 'Vehicle', 'Dicipta', 'Tempahan VB260917B637C dicipta.', 2, '2026-09-17 11:13:02'),
(64, 31, 'Vehicle', 'Driver Assigned', 'Pemandu telah ditugaskan dan menunggu pengesahan.', 2, '2026-09-17 11:13:53'),
(65, 31, 'Vehicle', 'Driver Accepted', 'Pemandu menerima tugasan.', 5, '2026-09-17 11:17:29'),
(66, 32, 'Vehicle', 'Dicipta', 'Tempahan VB2609171ADF1 dicipta.', 11, '2026-09-17 11:28:40'),
(67, 33, 'Vehicle', 'Dicipta', 'Tempahan VB26091702018 dicipta.', 11, '2026-09-17 11:58:27');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`) VALUES
(1, 'Pejabat YB Pegawai Kewangan Negeri'),
(2, 'Pejabat Timbalan Pegawai Kewangan Negeri'),
(3, 'Pejabat Bendahari'),
(4, 'Bahagian Khidmat Pengurusan'),
(9, 'Bahagian Belanjawan'),
(10, 'Bahagian Operasi Perakaunan'),
(11, 'Bahagian Pengurusan Hasil'),
(12, 'Bahagian Analisa Pemantauan Pelaburan dan Pinjaman'),
(13, 'Bahagian Perolehan dan Pengurusan Aset'),
(14, 'Bahagian Khidmat Naziran dan Perundingan'),
(15, 'Bahagain Pengurusan Dana dan Terimaan');

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `driver_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `license` varchar(50) NOT NULL,
  `status` enum('Available','Leave','Inactive') NOT NULL DEFAULT 'Available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`driver_id`, `user_id`, `license`, `status`) VALUES
(1, 5, 'D1234567', 'Available'),
(2, 6, 'D2345678', 'Available'),
(6, 13, '123', 'Available');

-- --------------------------------------------------------

--
-- Table structure for table `email_notifications`
--

CREATE TABLE `email_notifications` (
  `email_id` int(11) NOT NULL,
  `booking_no` varchar(30) NOT NULL,
  `recipient_email` varchar(100) NOT NULL,
  `email_type` varchar(50) DEFAULT NULL,
  `subject` varchar(150) DEFAULT NULL,
  `status` enum('Pending','Sent','Failed') NOT NULL DEFAULT 'Pending',
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_notifications`
--

INSERT INTO `email_notifications` (`email_id`, `booking_no`, `recipient_email`, `email_type`, `subject`, `status`, `sent_at`) VALUES
(1, 'VB202608001', 'ahmad.firdaus@selangor.gov.my', 'Approval', 'Vehicle Booking Approved', 'Sent', '2026-08-05 09:01:00'),
(2, 'VB202608002', 'nur.aisyah@selangor.gov.my', 'Pending', 'Vehicle Booking Submitted', 'Sent', '2026-08-05 10:16:00'),
(3, 'VB202608003', 'ahmad.firdaus@selangor.gov.my', 'Rejection', 'Vehicle Booking Rejected', 'Sent', '2026-08-06 11:31:00');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `module` varchar(30) NOT NULL DEFAULT 'Vehicle',
  `title` varchar(150) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `booking_id`, `module`, `title`, `message`, `is_read`, `created_at`) VALUES
(1, 5, 1, 'Vehicle', 'Booking Approved', 'Your booking VB202608001 has been approved.', 0, '2026-08-04 14:27:32'),
(2, 6, 2, 'Vehicle', 'Booking Submitted', 'Your booking is pending approval.', 1, '2026-08-04 14:27:32'),
(3, 5, 3, 'Vehicle', 'Booking Rejected', 'Your booking VB202608003 has been rejected.', 0, '2026-08-04 14:27:32');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone_no` varchar(20) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `role` enum('Admin','User','SuperAdmin') NOT NULL DEFAULT 'User',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `fullname`, `email`, `phone_no`, `department_id`, `password`, `profile_picture`, `role`, `created_at`, `updated_at`) VALUES
(1, 'Super Admin', 'superadmin@selangor.gov.my', '0123456789', 4, '$2y$10$tE6nqLIki9yC8pNEqtBkMefGxWJ102b7jEeWUwpiDZ7pfAKBcHCvC', NULL, 'SuperAdmin', '2026-08-03 15:03:18', '2026-08-18 14:35:34'),
(2, 'Admin', 'admin@selangor.gov.my', '0123456780', 4, '$2y$10$tE6nqLIki9yC8pNEqtBkMefGxWJ102b7jEeWUwpiDZ7pfAKBcHCvC', NULL, 'Admin', '2026-08-03 15:03:18', '2026-08-03 15:19:53'),
(3, 'User', 'user@selangor.gov.my', '0123456781', 4, '$2y$10$tE6nqLIki9yC8pNEqtBkMefGxWJ102b7jEeWUwpiDZ7pfAKBcHCvC', 'assets/uploads/avatars/user_3_1788426065.png', 'User', '2026-08-03 15:03:18', '2026-09-04 16:28:40'),
(5, 'Ahmad Firdaus', 'ahmad.firdaus@selangor.gov.my', '0121111111', 1, '$2y$10$tE6nqLIki9yC8pNEqtBkMefGxWJ102b7jEeWUwpiDZ7pfAKBcHCvC', NULL, 'User', '2026-08-04 14:24:11', '2026-08-04 14:29:35'),
(6, 'Nur Aisyah', 'nur.aisyah@selangor.gov.my', '0122222222', 2, '$2y$10$tE6nqLIki9yC8pNEqtBkMefGxWJ102b7jEeWUwpiDZ7pfAKBcHCvC', NULL, 'User', '2026-08-04 14:24:11', '2026-08-04 14:29:42'),
(7, 'Mohd Faiz', 'mohd.faiz@selangor.gov.my', '0123333334', 4, '$2y$10$tE6nqLIki9yC8pNEqtBkMefGxWJ102b7jEeWUwpiDZ7pfAKBcHCvC', NULL, 'User', '2026-08-04 14:24:11', '2026-08-12 16:16:13'),
(11, 'Fit', 'fit@selangor.gov.my', '1', 4, '$2y$10$dOce5WQMZ8aVqFgrxtEFrePFmX25rSajVu0vZxiKsXCP2lgl/iIcy', 'assets/uploads/avatars/user_11_1787034964.jpg', 'SuperAdmin', '2026-08-18 14:35:16', '2026-09-04 16:28:22'),
(12, 'Nureen Afriena', 'nureen@selangor.gov.my', NULL, 4, '$2y$10$K2zlXMEmQlAXGv3qu.ds4umV29nq0bBvb9G.ClU2SsabYDDJhCSyS', NULL, 'SuperAdmin', '2026-09-04 11:13:21', '2026-09-04 16:28:52'),
(13, 'Atika', 'atika@selangor.gov.my', NULL, 4, '$2y$10$O3fcoCX.P5EeHA4SoR6PyuulZTBoBCvCbguCfURRvFK531ydD81iC', 'assets/uploads/avatars/user_13_1789014307.jpg', 'User', '2026-09-04 11:43:46', '2026-09-10 12:25:07');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `plate_no` varchar(20) NOT NULL,
  `vehicle_name` varchar(100) DEFAULT NULL,
  `vehicle_type` varchar(50) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `road_tax_expiry` date DEFAULT NULL,
  `status` enum('Available','Booked','Maintenance','Inactive') NOT NULL DEFAULT 'Available',
  `description` text DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `road_tax_document` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`vehicle_id`, `plate_no`, `vehicle_name`, `vehicle_type`, `capacity`, `road_tax_expiry`, `status`, `description`, `driver_id`, `road_tax_document`) VALUES
(1, 'BPK1234', 'Toyota Hiace', 'Van', 12, '2027-10-31', 'Available', 'Official transport van', 6, NULL),
(2, 'BQN5678', 'Toyota Vios', 'Sedan', 5, '2027-11-30', 'Available', 'Department vehicle', 2, NULL),
(3, 'WXY8888', 'Perodua Alza', 'MPV', 7, '2026-12-31', 'Inactive', 'Under maintenance', NULL, NULL),
(4, 'JTM2026', 'Proton X70', 'SUV', 5, '2026-08-31', 'Available', 'Management vehicle', 1, NULL),
(5, 'asd15', 'asdfasd', 'adsfasdf', 234, '2026-10-11', 'Available', 'fasdfasdfasf', NULL, 'assets/uploads/road_tax/road_tax_c4a582b2522991c70cd54cb074f00bf0.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_bookings`
--

CREATE TABLE `vehicle_bookings` (
  `booking_id` int(11) NOT NULL,
  `booking_no` varchar(30) NOT NULL,
  `user_id` int(11) NOT NULL,
  `depart_datetime` datetime NOT NULL,
  `return_datetime` datetime DEFAULT NULL,
  `trip_type` enum('One Way','Return') NOT NULL,
  `origin` varchar(150) DEFAULT NULL,
  `destination` varchar(150) DEFAULT NULL,
  `passenger_total` int(11) DEFAULT NULL,
  `passenger_names` text DEFAULT NULL,
  `passenger_memo_path` varchar(255) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `status` enum('Submitted','Driver_Assigned','Driver_Accepted','Approved','Rejected','Cancelled','Completed') NOT NULL DEFAULT 'Submitted',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_bookings`
--

INSERT INTO `vehicle_bookings` (`booking_id`, `booking_no`, `user_id`, `depart_datetime`, `return_datetime`, `trip_type`, `origin`, `destination`, `passenger_total`, `passenger_names`, `passenger_memo_path`, `purpose`, `vehicle_id`, `driver_id`, `status`, `approved_by`, `approved_at`, `created_at`) VALUES
(1, 'VB202608001', 5, '2026-08-10 08:00:00', '2026-08-10 17:00:00', 'Return', 'Shah Alam', 'Putrajaya', 4, NULL, NULL, 'Meeting with ministry officials', 2, 2, 'Completed', 2, '2026-08-05 09:00:00', '2026-09-07 12:35:58'),
(2, 'VB202608002', 6, '2026-08-12 09:00:00', '2026-08-12 18:00:00', 'Return', 'Shah Alam', 'Cyberjaya', 6, NULL, NULL, 'ICT system audit visit', 2, 2, 'Completed', 1, '2026-08-12 15:19:49', '2026-09-07 12:35:58'),
(3, 'VB202608003', 5, '2026-08-15 07:30:00', '2026-08-15 20:00:00', 'Return', 'Shah Alam', 'Johor Bahru', 5, NULL, NULL, 'Data centre inspection', 4, 1, 'Rejected', 2, '2026-08-06 11:30:00', '2026-09-07 12:35:58'),
(4, 'VB202608004', 7, '2026-08-18 08:30:00', '2026-08-18 16:30:00', 'One Way', 'Shah Alam', 'Klang', 3, NULL, NULL, 'Hardware delivery', 2, 2, 'Completed', 2, '2026-08-07 10:15:00', '2026-09-07 12:35:58'),
(8, 'VB2608122EAF7', 3, '2026-08-12 15:33:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Malacca International Airport, Jalan Tunku Abdul Rahman, Taman Mekar, Ayer Keroh', 2, NULL, NULL, 'Bengkel', 2, 2, 'Completed', 1, '2026-08-12 16:01:29', '2026-09-07 12:35:58'),
(9, 'VB2608131275E', 1, '2026-08-17 04:28:00', '2026-08-17 19:28:00', 'Return', 'Wet World, Persiaran Dato Menteri, Section 2, Shah Alam', 'Persiaran Perbandaran, Section 14, Shah Alam, Petaling', 13, NULL, NULL, 'jalan2', NULL, NULL, 'Cancelled', NULL, NULL, '2026-09-07 12:35:58'),
(10, 'VB260813A5054', 3, '2026-08-13 15:32:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Jalan Sultan Ibrahim 9/8, Section 9, Shah Alam, Petaling', 1, NULL, NULL, 'Hardware Delivery', 2, 2, 'Completed', 1, '2026-08-13 16:05:03', '2026-09-07 12:35:58'),
(11, 'VB26081381D84', 3, '2026-08-14 09:00:00', '2026-08-14 11:30:00', 'Return', 'Selangor State Government Secretary Office, Persiaran Raja Muda, Section 6, Shah Alam', 'Stadium JKR, Jalan Sepat 17/57, Malaysian Public Works Department, Section 17', 41, NULL, NULL, 'Latihan Perbarisan', NULL, NULL, 'Cancelled', NULL, NULL, '2026-09-07 12:35:58'),
(12, 'VB2608141DE82', 1, '2026-08-17 08:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Gombak', 4, NULL, NULL, 'Hardware Delivery', 2, 2, 'Completed', 1, '2026-08-17 16:03:29', '2026-09-07 12:35:58'),
(13, 'VB2608175330B', 3, '2026-08-18 08:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'UTC Selangor, 5, Jalan 14/8, Section 14', 1, NULL, NULL, 'Hardware Delivery', 1, 1, 'Completed', 11, '2026-08-18 15:52:10', '2026-09-07 12:35:58'),
(14, 'VB260817ECF72', 1, '2026-08-18 08:00:00', '2026-08-20 17:00:00', 'Return', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Stadium Bukit Jalil, Jalan Barat, Bukit Jalil, Kuala Lumpur', 5, NULL, NULL, 'SUKMA Selangor', 2, 2, 'Completed', 11, '2026-08-18 15:52:14', '2026-09-07 12:35:58'),
(15, 'VB260818FB295', 3, '2026-08-19 08:00:00', '2026-08-19 16:00:00', 'Return', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Bukit Jalil National Stadium, Persiaran Putra, Sri Petaling, Kuala Lumpur', 3, NULL, NULL, 'Hardware Delivery', 4, 1, 'Completed', 11, '2026-08-18 16:32:02', '2026-09-07 12:35:58'),
(16, 'VB260819C5F38', 3, '2026-08-21 08:00:00', '2026-08-21 17:00:00', 'Return', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Pusat Kesihatan UiTM, Jalan Ilmu 1/1, Section 1, Shah Alam', 3, NULL, NULL, 'Hardware Delivery', 4, 1, 'Completed', 11, '2026-08-19 12:13:54', '2026-09-07 12:35:58'),
(17, 'VB260819F2DD9', 3, '2026-08-19 16:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Nuri 6/1, Section 6, Shah Alam', 'Jalan Kelab, New Kajang Garden, Kampung Sungai Jernih, Kajang Municipal Council', 1, '[\"Fitri\"]', NULL, 'Site Visit', 2, 2, 'Completed', 11, '2026-08-19 15:39:09', '2026-09-07 12:35:58'),
(18, 'VB260827845A8', 11, '2026-08-28 14:30:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Stadium Kajang, Jalan Kelab, New Kajang Garden, Kampung Sungai Jernih', 1, '[\"Fitri\"]', NULL, 'Raptai Hari Kemerdekaan', NULL, NULL, 'Rejected', 11, '2026-09-02 09:53:51', '2026-09-07 12:35:58'),
(19, 'VB260902E7DFB', 11, '2026-09-10 08:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'UiTM Marching Field, Section 1, Shah Alam, Petaling', 1, '[\"Fitri\"]', NULL, 'Urusan', 4, 1, 'Approved', 1, '2026-09-03 16:36:17', '2026-09-07 12:35:58'),
(20, 'VB260902AA835', 3, '2026-09-11 08:00:00', '2026-09-11 18:00:00', 'Return', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Putrajaya International Convention Centre, Jalan P5 A/1, Precinct 5, Putrajaya', 1, '[\"Fitri\"]', NULL, 'Stanco', 4, 1, 'Approved', 1, '2026-09-03 16:36:24', '2026-09-03 16:36:24'),
(21, 'VB2609036FB52', 3, '2026-09-04 09:00:00', '2026-09-04 13:00:00', 'Return', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'i-City, Section 7, Shah Alam, Petaling', 3, '[\"nor\",\"ayu\",\"nureen\"]', NULL, 'kursus', NULL, NULL, 'Cancelled', NULL, NULL, '2026-09-07 12:35:58'),
(22, 'VB2609045C49A', 11, '2026-09-04 12:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Central i-City, 1, Persiaran Multimedia, Section 7', 1, '[\"Fitri\"]', NULL, 'Hardware Delivery', 2, 2, 'Completed', 11, '2026-09-04 11:02:00', '2026-09-07 12:35:58'),
(23, 'VB26090450800', 1, '2026-09-05 09:00:00', '2026-09-05 17:00:00', 'Return', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Stadium Kajang, Jalan Kelab, New Kajang Garden, Kampung Sungai Jernih', 1, '[\"k\"]', NULL, 'jBHJ', 4, 1, 'Completed', 1, '2026-09-04 11:28:17', '2026-09-07 12:35:58'),
(24, 'VB260904B6F1C', 13, '2026-09-14 09:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Kompleks Kerajaan Parcel D, Persiaran Sultan Salahuddin Abdul Aziz Shah, Precinct 1, Putrajaya', 2, '[\"ayu\",\"atika\"]', NULL, 'Kursus', 2, 2, 'Approved', 1, '2026-09-04 11:53:20', '2026-09-07 12:35:58'),
(25, 'VB260904F0E01', 11, '2026-09-16 12:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'SMK Seksyen 9, Jalan Tengku Ampuan Rahimah 9/20, Section 9, Shah Alam', 1, '[\"asdfasd\"]', NULL, 'asdfasfas', NULL, NULL, '', NULL, NULL, '2026-09-07 12:35:58'),
(26, 'VB260907606E5', 11, '2026-09-07 14:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Persiaran Raja Muda, Section 5, Shah Alam', 'Setia City Mall, Persiaran Setia Dagang, Section U13, Setia Alam', NULL, NULL, 'assets/uploads/memos/memo_6a9e2f0188a54.pdf', 'fasdfasdf', 1, 6, 'Completed', 11, '2026-09-10 12:11:22', '2026-09-07 12:35:58'),
(27, 'VB260907AFD44', 7, '2026-09-08 14:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Persiaran Raja Muda, Section 6, Shah Alam', 'Setia City Mall, 7, Persiaran Setia Dagang, Section U13', 1, '[\"Mohd Faiz Bin Zamri\"]', NULL, 'Hardware', NULL, NULL, 'Cancelled', NULL, NULL, '2026-09-07 16:10:54'),
(28, 'VB260907C4965', 7, '2026-09-08 08:00:00', '2026-09-08 16:00:00', 'Return', 'Selangor State Government Secretary Office, Persiaran Raja Muda, Section 6, Shah Alam', 'Putrajaya International Convention Centre, Lebuh Gemilang, Precinct 5, Putrajaya', 5, '[\"Ali\",\"Abu\",\"Ahmad\",\"Zamri\",\"Shahrul\"]', NULL, 'asdfasdf', NULL, NULL, '', NULL, NULL, '2026-09-07 16:12:28'),
(29, 'VB2609179274B', 11, '2026-09-18 08:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Decathlon, Persiaran Damai, Section 14, Shah Alam', 1, '[\"asd\"]', NULL, 'asdfasd', NULL, NULL, '', NULL, NULL, '2026-09-17 10:54:34'),
(30, 'VB2609170FEEE', 2, '2026-09-18 12:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'SMK Seksyen 9, Jalan Tengku Ampuan Rahimah 9/20, Section 9, Shah Alam', 1, '[\"asdfasd\"]', NULL, 'asdfasdfas', NULL, NULL, '', NULL, NULL, '2026-09-17 11:08:32'),
(31, 'VB260917B637C', 2, '2026-09-23 12:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Jalan SS 24/1, Taman SEA, SS 23, Petaling Jaya', 1, '[\"asdfasdf\"]', NULL, 'asdfasdf', 4, 1, '', NULL, NULL, '2026-09-17 11:13:02'),
(32, 'VB2609171ADF1', 11, '2026-09-21 12:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Nuri 6/1, Section 6, Shah Alam', 'SMK Seksyen 9, Jalan Tengku Ampuan Rahimah 9/20, Section 9, Shah Alam', 1, '[\"asdfasd\"]', NULL, 'asdfasdfasfd', NULL, NULL, '', NULL, NULL, '2026-09-17 11:28:40'),
(33, 'VB26091702018', 11, '2026-09-18 12:00:00', NULL, 'One Way', 'Selangor State Government Secretary Office, Jalan Takbir 5/1, Section 5, Shah Alam', 'Persiaran Raja Muda, Section 5, Shah Alam, Petaling', 1, '[\"asdfasd\"]', NULL, 'asdfasdf', NULL, NULL, '', NULL, NULL, '2026-09-17 11:58:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_activity_log_user` (`user_id`),
  ADD KEY `idx_activity_log_created_at` (`created_at`),
  ADD KEY `idx_activity_log_module` (`module`);

--
-- Indexes for table `booking_history`
--
ALTER TABLE `booking_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `action_by` (`action_by`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`driver_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `license` (`license`);

--
-- Indexes for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD PRIMARY KEY (`email_id`),
  ADD KEY `booking_no` (`booking_no`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `plate_no` (`plate_no`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indexes for table `vehicle_bookings`
--
ALTER TABLE `vehicle_bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD UNIQUE KEY `booking_no` (`booking_no`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `approved_by` (`approved_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- AUTO_INCREMENT for table `booking_history`
--
ALTER TABLE `booking_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `email_notifications`
--
ALTER TABLE `email_notifications`
  MODIFY `email_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vehicle_bookings`
--
ALTER TABLE `vehicle_bookings`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `fk_activity_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `booking_history`
--
ALTER TABLE `booking_history`
  ADD CONSTRAINT `booking_history_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `vehicle_bookings` (`booking_id`),
  ADD CONSTRAINT `booking_history_ibfk_2` FOREIGN KEY (`action_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `drivers`
--
ALTER TABLE `drivers`
  ADD CONSTRAINT `drivers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD CONSTRAINT `email_notifications_ibfk_1` FOREIGN KEY (`booking_no`) REFERENCES `vehicle_bookings` (`booking_no`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `vehicle_bookings` (`booking_id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`);

--
-- Constraints for table `vehicle_bookings`
--
ALTER TABLE `vehicle_bookings`
  ADD CONSTRAINT `vehicle_bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `vehicle_bookings_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`),
  ADD CONSTRAINT `vehicle_bookings_ibfk_3` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`),
  ADD CONSTRAINT `vehicle_bookings_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
