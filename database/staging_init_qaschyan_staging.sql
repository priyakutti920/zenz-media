-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: qaschyan_staging
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `qaschyan_staging`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `qaschyan_staging` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `qaschyan_staging`;

--
-- Table structure for table `active_sessions`
--

DROP TABLE IF EXISTS `active_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `active_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `device_type` varchar(50) DEFAULT NULL,
  `browser` varchar(50) DEFAULT NULL,
  `platform` varchar(50) DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `idx_user_role` (`user_id`,`role`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `active_sessions`
--

LOCK TABLES `active_sessions` WRITE;
/*!40000 ALTER TABLE `active_sessions` DISABLE KEYS */;
INSERT INTO `active_sessions` VALUES (54,'4frsal5bar57i8i8mbu63iurnj',1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','Desktop','Google Chrome 155','Windows','2026-10-23 06:17:03','2026-10-23 05:54:38'),(81,'4a76kbn7fc8qil6ehr4oqpdmio',1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','Desktop','Google Chrome 155','Windows','2026-10-23 06:45:03','2026-10-23 06:31:21'),(93,'rq96qqofo9f10hogmumlcer7rn',1,'admin','127.0.0.1','','Desktop','Web Browser','Unknown OS','2026-10-23 07:06:46','2026-10-23 07:06:46'),(94,'b4hgq8cju80fr5mt3sirvghd1u',1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','Desktop','Google Chrome 155','Windows','2026-10-23 07:11:54','2026-10-23 07:07:48'),(102,'k001os5pijlggkvfm2phoq9cp7',1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','Desktop','Google Chrome 155','Windows','2026-10-23 08:22:05','2026-10-23 08:21:48'),(105,'3q5u77rmv8v321hllaehm79qa0',1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','Desktop','Google Chrome 155','Windows','2026-10-23 08:42:17','2026-10-23 08:41:46'),(110,'j5bpn3inlbvg6so8frdqoma8gg',1,'admin','127.0.0.1','','Desktop','Web Browser','Unknown OS','2026-10-23 08:51:57','2026-10-23 08:51:57'),(112,'pnuc4mluscio2468vbuvd414ak',1,'admin','127.0.0.1','','Desktop','Web Browser','Unknown OS','2026-10-23 08:58:32','2026-10-23 08:58:32'),(114,'91ta1v2a95ld784sh91gbtbo5e',1,'admin','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','Desktop','Google Chrome 155','Windows','2026-10-23 09:06:06','2026-10-23 09:06:06');
/*!40000 ALTER TABLE `active_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin`
--

DROP TABLE IF EXISTS `admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin`
--

LOCK TABLES `admin` WRITE;
/*!40000 ALTER TABLE `admin` DISABLE KEYS */;
INSERT INTO `admin` VALUES (1,'admin','cloudaspire2025@gmail.com','$2y$10$Oh0fCBk5bZcbrteDfm63LueINe8ofCajKliXNOXKXVv54VBAgPB/G','2026-06-28 05:07:38');
/*!40000 ALTER TABLE `admin` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_roles`
--

DROP TABLE IF EXISTS `admin_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_roles` (
  `admin_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  PRIMARY KEY (`admin_id`,`role_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `admin_roles_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admin_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_roles`
--

LOCK TABLES `admin_roles` WRITE;
/*!40000 ALTER TABLE `admin_roles` DISABLE KEYS */;
INSERT INTO `admin_roles` VALUES (1,1);
/*!40000 ALTER TABLE `admin_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `punch_history` text DEFAULT NULL,
  `leave_code` varchar(50) DEFAULT NULL,
  `status` enum('present','absent','half_day','late','on_leave') DEFAULT 'present',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `delay_minutes` int(11) DEFAULT 0,
  `early_exit_minutes` int(11) DEFAULT 0,
  `total_work_minutes` int(11) DEFAULT 0,
  `working_hours` decimal(5,2) DEFAULT 0.00,
  `attendance_status` enum('present','delayed','left_early','completed','half_day','absent','on_leave') DEFAULT 'present',
  `remarks` text DEFAULT NULL,
  `work_summary` text DEFAULT NULL,
  `completed_tasks` text DEFAULT NULL,
  `pending_tasks` text DEFAULT NULL,
  `check_in_lat` decimal(10,8) DEFAULT NULL,
  `check_in_lng` decimal(11,8) DEFAULT NULL,
  `check_in_dist` int(11) DEFAULT NULL,
  `check_out_lat` decimal(10,8) DEFAULT NULL,
  `check_out_lng` decimal(11,8) DEFAULT NULL,
  `check_out_dist` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_emp_date` (`employee_id`,`date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (1,12,'2026-09-18','08:55:56','17:00:00','[{\"in\":\"08:55:56\",\"out\":\"17:00:00\"}]',NULL,'present',NULL,'2026-09-18 03:25:56','2026-10-23 06:57:21',0,0,480,8.00,'completed','Auto-closed past unclosed punch',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(2,13,'2026-09-18','08:57:46','17:00:00','[{\"in\":\"08:57:46\",\"out\":\"17:00:00\"}]',NULL,'present',NULL,'2026-09-18 03:27:46','2026-10-23 06:57:21',0,0,480,8.00,'completed','Auto-closed past unclosed punch',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(3,14,'2026-09-18','09:04:10','17:00:00','[{\"in\":\"09:04:10\",\"out\":\"17:00:00\"}]',NULL,'present',NULL,'2026-09-18 03:34:10','2026-10-23 06:57:21',0,0,480,8.00,'completed','Auto-closed past unclosed punch',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(4,15,'2026-09-18','09:05:18','17:00:00','[{\"in\":\"09:05:18\",\"out\":\"17:00:00\"}]',NULL,'present',NULL,'2026-09-18 03:35:18','2026-10-23 06:57:21',0,0,480,8.00,'completed','Auto-closed past unclosed punch',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(5,17,'2026-09-18','09:07:02','17:00:00','[{\"in\":\"09:07:02\",\"out\":\"17:00:00\"}]',NULL,'present',NULL,'2026-09-18 03:37:02','2026-10-23 06:57:21',0,0,480,8.00,'completed','Auto-closed past unclosed punch',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(18,12,'2026-10-23','12:38:18','12:39:11','[{\"in\":\"12:38:18\",\"out\":\"12:39:11\"}]','Family Responsibility','half_day',NULL,'2026-10-23 07:08:18','2026-10-23 07:09:11',168,260,1,0.02,'half_day',NULL,'test','test',NULL,11.95310315,79.81348170,0,11.95310315,79.81348170,0);
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_requests`
--

DROP TABLE IF EXISTS `attendance_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `requested_work_mode` enum('wfh','on_site_exception') NOT NULL DEFAULT 'wfh',
  `reason` text NOT NULL,
  `supporting_details` text DEFAULT NULL,
  `requested_check_in_time` time NOT NULL,
  `approved_check_in_time` time DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled','checked_in','expired') NOT NULL DEFAULT 'pending',
  `approver_id` int(11) DEFAULT NULL,
  `decision_timestamp` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `actual_check_in` time DEFAULT NULL,
  `submitted_lat` decimal(10,8) DEFAULT NULL,
  `submitted_lng` decimal(11,8) DEFAULT NULL,
  `submitted_dist` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp_date` (`employee_id`,`attendance_date`),
  KEY `idx_status` (`status`),
  KEY `idx_date_status` (`attendance_date`,`status`),
  KEY `fk_att_req_approver` (`approver_id`),
  CONSTRAINT `fk_att_req_approver` FOREIGN KEY (`approver_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_att_req_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_requests`
--

LOCK TABLES `attendance_requests` WRITE;
/*!40000 ALTER TABLE `attendance_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `daily_work_summaries`
--

DROP TABLE IF EXISTS `daily_work_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `daily_work_summaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `work_summary` text NOT NULL,
  `completed_tasks` text NOT NULL,
  `pending_tasks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance_summary` (`attendance_id`),
  KEY `idx_attendance` (`attendance_id`),
  KEY `idx_employee_date` (`employee_id`,`date`),
  CONSTRAINT `daily_work_summaries_ibfk_1` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_work_summaries_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `daily_work_summaries`
--

LOCK TABLES `daily_work_summaries` WRITE;
/*!40000 ALTER TABLE `daily_work_summaries` DISABLE KEYS */;
INSERT INTO `daily_work_summaries` VALUES (9,18,12,'2026-10-23','test','test',NULL,'2026-10-23 07:09:11','2026-10-23 07:09:11');
/*!40000 ALTER TABLE `daily_work_summaries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `status` enum('active','inactive','on_leave') DEFAULT 'active',
  `work_mode` enum('on_site','wfh') NOT NULL DEFAULT 'on_site',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `avatar` varchar(255) DEFAULT NULL,
  `aadhar_image` varchar(255) DEFAULT NULL,
  `aadhar_verified` tinyint(1) DEFAULT 0,
  `employee_id` varchar(10) DEFAULT NULL,
  `first_login_completed` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (12,'Prem','Kumar','tamilaninstamedia1@gmail.com','$2y$10$2FI6rX1WvXjYN9MQZqh1B.bo78aBuCydxzn2onwnE5mVjdl8tZpGm','+918098830937','HR','Funder',0.00,'2026-06-29','active','on_site','2026-06-29 02:08:05','2026-10-23 05:11:49','avatar_12_1792732309.png','aadhar_12_1782700791.png',2,'EMP-001',1),(13,'Karthick','Raja','kr8654460@gmail.com','$2y$10$x82W2gGNyLxbiRLZYcP.bu9UAtu1PjMpgqR9K1jiVyYk2Mlu7PZHS','8525813179','Engineering','Co-founder & CEO',0.00,'2026-06-29','active','on_site','2026-06-29 03:09:30','2026-09-13 05:54:47','avatar_13_1782724870.jpg',NULL,0,'EMP-002',0),(14,'Shanmuga','Priya','priya30shanmuga@gmail.com','$2y$10$/s80MXwXvwYth4vU0VGMAOlIQcIR5XgKG.r/2MNOga.egEDYJqFSe','9952426770','Engineering','Juniour Devloper',12000.00,'2026-06-29','active','on_site','2026-06-29 03:12:49','2026-10-23 08:22:32',NULL,'aadhar_14_1782724367.jpg',1,'EMP-003',1),(15,'Jeya','Chandra','jeyachandra185@gmail.com','$2y$10$lBcqo.RyZTiBpndGW3w7ZOzLvYL/Upl2G1bhx.FnUPG9YRVCiFLPa','9087407463','Engineering','Junior SEO Executive',11000.00,'2026-06-29','active','on_site','2026-06-29 03:19:51','2026-09-13 05:54:18',NULL,'aadhar_15_1782727821.jpg',1,'EMP-004',0),(16,'Abirami','.R','abiramiramar07@gmail.com','$2y$10$VjpfHS293atqy9VG5S3lZehOO8iKlH97ofaLL2kDtS5yTmIr98LZe','7502121506','Engineering','Junior  SEO Executive',13500.00,'2026-06-29','active','on_site','2026-06-29 03:23:02','2026-09-10 03:48:20',NULL,'aadhar_16_1782728193.jpg',1,'EMP-005',0),(17,'Kavitha','A','kavikavitha98861@gmail.com','$2y$10$yncLJztNFaCX/OOc.QsyC.m1mJ1MSWVcDNHz2sg5.EJVtDJxt9vm6','7548835595','Engineering','Junior   Assit Devloper',11500.00,'2026-06-29','active','on_site','2026-06-29 03:24:48','2026-10-23 06:43:34','avatar_17_1789703129.jpg','aadhar_17_1789703686.jpg',2,'EMP-006',0),(18,'Priya','Dharshini R','priyatheangel920@gmail.com','$2y$10$1fOaCYLjH.z85.WqmhC3nuGCERO55iUiWr873gZeMDEvytrUliYDK','8111067718','Design','Front-End Devloper',0.00,'2026-06-29','inactive','on_site','2026-06-29 03:27:18','2026-09-18 03:45:06',NULL,NULL,0,'EMP-007',0),(20,'Divyasri','.K','divyakutty6294@gmail.com','$2y$10$Y1lXyiRhl3lo7E/.mNsOv.l7iisRm8vme9.1lQYz/27KOCJULcu7i','733 903 6114','Support','TeleCaller',7000.00,'2026-07-27','active','on_site','2026-07-27 03:07:17','2026-09-18 03:45:27','avatar_20_1785122469.jpg','aadhar_20_1785122462.jpg',1,'EMP-008',0);
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_balances`
--

DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type` enum('annual','sick','casual') NOT NULL,
  `year` int(11) NOT NULL,
  `total_days` int(11) NOT NULL,
  `used_days` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_leave_year` (`employee_id`,`leave_type`,`year`),
  CONSTRAINT `leave_balances_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_balances`
--

LOCK TABLES `leave_balances` WRITE;
/*!40000 ALTER TABLE `leave_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_code_history`
--

DROP TABLE IF EXISTS `leave_code_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_code_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_id` int(11) NOT NULL,
  `leave_code` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `leave_id` (`leave_id`),
  CONSTRAINT `leave_code_history_ibfk_1` FOREIGN KEY (`leave_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_code_history`
--

LOCK TABLES `leave_code_history` WRITE;
/*!40000 ALTER TABLE `leave_code_history` DISABLE KEYS */;
INSERT INTO `leave_code_history` VALUES (1,1,'LV-IWBRE3','used',1,'2026-09-18 03:54:46');
/*!40000 ALTER TABLE `leave_code_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type` enum('annual','sick','casual','early_departure') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days` int(11) NOT NULL,
  `reason` text NOT NULL,
  `early_departure_time` time DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `leave_code` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

LOCK TABLES `leave_requests` WRITE;
/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
INSERT INTO `leave_requests` VALUES (1,20,'casual','2026-09-18','2026-09-18',1,'Asking Persnoal Levae',NULL,'approved',NULL,1,'2026-09-18 03:54:42','LV-IWBRE3','2026-09-18 03:54:42');
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `timestamp` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip`),
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passkey_credentials`
--

DROP TABLE IF EXISTS `passkey_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `passkey_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_type` enum('employee','admin') NOT NULL DEFAULT 'employee',
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `public_key` text NOT NULL,
  `user_handle` varchar(64) DEFAULT NULL,
  `sign_count` bigint(20) unsigned DEFAULT 0,
  `transports` varchar(255) DEFAULT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_credential_id` (`credential_id`),
  KEY `idx_user_type_id` (`user_type`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passkey_credentials`
--

LOCK TABLES `passkey_credentials` WRITE;
/*!40000 ALTER TABLE `passkey_credentials` DISABLE KEYS */;
/*!40000 ALTER TABLE `passkey_credentials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'Employees: View Directory','employees.view','Employees','View employee listings, profile cards, and details','2026-10-23 05:20:26'),(2,'Employees: Create Employee','employees.create','Employees','Add new employees to the workforce directory','2026-10-23 05:20:26'),(3,'Employees: Edit Employee','employees.edit','Employees','Modify employee profile, compensation, and work mode','2026-10-23 05:20:26'),(4,'Employees: Delete Employee','employees.delete','Employees','Remove employees and revoke portal access','2026-10-23 05:20:26'),(5,'Attendance: View Records','attendance.view','Attendance','View daily and monthly attendance logs','2026-10-23 05:20:26'),(6,'Attendance: Manage & Edit','attendance.manage','Attendance','Edit check-in, check-out, status, and manual overrides','2026-10-23 05:20:26'),(7,'Attendance: Review Work Summaries','attendance.review_summaries','Attendance','Review employee daily work summaries and blockers','2026-10-23 05:20:26'),(8,'Attendance: Export CSV','attendance.export','Attendance','Export attendance reports to CSV and spreadsheets','2026-10-23 05:20:26'),(9,'Tasks: View All Tasks','tasks.view','Tasks','View tasks across all departments and employees','2026-10-23 05:20:26'),(10,'Tasks: Create & Assign','tasks.create','Tasks','Create new tasks and assign to employees','2026-10-23 05:20:26'),(11,'Tasks: Manage & Update','tasks.manage','Tasks','Update task status, priority, and verify completion','2026-10-23 05:20:26'),(12,'Projects: View Submissions','projects.view','Projects','Review employee submitted drive links and deliverables','2026-10-23 05:20:26'),(13,'Projects: Manage & Review','projects.manage','Projects','Approve, request changes, or reject submitted projects','2026-10-23 05:20:26'),(14,'Leaves: View Requests','leaves.view','Leaves','View leave applications and calendar balances','2026-10-23 05:20:26'),(15,'Leaves: Approve / Reject','leaves.manage','Leaves','Approve or reject leave requests and generate leave codes','2026-10-23 05:20:26'),(16,'Shifts: View & Configure','shifts.view','Shifts','View shift schedules and grace thresholds','2026-10-23 05:20:26'),(17,'Shifts: Manage Shifts','shifts.manage','Shifts','Create, edit, and activate operational shift timings','2026-10-23 05:20:26'),(18,'Reports: View Analytics','reports.view','Reports','Access executive dashboards, KPI telemetry, and analytics','2026-10-23 05:20:26'),(19,'Reports: Export Reports','reports.export','Reports','Export detailed workforce analytics','2026-10-23 05:20:26'),(20,'Settings: View Settings','settings.view','Settings','View system settings and console configurations','2026-10-23 05:20:26'),(21,'Settings: Manage Settings','settings.manage','Settings','Manage branding, SMTP, and office geofence locations','2026-10-23 05:20:26'),(22,'Roles: View Roles','roles.view','Role Management','View roles and their assigned permission matrices','2026-10-23 05:20:26'),(23,'Roles: Manage Roles','roles.manage','Role Management','Create and edit roles and customize permission allocations','2026-10-23 05:20:26'),(24,'Attendance: Approve / Reject WFH & Exceptions','attendance.approve_requests','Attendance','Authorize or reject employee work from home and on-site location exception requests','2026-10-23 07:19:44');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_status_updates`
--

DROP TABLE IF EXISTS `project_status_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_status_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `status` varchar(40) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_status_updates_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_status_updates`
--

LOCK TABLES `project_status_updates` WRITE;
/*!40000 ALTER TABLE `project_status_updates` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_status_updates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `drive_link` varchar(2048) NOT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projects`
--

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),(1,10),(1,11),(1,12),(1,13),(1,14),(1,15),(1,16),(1,17),(1,18),(1,19),(1,20),(1,21),(1,22),(1,23),(2,1),(2,2),(2,3),(2,5),(2,6),(2,7),(2,8),(2,14),(2,15),(2,16),(2,17),(2,18),(2,19),(2,24),(3,1),(3,5),(3,7),(3,9),(3,10),(3,11),(3,12),(3,13),(3,18),(4,1),(4,5),(4,7),(4,9),(4,12),(4,14),(4,18);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Super Administrator','super_admin','Full root access to all system functions, security controls, and workforce modules.',1,'2026-10-23 05:20:26','2026-10-23 05:20:26'),(2,'HR Operations Manager','hr_manager','Manages employee directory, attendance verification, leave approvals, shifts, and daily work summaries.',0,'2026-10-23 05:20:26','2026-10-23 05:20:26'),(3,'Project Director & Team Lead','team_lead','Assigns and reviews daily employee tasks, project deliverables, and reviews work summaries.',0,'2026-10-23 05:20:26','2026-10-23 05:20:26'),(4,'Department Supervisor','supervisor','Monitors attendance, shifts, and team task deliverables without administrative modification rights.',0,'2026-10-23 05:20:26','2026-10-23 05:20:26');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shift_settings`
--

DROP TABLE IF EXISTS `shift_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `checkin_start` time NOT NULL,
  `checkin_end` time NOT NULL,
  `checkout_time` time NOT NULL,
  `grace_minutes` int(11) DEFAULT 0,
  `late_threshold` int(11) DEFAULT 0,
  `early_exit_threshold` int(11) DEFAULT 0,
  `color` varchar(20) DEFAULT '#4f46e5',
  `is_active` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shift_settings`
--

LOCK TABLES `shift_settings` WRITE;
/*!40000 ALTER TABLE `shift_settings` DISABLE KEYS */;
INSERT INTO `shift_settings` VALUES (1,'Regular Shift',NULL,'09:50:00','10:00:00','17:00:00',10,10,10,'#4f46e5',1,'2026-06-28 16:27:53','2026-09-17 15:49:10');
/*!40000 ALTER TABLE `shift_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_settings`
--

DROP TABLE IF EXISTS `site_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_name` varchar(120) NOT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `brand_color` varchar(20) DEFAULT '#4f46e5',
  `support_email` varchar(255) DEFAULT NULL,
  `support_phone` varchar(50) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `favicon` varchar(255) DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` smallint(5) unsigned NOT NULL DEFAULT 587,
  `smtp_encryption` varchar(10) NOT NULL DEFAULT 'tls',
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` text DEFAULT NULL,
  `smtp_from_email` varchar(255) DEFAULT NULL,
  `smtp_from_name` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `office_name` varchar(150) NOT NULL DEFAULT 'Cloud Aspire Headquarters',
  `office_address` varchar(255) DEFAULT NULL,
  `office_latitude` decimal(10,8) DEFAULT 11.94160000,
  `office_longitude` decimal(11,8) DEFAULT 79.80830000,
  `office_radius_meters` int(11) NOT NULL DEFAULT 100,
  `location_attendance_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `wfh_early_window_minutes` int(11) NOT NULL DEFAULT 15,
  `wfh_grace_period_minutes` int(11) NOT NULL DEFAULT 15,
  `wfh_late_threshold_minutes` int(11) NOT NULL DEFAULT 30,
  `wfh_auto_reschedule_allowed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_settings`
--

LOCK TABLES `site_settings` WRITE;
/*!40000 ALTER TABLE `site_settings` DISABLE KEYS */;
INSERT INTO `site_settings` VALUES (3,'Cloud Aspire',NULL,'#4f46e5','cloudaspire2025@gmail.com','+918098830937','logo-96e285f855212224d62cf669.png','favicon-b9f474fa06bc608e0ac6586c.png','smtp.gmail.com',587,'tls','priyalove920@gmail.com','gcm:40A1zQmlr7I4WoQomzqrEwMgVNoAJ17jMfpdoeBCEhHcHPTrlrmQ13K0IeHJxOo=','priyalove920@gmail.com','Cloud Aspire',1,'2026-06-28 14:31:52','2026-10-23 08:22:05','Cloud Aspire Headquarters',NULL,11.95924032,79.78874445,100,1,15,15,30,0);
/*!40000 ALTER TABLE `site_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('sent','in_progress','verify','completed') DEFAULT 'sent',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tasks`
--

LOCK TABLES `tasks` WRITE;
/*!40000 ALTER TABLE `tasks` DISABLE KEYS */;
/*!40000 ALTER TABLE `tasks` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-23 14:36:23
