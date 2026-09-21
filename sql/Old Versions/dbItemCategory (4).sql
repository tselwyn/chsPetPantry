-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Apr 25, 2026 at 09:19 PM
-- Server version: 8.4.6-6
-- PHP Version: 8.2.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dbevnlo0ng2zde`
--

-- --------------------------------------------------------

--
-- Table structure for table `dbitemcategory`
--

DROP TABLE IF EXISTS `dbitemcategory`;
CREATE TABLE `dbitemcategory` (
  `id` int NOT NULL,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `bananaBox` tinyint(1) NOT NULL DEFAULT '0',
  `itemsPerBox` int NOT NULL DEFAULT '1',
  `status` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `shopOnly` tinyint NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dbitemcategory`
--

INSERT INTO `dbitemcategory` (`id`, `name`, `bananaBox`, `itemsPerBox`, `status`, `shopOnly`) VALUES
(2, 'Beans - Canned', 0, 36, 'Active', 0),
(3, 'Beans - Dry', 0, 25, 'Active', 0),
(4, 'Canned Meals', 0, 36, 'Active', 0),
(5, 'Cereal', 1, 10, 'Active', 0),
(6, 'Chicken', 1, 36, 'Active', 0),
(7, 'Corn', 0, 36, 'Active', 0),
(8, 'Fruit', 0, 36, 'Active', 0),
(9, 'Green Beans', 0, 36, 'Active', 0),
(10, 'Jelly', 0, 24, 'Active', 0),
(11, 'M&C', 1, 55, 'Active', 0),
(12, 'Mixed Veg', 0, 36, 'Active', 0),
(13, 'Oatmeal', 1, 12, 'Active', 0),
(14, 'Oil', 0, 12, 'Active', 0),
(15, 'Pancake', 1, 12, 'Active', 0),
(16, 'Pasta', 1, 18, 'Active', 0),
(17, 'Peanut Butter', 0, 24, 'Active', 0),
(18, 'Ramen', 1, 96, 'Active', 0),
(19, 'Snacks', 1, 25, 'Active', 0),
(20, 'Soup', 0, 36, 'Active', 0),
(21, 'Spaghetti', 0, 40, 'Active', 0),
(22, 'Syrup', 0, 12, 'Active', 0),
(23, 'Tomato - Canned', 0, 36, 'Active', 0),
(24, 'Tomato - JARS', 0, 12, 'Active', 0),
(25, 'Tuna', 0, 72, 'Active', 0),
(26, 'Milk', 1, 2, 'Deleted', 0),
(27, 'Rice', 0, 12, 'Active', 1),
(28, 'Masa Flour', 0, 10, 'Active', 1),
(29, 'White Flour', 0, 12, 'Active', 1),
(30, 'Toilet Paper', 0, 15, 'Inactive', 1),
(31, 'Beef', 0, 5, 'Deleted', 0),
(32, 'Other', 0, 0, 'Active', 1),
(33, 'Baked Beans', 0, 1, 'Active', 1),
(34, 'Other Meats', 0, 1, 'Active', 1),
(35, 'Shelf Stable Milk', 0, 1, 'Active', 1);

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
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
