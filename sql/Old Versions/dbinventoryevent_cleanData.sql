-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 22, 2026 at 11:03 PM
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
-- Table structure for table `dbinventoryevent`
--

DROP TABLE IF EXISTS `dbinventoryevent`;
CREATE TABLE `dbinventoryevent` (
  `id` int(11) NOT NULL,
  `personId` varchar(11) NOT NULL,
  `location` varchar(50) NOT NULL DEFAULT 'Pantry',
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dbinventoryevent`
--

INSERT INTO `dbinventoryevent` (`id`, `personId`, `location`, `date`) VALUES
(1000, '2', 'Warehouse', '2026-01-09'),
(1001, '2', 'Pantry', '2026-01-09'),
(1002, '2', 'Pallet', '2026-01-09'),
(1003, '2', 'Warehouse', '2026-02-04'),
(1004, '2', 'Pantry', '2026-02-04'),
(1005, '2', 'Pallet', '2026-02-04'),
(1006, '2', 'Warehouse', '2026-03-20'),
(1007, '2', 'Pantry', '2026-03-20'),
(1008, '2', 'Pallet', '2026-03-20'),
(1009, '2', 'Warehouse', '2026-03-27'),
(1010, '2', 'Pantry', '2026-03-27'),
(1011, '2', 'Pallet', '2026-03-27'),
(1012, '2', 'Warehouse', '2026-04-08'),
(1013, '2', 'Pantry', '2026-04-08'),
(1014, '2', 'Pallet', '2026-04-08'),
(1015, '2', 'Warehouse', '2026-04-13'),
(1016, '2', 'Pantry', '2026-04-13'),
(1017, '2', 'Pallet', '2026-04-13');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `dbinventoryevent`
--
ALTER TABLE `dbinventoryevent`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `dbinventoryevent`
--
ALTER TABLE `dbinventoryevent`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1018;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
