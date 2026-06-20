-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: operations
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
-- Table structure for table `emailids`
--

DROP TABLE IF EXISTS `emailids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `emailids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Address` varchar(300) NOT NULL,
  `Password` varchar(300) NOT NULL,
  `Status` varchar(30) NOT NULL,
  `ModifiedDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `24hrsCount` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `emailids`
--

LOCK TABLES `emailids` WRITE;
/*!40000 ALTER TABLE `emailids` DISABLE KEYS */;
INSERT INTO `emailids` (`id`, `Address`, `Password`, `Status`, `ModifiedDate`, `24hrsCount`) VALUES (1,'','sajin.dasels@gmail.com                                                                                                                                                                                                                                         ','ykkq ocoi vwqs esqm                                                                                                             ','1987-01-30 03:38:08',-1608507360),(2,'','dazzlegacy01@gmail.com                                                                                                                                                                                                                                         ','jezl ejdg ymwt xpsh                                                                                                             ','1987-01-30 03:38:08',-1608507360),(3,'','dazzlegacy007@gmail.com                                                                                                                                                                                                                                        ','tori akjl qwrp iveq                                                                                                             ','1987-01-30 03:38:08',-1608507360),(4,'','dazzlegacy7@gmail.com                                                                                                                                                                                                                                          ','tioi yobe swpk wuhx                                                                                                             ','1987-01-30 03:38:08',-1608507360),(5,'','dazzlegacy0@gmail.com                                                                                                                                                                                                                                          ','turd myxi tyfr yeze                                                                                                             ','1987-01-30 03:38:08',-1608507360),(6,'','dazzlegacy074@gmail.com                                                                                                                                                                                                                                        ','nsvy iwyk foqt jegs                                                                                                             ','1987-01-30 03:38:08',-1608507360);
/*!40000 ALTER TABLE `emailids` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-13 14:45:41
