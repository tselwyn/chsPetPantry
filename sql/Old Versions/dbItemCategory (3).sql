-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 20, 2026 at 05:09 PM
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
-- Table structure for table `dbitemcategory`
--

DROP TABLE IF EXISTS `dbitemcategory`;
CREATE TABLE `dbitemcategory` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `bananaBox` tinyint(1) NOT NULL DEFAULT 0,
  `itemsPerBox` int(11) NOT NULL DEFAULT 1,
  `status` varchar(11) NOT NULL,
  `shopOnly` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dbitemcategory`
--

INSERT INTO `dbitemcategory` (`id`, `name`, `bananaBox`, `itemsPerBox`, `status`) VALUES
(2, 'Beans - Canned', 0, 36, 'Active'),
(3, 'Beans - Dry', 0, 25, 'Active'),
(4, 'Canned Meals', 0, 36, 'Active'),
(5, 'Cereal', 1, 10, 'Active'),
(6, 'Chicken', 0, 36, 'Active'),
(7, 'Corn', 0, 36, 'Active'),
(8, 'Fruit', 0, 36, 'Active'),
(9, 'Green Beans', 0, 36, 'Active'),
(10, 'Jelly', 0, 24, 'Active'),
(11, 'M&C', 1, 55, 'Active'),
(12, 'Mixed Veg', 0, 36, 'Active'),
(13, 'Oatmeal', 1, 12, 'Active'),
(14, 'Oil', 0, 12, 'Active'),
(15, 'Pancake', 1, 12, 'Active'),
(16, 'Pasta', 1, 18, 'Active'),
(17, 'Peanut Butter', 0, 24, 'Active'),
(18, 'Ramen', 1, 96, 'Active'),
(19, 'Snacks', 1, 25, 'Active'),
(20, 'Soup', 0, 36, 'Active'),
(21, 'Spaghetti', 0, 40, 'Active'),
(22, 'Syrup', 0, 12, 'Active'),
(23, 'Tomato - Canned', 0, 36, 'Active'),
(24, 'Tomato - JARS', 0, 12, 'Active'),
(25, 'Tuna', 0, 72, 'Active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `dbitemcategory`
--
ALTER TABLE `dbitemcategory`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `dbitemcategory`
--
ALTER TABLE `dbitemcategory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
