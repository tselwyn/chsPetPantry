-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 27, 2026 at 05:01 PM
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
-- Table structure for table `dbclient`
--

DROP TABLE IF EXISTS `dbclient`;
CREATE TABLE `dbclient`(
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shoppingEventId` varchar(11) NOT NULL,
  `personId` varchar(11) NOT NULL,
  `numClients` float NOT NULL,
  `date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `dbclient` (`shoppingEventId`, `personId`, `numClients`, `date`) VALUES
(1001, '3', 50.32, '2026-01-20'),  -- 1-3 Curb
(1002, '3', 50.32, '2026-01-20'),  -- 1-3 IN HOUSE
(1003, '2', 164.50, '2026-02-04'),  -- 4+ Curb
(1004, '2', 164.50, '2026-02-04'),   -- 4+ IN HOUSE
(1005, '2', 28.00, '2026-02-04');   -- 8+ IN HOUSE

COMMIT;
