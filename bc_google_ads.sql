-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 17, 2026 at 09:05 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u278078154_h4Hok`
--

-- --------------------------------------------------------

--
-- Table structure for table `bc_google_ads`
--

CREATE TABLE `bc_google_ads` (
  `id` int(10) UNSIGNED NOT NULL,
  `tag_name` varchar(191) NOT NULL,
  `conversion_id` varchar(50) NOT NULL,
  `lead_label` varchar(100) DEFAULT NULL,
  `contact_label` varchar(100) DEFAULT NULL,
  `schedule_label` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `bc_google_ads`
--

INSERT INTO `bc_google_ads` (`id`, `tag_name`, `conversion_id`, `lead_label`, `contact_label`, `schedule_label`, `is_active`, `created_at`) VALUES
(3, 'Barbara Cleaning', 'AW-17721068726', '', 'n6xkCNqequMbELaZiIJC', '', 1, '2026-01-14 20:44:12');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bc_google_ads`
--
ALTER TABLE `bc_google_ads`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bc_google_ads`
--
ALTER TABLE `bc_google_ads`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
