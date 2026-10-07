-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 04, 2026 at 01:56 AM
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

CREATE TABLE `dbpalletcounts` (
  `id` int(11) NOT NULL,
  `palletEventId` int(11) NOT NULL,
  `itemCategoryId` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Dumping data for table `dbpalletcounts`
--

INSERT INTO `dbpalletcounts` (`id`, `palletEventId`, `itemCategoryId`, `quantity`) VALUES
(2, 3, 2, 1),
(3, 4, 2, 123),
(15, 5, 2, 1),
(16, 5, 3, 5),
(53, 1, 2, 2),
(54, 1, 4, 2),
(55, 1, 6, 2),
(56, 1, 8, 2),
(57, 6, 3, 3),
(58, 6, 5, 3),
(111, 8, 2, 1),
(112, 8, 4, 2),
(113, 8, 6, 4),
(114, 8, 8, 8),
(115, 8, 10, 16),
(116, 8, 12, 32),
(117, 8, 14, 64),
(118, 8, 16, 128),
(119, 8, 18, 256),
(120, 8, 20, 512),
(121, 8, 22, 1024),
(122, 8, 24, 2048);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
