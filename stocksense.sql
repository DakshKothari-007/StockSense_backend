-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 27, 2026 at 08:30 AM
-- Server version: 10.4.24-MariaDB
-- PHP Version: 8.1.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `stocksense`
--

-- --------------------------------------------------------

--
-- Table structure for table `owner_account`
--

CREATE TABLE `owner_account` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `shop_name` varchar(150) NOT NULL,
  `location` varchar(255) NOT NULL DEFAULT 'Indiranagar, Bengaluru',
  `store_type` varchar(100) NOT NULL DEFAULT 'Neighbourhood supermarket',
  `currency` varchar(50) NOT NULL DEFAULT '₹ INR / IST',
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `owner_account`
--

INSERT INTO `owner_account` (`id`, `full_name`, `shop_name`, `location`, `store_type`, `currency`, `email`, `password`, `created_at`) VALUES
(2, 'Ananya Rao', 'Fresh Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'ananya@example.com', '$2y$10$W8T3pylwM2Db8ClfJSaroeRUSr4Ity7S.p29J3lu/apt3eT3EYecS', '2026-10-26 04:39:32'),
(3, 'daksh', 'Fresh Mart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'daksh1@gmail.com', '$2y$10$1nCja8GhLmBNc3meILbOd.1W0d2SGh8bimtW54TpnokDU.5DaIV0y', '2026-10-26 04:43:23'),
(4, 'Isha', 'Jio Supermart', 'Indiranagar, Bengaluru', 'Neighbourhood supermarket', '₹ INR / IST', 'kotharidaksh@gmail.com', '$2y$10$0tpQXI7/g/T6Jtoj9MvC8egiMBeqv.ZDLxPg039s6gU0ftlx0kG2O', '2026-10-27 05:11:01');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `low_stock_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `ai_recommendations` tinyint(1) NOT NULL DEFAULT 1,
  `daily_sales_summary` tinyint(1) NOT NULL DEFAULT 1,
  `weekly_email_digest` tinyint(1) NOT NULL DEFAULT 0,
  `theme_mode` varchar(20) NOT NULL DEFAULT 'SYSTEM',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_user_settings_owner` FOREIGN KEY (`user_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `low_stock_alerts`, `ai_recommendations`, `daily_sales_summary`, `weekly_email_digest`, `theme_mode`) VALUES
(1, 2, 1, 1, 1, 0, 'SYSTEM'),
(2, 3, 1, 1, 1, 0, 'SYSTEM'),
(3, 4, 1, 1, 1, 0, 'SYSTEM');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
