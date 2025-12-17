-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Время создания: Окт 13 2025 г., 14:03
-- Версия сервера: 10.11.13-MariaDB
-- Версия PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `rhusm_mariadb`
--

-- --------------------------------------------------------

--
-- Структура таблицы `tg_broadcast_schedules`
--

CREATE TABLE `tg_broadcast_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope` enum('drop','team') NOT NULL,
  `message` text NOT NULL,
  `time1` time DEFAULT NULL,
  `time2` time DEFAULT NULL,
  `time3` time DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `last_sent_date` date DEFAULT NULL,
  `sent_mask` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `only_inwork` tinyint(1) NOT NULL DEFAULT 0,
  `mask` tinyint(3) UNSIGNED NOT NULL DEFAULT 127,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_sent_at` datetime DEFAULT NULL,
  `sent_today` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Дамп данных таблицы `tg_broadcast_schedules`
--

INSERT INTO `tg_broadcast_schedules` (`id`, `scope`, `message`, `time1`, `time2`, `time3`, `active`, `last_sent_date`, `sent_mask`, `created_at`, `only_inwork`, `mask`, `enabled`, `last_sent_at`, `sent_today`) VALUES
(7, 'drop', 'тест', '13:49:00', NULL, NULL, 1, NULL, 127, '2025-10-13 13:44:44', 1, 127, 1, NULL, 0);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `tg_broadcast_schedules`
--
ALTER TABLE `tg_broadcast_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scope_enabled` (`scope`,`enabled`,`active`),
  ADD KEY `idx_times` (`time1`,`time2`,`time3`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `tg_broadcast_schedules`
--
ALTER TABLE `tg_broadcast_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
