-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 08, 2026 at 06:51 PM
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
-- Database: `foodpantrydb`
--

-- --------------------------------------------------------

--
-- Table structure for table `dbpersons`
--

DROP TABLE IF EXISTS `dbpersons`;
CREATE TABLE `dbpersons` (
  `personId` int(11) NOT NULL,
  `id` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `force_password_change` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `dbpersons`
--

INSERT INTO `dbpersons` (`personId`, `id`, `first_name`, `last_name`, `email`, `type`, `status`, `password`, `force_password_change`) VALUES
(1, 'vmsroot', 'vmsroot', '', '', 'superadmin', 'Active', '$2y$10$.3p8xvmUqmxNztEzMJQRBesLDwdiRU3xnt/HOcJtsglwsbUk88VTO', 0),
(2, 'admin', 'Administrator', '', '', 'admin', 'Active', '$2y$10$qhso9F9TxAvD2GRsc/M6uORWgz5dHuXlpgwBOsRiOEh3o7CL5Rioq', 0),
(3, 'inventory', 'Inventory', 'Counter', '', 'inventory_counter', 'Active', '$2y$10$kY.eBs46ZjtgbSi/IQyJb.68VUshJsysUQ8lTa4WnLzVibJ1BoQaK', 0),
(4, 'vmsroot2', 'vmsroot2', '', '', 'superadmin', 'Active', '$2y$10$.3p8xvmUqmxNztEzMJQRBesLDwdiRU3xnt/HOcJtsglwsbUk88VTO', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `dbpersons`
--
ALTER TABLE `dbpersons`
  ADD PRIMARY KEY (`personId`),
  ADD UNIQUE KEY `id` (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `dbpersons`
--
ALTER TABLE `dbpersons`
  MODIFY `personId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
