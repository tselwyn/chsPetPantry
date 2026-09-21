-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
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
-- Table structure for table `dbshoppingcountgroup`
--
-- Run this file once to add item-group support.
-- It also adds the groupId column to dbshoppingcounts.
--

CREATE TABLE IF NOT EXISTS `dbshoppingcountgroup` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shoppingEventId` int(11) NOT NULL,
  `groupName` varchar(100) NOT NULL DEFAULT 'New Group',
  PRIMARY KEY (`id`),
  KEY `idx_shoppingEventId` (`shoppingEventId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

--
-- Add groupId column to dbshoppingcounts (nullable = item is not in a group)
--
ALTER TABLE `dbshoppingcounts`
  ADD COLUMN IF NOT EXISTS `groupId` int(11) DEFAULT NULL,
  ADD KEY IF NOT EXISTS `fk_sc_groupId` (`groupId`);

--
-- Add notes column to dbshoppingcounts (stores per-item comments)
--
ALTER TABLE `dbshoppingcounts`
  ADD COLUMN IF NOT EXISTS `notes` varchar(500) DEFAULT NULL;

--
-- Add excludeFromConsumption column to dbshoppingcounts
-- Controls whether item appears in consumption rate calculations
--
ALTER TABLE `dbshoppingcounts`
  ADD COLUMN IF NOT EXISTS `excludeFromConsumption` tinyint(1) NOT NULL DEFAULT 0;

--
-- Add unique constraint on (shoppingEventId, itemCategoryId) to prevent duplicate basket entries
--
ALTER TABLE `dbshoppingcounts`
  ADD UNIQUE IF NOT EXISTS `unique_event_category` (`shoppingEventId`, `itemCategoryId`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
