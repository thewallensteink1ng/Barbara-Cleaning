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
-- Table structure for table `bc_pixels`
--

CREATE TABLE `bc_pixels` (
  `id` int(10) UNSIGNED NOT NULL,
  `pixel_id` varchar(32) NOT NULL,
  `pixel_name` varchar(191) NOT NULL,
  `access_token` text DEFAULT NULL,
  `test_code` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `bc_pixels`
--

INSERT INTO `bc_pixels` (`id`, `pixel_id`, `pixel_name`, `access_token`, `test_code`, `is_active`, `created_at`) VALUES
(4, '1267481508729685', '', 'EAAOqjZBgr90YBQIzjtFYTnvZCxScs75ZC57vir0fmu7l9MccZAvP4ZBYcvCaLtRhQ97Yg3iW4iCyPDoNWtbCBfOescEUzDSoN57Jn0ZBjj7qzwX9zKQ7Fm2FWNAZAdQrmOyq18FU0vOpQ6gcSBdusdV8p3mkKKFU1YU4IlZBpLNEO6OiFQUMcvG7ryfXy3tYBQZDZD', NULL, 1, '2026-01-05 21:12:53');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bc_pixels`
--
ALTER TABLE `bc_pixels`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bc_pixels`
--
ALTER TABLE `bc_pixels`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
