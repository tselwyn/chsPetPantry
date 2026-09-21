-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 04, 2026 at 02:19 AM
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
-- Table structure for table `dbpalletevent`
--

CREATE TABLE IF NOT EXISTS `dbpalletevent` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `personId` varchar(11) NOT NULL,
  `date` date NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Add notes column if it doesn't exist
--

ALTER TABLE `dbpalletevent` ADD COLUMN `notes` text DEFAULT NULL;

--
-- Dumping data for table `dbpalletevent`
--

-- Data insertion commented out to avoid duplicate key errors
-- Uncomment the lines below if you need to reimport the data
-- INSERT INTO `dbpalletevent` (`id`, `name`, `personId`, `date`) VALUES
-- (1, 'Pallet', '2', '0000-00-00'),
-- (6, 'Pallet 2', '2', '2026-04-02'),
-- (8, 'Pal  2 Square', '2', '2026-04-02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `dbpalletevent`
--
ALTER TABLE `dbpalletevent`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `dbpalletevent`
--
ALTER TABLE `dbpalletevent`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
