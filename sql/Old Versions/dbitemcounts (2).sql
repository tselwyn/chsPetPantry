-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 12, 2026 at 04:02 AM
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
-- Table structure for table `dbitemcounts`
--

DROP TABLE IF EXISTS `dbitemcounts`;
CREATE TABLE `dbitemcounts` (
  `id` int(11) NOT NULL,
  `inventoryEventId` int(11) NOT NULL,
  `itemCategoryId` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Dumping data for table `dbitemcounts`
--

INSERT INTO `dbitemcounts` (`id`, `inventoryEventId`, `itemCategoryId`, `quantity`) VALUES
(24, 1001, 16, 45),
(25, 1001, 3, 24),
(26, 1001, 21, 15),
(27, 1001, 4, 10),
(28, 1001, 12, 4),
(29, 1001, 9, 22),
(30, 1001, 7, 28),
(31, 1001, 20, 28),
(32, 1001, 18, 10),
(33, 1001, 11, 30),
(34, 1001, 2, 20),
(35, 1001, 24, 20),
(36, 1001, 23, 15),
(37, 1001, 19, 20),
(38, 1001, 15, 14),
(39, 1001, 8, 27),
(40, 1001, 13, 16),
(41, 1001, 25, 6),
(42, 1001, 6, 10),
(43, 1001, 22, 19),
(44, 1001, 5, 31),
(45, 1001, 17, 20),
(46, 1001, 10, 6),
(47, 1001, 14, 8),
(48, 1002, 12, 1),
(49, 1002, 18, 2),
(50, 1002, 23, 2),
(51, 1002, 8, 1),
(52, 1002, 25, 1),
(53, 1002, 6, 2),
(54, 1002, 17, 1),
(55, 1003, 16, 42),
(56, 1003, 3, 20),
(57, 1003, 21, 28),
(58, 1003, 4, 7),
(59, 1003, 12, 2),
(60, 1003, 9, 35),
(61, 1003, 7, 30),
(62, 1003, 20, 30),
(63, 1003, 18, 28),
(64, 1003, 11, 27),
(65, 1003, 2, 13),
(66, 1003, 24, 22),
(67, 1003, 23, 6),
(68, 1003, 19, 17),
(69, 1003, 15, 18),
(70, 1003, 8, 26),
(71, 1003, 13, 17),
(72, 1003, 25, 8),
(73, 1003, 6, 10),
(74, 1003, 22, 16),
(75, 1003, 5, 35),
(76, 1003, 17, 21),
(77, 1003, 10, 1),
(78, 1003, 14, 14),
(79, 1004, 12, 1),
(80, 1004, 11, 1),
(81, 1004, 19, 3),
(82, 1004, 8, 2),
(83, 1004, 13, 2),
(84, 1004, 25, 1),
(85, 1004, 6, 3),
(86, 1004, 10, 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `dbitemcounts`
--
ALTER TABLE `dbitemcounts`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `dbitemcounts`
--
ALTER TABLE `dbitemcounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
