-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 19, 2026 at 04:31 AM
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
-- Table structure for table `dbpalletcounts`
--

DROP TABLE IF EXISTS `dbpalletcounts`;
CREATE TABLE `dbpalletcounts` (
  `id` int(11) NOT NULL,
  `palletEventId` int(11) NOT NULL,
  `itemCategoryId` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `expiration` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Dumping data for table `dbpalletcounts`
--

INSERT INTO `dbpalletcounts` (`id`, `palletEventId`, `itemCategoryId`, `quantity`, `expiration`) VALUES
(2, 3, 2, 1, NULL),
(3, 4, 2, 123, NULL),
(15, 5, 2, 1, NULL),
(16, 5, 3, 5, NULL),
(141, 1, 2, 1, NULL),
(142, 1, 3, 4, NULL),
(143, 1, 21, 1, NULL),
(144, 1, 23, 8, NULL),
(145, 1, 24, 11, NULL),
(146, 1, 25, 1, NULL),
(147, 6, 2, 11, NULL),
(148, 6, 3, 1, NULL),
(149, 6, 20, 1, NULL),
(150, 6, 23, 13, NULL),
(151, 6, 24, 5, NULL),
(157, 13, 2, 3, NULL),
(158, 13, 3, 1, NULL),
(159, 13, 9, 8, NULL),
(160, 13, 20, 5, NULL),
(161, 13, 24, 18, NULL),
(162, 14, 2, 1, NULL),
(163, 14, 4, 2, NULL),
(164, 14, 6, 1, NULL),
(165, 14, 7, 2, NULL),
(166, 14, 9, 2, NULL),
(167, 14, 17, 1, NULL),
(168, 14, 20, 1, NULL),
(169, 14, 25, 1, NULL),
(170, 15, 7, 4, NULL),
(171, 15, 8, 3, NULL),
(172, 15, 9, 3, NULL),
(173, 15, 16, 10, NULL),
(174, 15, 21, 3, NULL),
(178, 17, 14, 34, NULL),
(179, 16, 8, 6, NULL),
(180, 16, 10, 19, NULL),
(181, 16, 21, 14, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `dbpalletcounts`
--
ALTER TABLE `dbpalletcounts`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `dbpalletcounts`
--
ALTER TABLE `dbpalletcounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=219;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
