-- ========================================================
-- ChatBox SaaS Database Dump for cPanel MySQL
-- Target Database: salutehost_chatbox
-- Generated: 2026-10-13 06:53:58
-- ========================================================

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'agent',
  `avatar_url` text DEFAULT NULL,
  `is_online` tinyint(1) NOT NULL DEFAULT 0,
  `last_active_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tenants`;
CREATE TABLE `tenants` (
  `id` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `plan` varchar(50) NOT NULL DEFAULT 'pro',
  `status` varchar(50) NOT NULL DEFAULT 'active',
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `properties`;
CREATE TABLE `properties` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `site_name` varchar(255) NOT NULL,
  `site_url` varchar(255) NOT NULL,
  `public_key` varchar(64) NOT NULL,
  `secret_key` varchar(64) NOT NULL,
  `allowed_domains` json DEFAULT NULL,
  `widget_config` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `properties_public_key_unique` (`public_key`),
  UNIQUE KEY `properties_secret_key_unique` (`secret_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` char(36) NOT NULL,
  `property_id` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `department_user`;
CREATE TABLE `department_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `visitors`;
CREATE TABLE `visitors` (
  `id` char(36) NOT NULL,
  `property_id` char(36) NOT NULL,
  `visitor_token` varchar(64) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country_code` varchar(10) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `current_page_url` text DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `is_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `visitors_visitor_token_unique` (`visitor_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `conversations`;
CREATE TABLE `conversations` (
  `id` char(36) NOT NULL,
  `property_id` char(36) NOT NULL,
  `visitor_id` char(36) NOT NULL,
  `assigned_agent_id` char(36) DEFAULT NULL,
  `department_id` char(36) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'open',
  `rating` int(11) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id` char(36) NOT NULL,
  `conversation_id` char(36) NOT NULL,
  `sender_type` varchar(50) NOT NULL,
  `sender_id` char(36) DEFAULT NULL,
  `message_type` varchar(50) NOT NULL DEFAULT 'text',
  `content` text NOT NULL,
  `attachment_url` text DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `canned_responses`;
CREATE TABLE `canned_responses` (
  `id` char(36) NOT NULL,
  `property_id` char(36) NOT NULL,
  `shortcut` varchar(50) NOT NULL,
  `content` text NOT NULL,
  `created_by` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `faqs`;
CREATE TABLE `faqs` (
  `id` char(36) NOT NULL,
  `property_id` char(36) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `personal_access_tokens`;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` char(36) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache`;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `tenants`
INSERT INTO `tenants` (`id`, `name`, `email`, `plan`, `status`, `settings`, `created_at`, `updated_at`) VALUES ('01a125ab-f6ff-7332-8eaa-50ca81fca19b', 'ChatBox Technologies', 'contact@chatbox.com', 'enterprise', 'active', '{\"timezone\":\"Asia\\/Kolkata\",\"language\":\"en\"}', '2026-10-10 11:56:29', '2026-10-10 11:56:29');
INSERT INTO `tenants` (`id`, `name`, `email`, `plan`, `status`, `settings`, `created_at`, `updated_at`) VALUES ('01a125f2-0fad-722b-a168-a67bdbc43bab', 'Tata Motors Tech', 'rajesh.sharma@tatamotors.com', 'enterprise', 'active', '{\"timezone\":\"UTC\",\"language\":\"en\"}', '2026-10-10 13:13:03', '2026-10-10 13:13:03');
INSERT INTO `tenants` (`id`, `name`, `email`, `plan`, `status`, `settings`, `created_at`, `updated_at`) VALUES ('01a125f2-e8a5-7183-a906-ddf28dd53e96', 'Tata Motors Tech 5864', 'rajesh.5864@tatamotors.com', 'enterprise', 'active', '{\"timezone\":\"UTC\",\"language\":\"en\"}', '2026-10-10 13:13:58', '2026-10-10 13:13:58');
INSERT INTO `tenants` (`id`, `name`, `email`, `plan`, `status`, `settings`, `created_at`, `updated_at`) VALUES ('01a13395-8196-725a-99db-e154a6eeb952', 'Tata Motors Tech 7554', 'rajesh.7554@tatamotors.com', 'enterprise', 'active', '{\"timezone\":\"UTC\",\"language\":\"en\"}', '2026-10-13 04:46:38', '2026-10-13 04:46:38');

-- Dumping data for table `properties`
INSERT INTO `properties` (`id`, `tenant_id`, `site_name`, `site_url`, `public_key`, `secret_key`, `allowed_domains`, `widget_config`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125ab-f9bc-716e-b768-fc08c0639810', '01a125ab-f6ff-7332-8eaa-50ca81fca19b', 'ChatBox Live Cloud', 'https://chatbox.live', 'pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD', 'sk_live_chatbox_mzpwxmZGQNKofTMArAexEhrU', '[\"localhost\",\"127.0.0.1\",\"chatbox.live\"]', '{\"primary_color\":\"#2563eb\",\"welcome_message\":\"Namaste! Welcome to ChatBox Support. How can we help you today?\",\"logo_url\":\"https:\\/\\/via.placeholder.com\\/64x64.png?text=CB\",\"position\":\"right\",\"require_email\":true,\"require_name\":true}', 1, '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `properties` (`id`, `tenant_id`, `site_name`, `site_url`, `public_key`, `secret_key`, `allowed_domains`, `widget_config`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125f2-1096-72d1-8c54-f171b77bdf26', '01a125f2-0fad-722b-a168-a67bdbc43bab', 'Tata Motors EV Portal', 'https://ev.tatamotors.com', 'pk_live_tata-motors-tech_pOPsDZNuS3ToDwlh', 'sk_live_tata-motors-tech_WNDjx4AU48OXRVzYLs9IVPFu', '[\"*\"]', '{\"primary_color\":\"#2563eb\",\"welcome_message\":\"Hello! Welcome to Tata Motors Tech. How can we help you?\",\"position\":\"right\",\"require_email\":true,\"require_name\":true}', 1, '2026-10-10 13:13:03', '2026-10-10 13:13:03');
INSERT INTO `properties` (`id`, `tenant_id`, `site_name`, `site_url`, `public_key`, `secret_key`, `allowed_domains`, `widget_config`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125f2-e990-73f1-b94e-294f808bb0ea', '01a125f2-e8a5-7183-a906-ddf28dd53e96', 'Tata Motors EV Portal', 'https://ev.tatamotors.com', 'pk_live_tata-motors-tech-5864_xSIIRMhWq0hVJKsg', 'sk_live_tata-motors-tech-5864_QoMgiuPhT1DiEX5Ph76Mnkjt', '[\"*\"]', '{\"primary_color\":\"#2563eb\",\"welcome_message\":\"Hello! Welcome to Tata Motors Tech 5864. How can we help you?\",\"position\":\"right\",\"require_email\":true,\"require_name\":true}', 1, '2026-10-10 13:13:58', '2026-10-10 13:13:58');
INSERT INTO `properties` (`id`, `tenant_id`, `site_name`, `site_url`, `public_key`, `secret_key`, `allowed_domains`, `widget_config`, `is_active`, `created_at`, `updated_at`) VALUES ('01a13395-851b-70d4-adf2-8639c4b1f2fa', '01a13395-8196-725a-99db-e154a6eeb952', 'Tata Motors EV Portal', 'https://ev.tatamotors.com', 'pk_live_tata-motors-tech-7554_tDfxUzhlyPrpy5c6', 'sk_live_tata-motors-tech-7554_lmuy8XBkMW6r6A5EkBUNTsNn', '[\"*\"]', '{\"primary_color\":\"#2563eb\",\"welcome_message\":\"Hello! Welcome to Tata Motors Tech 7554. How can we help you?\",\"position\":\"right\",\"require_email\":true,\"require_name\":true}', 1, '2026-10-13 04:46:39', '2026-10-13 04:46:39');

-- Dumping data for table `users`
INSERT INTO `users` (`id`, `tenant_id`, `name`, `email`, `email_verified_at`, `password`, `role`, `avatar_url`, `is_online`, `last_active_at`, `remember_token`, `created_at`, `updated_at`) VALUES ('01a125ab-f6f8-7350-983b-efd6de77fab4', NULL, 'Platform Super Admin', 'ambitionmanagements@gmail.com', NULL, '$2y$12$k42nzVJ/hrfHx8HFDcdJeecG/o7HnmSNyvXNH57G5YLZOthYXHgjW', 'super_admin', NULL, 1, '2026-10-13 05:33:39', NULL, '2026-10-10 11:56:29', '2026-10-13 05:33:39');
INSERT INTO `users` (`id`, `tenant_id`, `name`, `email`, `email_verified_at`, `password`, `role`, `avatar_url`, `is_online`, `last_active_at`, `remember_token`, `created_at`, `updated_at`) VALUES ('01a125ab-f7f3-73c7-a202-803c31808715', '01a125ab-f6ff-7332-8eaa-50ca81fca19b', 'Rohit (Owner)', 'admin@chatbox.com', NULL, '$2y$12$TaF9CNXQ8WJWXcsxt4ROie37LUxNknABNF4QOZxB5x9CR10STcH9m', 'admin', NULL, 0, '2026-10-13 04:46:16', NULL, '2026-10-10 11:56:29', '2026-10-13 05:18:29');
INSERT INTO `users` (`id`, `tenant_id`, `name`, `email`, `email_verified_at`, `password`, `role`, `avatar_url`, `is_online`, `last_active_at`, `remember_token`, `created_at`, `updated_at`) VALUES ('01a125ab-f8d8-71fc-aab3-6b6a8e8d990c', '01a125ab-f6ff-7332-8eaa-50ca81fca19b', 'Rahul Verma', 'rahul@chatbox.com', NULL, '$2y$12$mRRJhItZFx2tQGQrUCCKc.1b2DORyjgukaRcfMHL0.yeMDFAy5R2y', 'agent', NULL, 1, '2026-10-10 11:56:29', NULL, '2026-10-10 11:56:29', '2026-10-10 11:56:29');
INSERT INTO `users` (`id`, `tenant_id`, `name`, `email`, `email_verified_at`, `password`, `role`, `avatar_url`, `is_online`, `last_active_at`, `remember_token`, `created_at`, `updated_at`) VALUES ('01a125ab-f9ba-7355-9993-918a1e0aa2ae', '01a125ab-f6ff-7332-8eaa-50ca81fca19b', 'Priya Patel', 'priya@chatbox.com', NULL, '$2y$12$BoH5ohsgZ5AcTWMJZ0NB5Ogs/v.iGnL60bRLSYlipAiVFVyjeCY2e', 'agent', NULL, 0, '2026-10-10 11:41:30', NULL, '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `users` (`id`, `tenant_id`, `name`, `email`, `email_verified_at`, `password`, `role`, `avatar_url`, `is_online`, `last_active_at`, `remember_token`, `created_at`, `updated_at`) VALUES ('01a125f2-1094-73c9-88e7-4b665ec62a81', '01a125f2-0fad-722b-a168-a67bdbc43bab', 'Rajesh Sharma', 'rajesh.sharma@tatamotors.com', NULL, '$2y$12$p24DZsrUD07Eao3cBtnR0u4gIBw4ESUmEivIsPDiH1KiDAc/NuoP.', 'admin', NULL, 0, NULL, NULL, '2026-10-10 13:13:03', '2026-10-10 13:13:03');
INSERT INTO `users` (`id`, `tenant_id`, `name`, `email`, `email_verified_at`, `password`, `role`, `avatar_url`, `is_online`, `last_active_at`, `remember_token`, `created_at`, `updated_at`) VALUES ('01a125f2-e98e-73fb-9ea8-d9930a89b84a', '01a125f2-e8a5-7183-a906-ddf28dd53e96', 'Rajesh Sharma', 'rajesh.5864@tatamotors.com', NULL, '$2y$12$l5Oqn8oh1DGuTEEUNzova.GEnh6.tyxxPvedffuJA4v9aBTfp0Fda', 'admin', NULL, 0, NULL, NULL, '2026-10-10 13:13:58', '2026-10-10 13:13:58');
INSERT INTO `users` (`id`, `tenant_id`, `name`, `email`, `email_verified_at`, `password`, `role`, `avatar_url`, `is_online`, `last_active_at`, `remember_token`, `created_at`, `updated_at`) VALUES ('01a13395-8516-72d1-8026-a1c555ca5d89', '01a13395-8196-725a-99db-e154a6eeb952', 'Rajesh Sharma', 'rajesh.7554@tatamotors.com', NULL, '$2y$12$rxL5MeumvL.4iWwJPdLdPuxlK9rt.PDvHvNiSVgxgveO992NfiRHu', 'admin', NULL, 0, NULL, NULL, '2026-10-13 04:46:39', '2026-10-13 04:46:39');

-- Dumping data for table `departments`
INSERT INTO `departments` (`id`, `property_id`, `name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125ab-f9c0-7310-9eb7-e117385d511b', '01a125ab-f9bc-716e-b768-fc08c0639810', 'Technical Support', 'Server issues, cPanel, SSL & DNS queries', 1, '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `departments` (`id`, `property_id`, `name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125ab-f9c0-7310-9eb7-e11738f7ea24', '01a125ab-f9bc-716e-b768-fc08c0639810', 'Billing & Invoicing', 'Payment failures, renewals & invoices', 1, '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `departments` (`id`, `property_id`, `name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125ab-f9c1-7354-9ab9-446251b85331', '01a125ab-f9bc-716e-b768-fc08c0639810', 'Sales', 'New plans, migrations & VPS quotes', 1, '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `departments` (`id`, `property_id`, `name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125f2-1098-7019-8812-8025e16c766e', '01a125f2-1096-72d1-8c54-f171b77bdf26', 'General Support', 'General inquiries', 1, '2026-10-10 13:13:03', '2026-10-10 13:13:03');
INSERT INTO `departments` (`id`, `property_id`, `name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('01a125f2-e992-71f5-8067-bfc043f40592', '01a125f2-e990-73f1-b94e-294f808bb0ea', 'General Support', 'General inquiries', 1, '2026-10-10 13:13:58', '2026-10-10 13:13:58');
INSERT INTO `departments` (`id`, `property_id`, `name`, `description`, `is_active`, `created_at`, `updated_at`) VALUES ('01a13395-852e-7166-a26d-e6fd6ea6711b', '01a13395-851b-70d4-adf2-8639c4b1f2fa', 'General Support', 'General inquiries', 1, '2026-10-13 04:46:39', '2026-10-13 04:46:39');

-- Dumping data for table `department_user`
INSERT INTO `department_user` (`id`, `department_id`, `user_id`, `created_at`, `updated_at`) VALUES (1, '01a125ab-f9c0-7310-9eb7-e117385d511b', '01a125ab-f7f3-73c7-a202-803c31808715', NULL, NULL);
INSERT INTO `department_user` (`id`, `department_id`, `user_id`, `created_at`, `updated_at`) VALUES (2, '01a125ab-f9c0-7310-9eb7-e11738f7ea24', '01a125ab-f7f3-73c7-a202-803c31808715', NULL, NULL);
INSERT INTO `department_user` (`id`, `department_id`, `user_id`, `created_at`, `updated_at`) VALUES (3, '01a125ab-f9c1-7354-9ab9-446251b85331', '01a125ab-f7f3-73c7-a202-803c31808715', NULL, NULL);
INSERT INTO `department_user` (`id`, `department_id`, `user_id`, `created_at`, `updated_at`) VALUES (4, '01a125ab-f9c0-7310-9eb7-e117385d511b', '01a125ab-f8d8-71fc-aab3-6b6a8e8d990c', NULL, NULL);
INSERT INTO `department_user` (`id`, `department_id`, `user_id`, `created_at`, `updated_at`) VALUES (5, '01a125ab-f9c1-7354-9ab9-446251b85331', '01a125ab-f8d8-71fc-aab3-6b6a8e8d990c', NULL, NULL);
INSERT INTO `department_user` (`id`, `department_id`, `user_id`, `created_at`, `updated_at`) VALUES (6, '01a125ab-f9c0-7310-9eb7-e11738f7ea24', '01a125ab-f9ba-7355-9993-918a1e0aa2ae', NULL, NULL);

-- Dumping data for table `canned_responses`
INSERT INTO `canned_responses` (`id`, `property_id`, `shortcut`, `content`, `created_by`, `created_at`, `updated_at`) VALUES ('01a125ab-f9ca-72df-9fad-fce096e226c6', '01a125ab-f9bc-716e-b768-fc08c0639810', '/greet', 'Hello! Welcome to ChatBox. My name is {agent_name}. How may I help you today?', '01a125ab-f7f3-73c7-a202-803c31808715', '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `canned_responses` (`id`, `property_id`, `shortcut`, `content`, `created_by`, `created_at`, `updated_at`) VALUES ('01a125ab-f9cb-711c-acc4-629313efd698', '01a125ab-f9bc-716e-b768-fc08c0639810', '/pricing', 'Our plans start from just ₹499/mo with NVMe storage and 99.99% uptime guarantee!', '01a125ab-f7f3-73c7-a202-803c31808715', '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `canned_responses` (`id`, `property_id`, `shortcut`, `content`, `created_by`, `created_at`, `updated_at`) VALUES ('01a125ab-f9cc-71a5-a4bc-c01c6b5402d0', '01a125ab-f9bc-716e-b768-fc08c0639810', '/bye', 'Thank you for contacting ChatBox Support! Have a wonderful day ahead.', '01a125ab-f7f3-73c7-a202-803c31808715', '2026-10-10 11:56:30', '2026-10-10 11:56:30');

-- Dumping data for table `faqs`
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e02c-73e8-9c37-352339a1a158', '01a125ab-f9bc-716e-b768-fc08c0639810', 'Billing', 'What is your refund policy?', 'We offer a 100% money-back guarantee within 14 days of purchase. No questions asked.', 1, 1, '2026-10-13 04:55:46', '2026-10-13 04:55:46');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e035-73d9-96f4-6806e55103f2', '01a125ab-f9bc-716e-b768-fc08c0639810', 'Technical', 'How long does server setup take?', 'Cloud instances are deployed automatically within 60 seconds after payment confirmation.', 2, 1, '2026-10-13 04:55:46', '2026-10-13 04:55:46');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e038-71e5-bbf8-92f7604149c6', '01a125ab-f9bc-716e-b768-fc08c0639810', 'General', 'How can I contact human support?', 'You can start a live chat right here, or email support@ambitionhost.com 24/7.', 3, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e040-70b9-8a88-75e5022373ac', '01a125f2-1096-72d1-8c54-f171b77bdf26', 'Billing', 'What is your refund policy?', 'We offer a 100% money-back guarantee within 14 days of purchase. No questions asked.', 1, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e043-7397-979f-c35d50ba711d', '01a125f2-1096-72d1-8c54-f171b77bdf26', 'Technical', 'How long does server setup take?', 'Cloud instances are deployed automatically within 60 seconds after payment confirmation.', 2, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e046-735d-bda2-859ad8ed0ff6', '01a125f2-1096-72d1-8c54-f171b77bdf26', 'General', 'How can I contact human support?', 'You can start a live chat right here, or email support@ambitionhost.com 24/7.', 3, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e04a-7077-86a4-709e15005122', '01a125f2-e990-73f1-b94e-294f808bb0ea', 'Billing', 'What is your refund policy?', 'We offer a 100% money-back guarantee within 14 days of purchase. No questions asked.', 1, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e04d-732d-bd4f-f345e7766e28', '01a125f2-e990-73f1-b94e-294f808bb0ea', 'Technical', 'How long does server setup take?', 'Cloud instances are deployed automatically within 60 seconds after payment confirmation.', 2, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e051-73c4-9b98-edb91ef03a8d', '01a125f2-e990-73f1-b94e-294f808bb0ea', 'General', 'How can I contact human support?', 'You can start a live chat right here, or email support@ambitionhost.com 24/7.', 3, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e055-7095-ab5f-aabbd20f9471', '01a13395-851b-70d4-adf2-8639c4b1f2fa', 'Billing', 'What is your refund policy?', 'We offer a 100% money-back guarantee within 14 days of purchase. No questions asked.', 1, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e057-7094-a9a4-2ac66a237cda', '01a13395-851b-70d4-adf2-8639c4b1f2fa', 'Technical', 'How long does server setup take?', 'Cloud instances are deployed automatically within 60 seconds after payment confirmation.', 2, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a1339d-e059-706a-883a-dc3395cea5c9', '01a13395-851b-70d4-adf2-8639c4b1f2fa', 'General', 'How can I contact human support?', 'You can start a live chat right here, or email support@ambitionhost.com 24/7.', 3, 1, '2026-10-13 04:55:47', '2026-10-13 04:55:47');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a133a5-c58b-723d-a0f2-5763d9a380d4', '01a125ab-f9bc-716e-b768-fc08c0639810', 'Support', 'How can I reach emergency support?', 'You can reach emergency phone support at +1-800-CHATBOX or click Start Live Chat below.', 4, 1, '2026-10-13 05:04:24', '2026-10-13 05:04:24');
INSERT INTO `faqs` (`id`, `property_id`, `category`, `question`, `answer`, `order`, `is_active`, `created_at`, `updated_at`) VALUES ('01a133c6-97c0-7104-86f8-6773dbd63cd3', '01a125ab-f9bc-716e-b768-fc08c0639810', 'Support', 'How can I connect with a live agent?', 'Click the \"Need more help? Start chat\" button anytime in this widget to talk directly with an online representative.', 1, 1, '2026-10-13 05:40:15', '2026-10-13 05:40:15');

-- Dumping data for table `visitors`
INSERT INTO `visitors` (`id`, `property_id`, `visitor_token`, `name`, `email`, `phone`, `ip_address`, `city`, `country_code`, `browser`, `os`, `current_page_url`, `last_seen_at`, `is_blocked`, `metadata`, `created_at`, `updated_at`) VALUES ('01a125ab-f9ce-70ae-9731-312be379e429', '01a125ab-f9bc-716e-b768-fc08c0639810', 'vtok_xwiwGWHO3F9Nx0JRxUHsBZoXytPXLtNz', 'Amit Kumar', 'amit@example.com', '+919876543210', '127.0.0.1', 'Mumbai', 'IN', 'Chrome', 'Windows', 'https://chatbox.live/pricing', '2026-10-10 11:56:30', 0, NULL, '2026-10-10 11:56:30', '2026-10-13 05:06:42');
INSERT INTO `visitors` (`id`, `property_id`, `visitor_token`, `name`, `email`, `phone`, `ip_address`, `city`, `country_code`, `browser`, `os`, `current_page_url`, `last_seen_at`, `is_blocked`, `metadata`, `created_at`, `updated_at`) VALUES ('01a125b0-b17e-71e1-8640-d13b60ef85dc', '01a125ab-f9bc-716e-b768-fc08c0639810', 'vtok_5CBu3aSLo2xn2kxlnOayrFlW8Trr9z0m', 'Deepak Pandey', 'support@ambitionhost.com', NULL, '127.0.0.1', NULL, NULL, 'Chrome', 'Win32', 'http://127.0.0.1:8000/demo.html?pk=pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD', '2026-10-13 06:53:20', 0, '{\"lead_status\":\"new\",\"initial_inquiry\":\"fghgf\"}', '2026-10-10 12:01:39', '2026-10-13 06:53:20');
INSERT INTO `visitors` (`id`, `property_id`, `visitor_token`, `name`, `email`, `phone`, `ip_address`, `city`, `country_code`, `browser`, `os`, `current_page_url`, `last_seen_at`, `is_blocked`, `metadata`, `created_at`, `updated_at`) VALUES ('01a125f2-ea5f-7114-bf7f-00617ff7cde7', '01a125f2-e990-73f1-b94e-294f808bb0ea', 'vtok_RhjZhkai0Tcj4hNPmnhMBKM5gvibMwtp', 'Anil Verma', 'anil@gmail.com', NULL, '127.0.0.1', NULL, NULL, NULL, NULL, NULL, '2026-10-10 13:13:59', 0, NULL, '2026-10-10 13:13:59', '2026-10-10 13:13:59');
INSERT INTO `visitors` (`id`, `property_id`, `visitor_token`, `name`, `email`, `phone`, `ip_address`, `city`, `country_code`, `browser`, `os`, `current_page_url`, `last_seen_at`, `is_blocked`, `metadata`, `created_at`, `updated_at`) VALUES ('01a13395-8afe-7375-9902-fdfccedfed92', '01a13395-851b-70d4-adf2-8639c4b1f2fa', 'vtok_HYFOJaNiIBIpoHMB7cyqLNgJg2N2sc2q', 'Anil Verma', 'anil@gmail.com', NULL, '127.0.0.1', NULL, NULL, NULL, NULL, NULL, '2026-10-13 04:46:40', 0, NULL, '2026-10-13 04:46:40', '2026-10-13 04:46:40');
INSERT INTO `visitors` (`id`, `property_id`, `visitor_token`, `name`, `email`, `phone`, `ip_address`, `city`, `country_code`, `browser`, `os`, `current_page_url`, `last_seen_at`, `is_blocked`, `metadata`, `created_at`, `updated_at`) VALUES ('01a13396-23b7-7379-81a3-e136a9fdc6fc', '01a125ab-f9bc-716e-b768-fc08c0639810', 'vtok_iuTTP0gcezBYbeOadaW7jy91o6YFk1CF', 'Deepak Pandey', 'support@ambitionhost.com', NULL, '127.0.0.1', NULL, NULL, NULL, NULL, NULL, '2026-10-13 04:47:20', 0, '{\"lead_status\":\"new\",\"initial_inquiry\":\"hello\"}', '2026-10-13 04:47:19', '2026-10-13 04:47:20');
INSERT INTO `visitors` (`id`, `property_id`, `visitor_token`, `name`, `email`, `phone`, `ip_address`, `city`, `country_code`, `browser`, `os`, `current_page_url`, `last_seen_at`, `is_blocked`, `metadata`, `created_at`, `updated_at`) VALUES ('01a1339c-cbe3-71dd-970b-c422ae1597f5', '01a125ab-f9bc-716e-b768-fc08c0639810', 'vtok_VuGP1blt9na7Y4xNthRdlhsboSshHETH', NULL, NULL, NULL, '127.0.0.1', NULL, NULL, 'Chrome', 'Win32', 'http://127.0.0.1:8000/demo.html', '2026-10-13 04:54:36', 0, NULL, '2026-10-13 04:54:36', '2026-10-13 04:54:36');

-- Dumping data for table `conversations`
INSERT INTO `conversations` (`id`, `property_id`, `visitor_id`, `assigned_agent_id`, `department_id`, `status`, `last_message_at`, `closed_at`, `created_at`, `updated_at`) VALUES ('01a125ab-f9cf-71fd-b8c8-36e9a97a5378', '01a125ab-f9bc-716e-b768-fc08c0639810', '01a125ab-f9ce-70ae-9731-312be379e429', '01a125ab-f8d8-71fc-aab3-6b6a8e8d990c', '01a125ab-f9c1-7354-9ab9-446251b85331', 'open', '2026-10-10 11:56:30', NULL, '2026-10-10 11:56:30', '2026-10-10 11:56:30');
INSERT INTO `conversations` (`id`, `property_id`, `visitor_id`, `assigned_agent_id`, `department_id`, `status`, `last_message_at`, `closed_at`, `created_at`, `updated_at`) VALUES ('01a1338b-4937-70a6-91b0-220b0d7b8166', '01a125ab-f9bc-716e-b768-fc08c0639810', '01a125b0-b17e-71e1-8640-d13b60ef85dc', '01a125ab-f6f8-7350-983b-efd6de77fab4', '01a125ab-f9c0-7310-9eb7-e117385d511b', 'open', '2026-10-13 04:35:28', NULL, '2026-10-13 04:35:28', '2026-10-13 04:55:32');
INSERT INTO `conversations` (`id`, `property_id`, `visitor_id`, `assigned_agent_id`, `department_id`, `status`, `last_message_at`, `closed_at`, `created_at`, `updated_at`) VALUES ('01a13396-26f8-7162-904b-3a43ff1ffc75', '01a125ab-f9bc-716e-b768-fc08c0639810', '01a13396-23b7-7379-81a3-e136a9fdc6fc', '01a125ab-f7f3-73c7-a202-803c31808715', '01a125ab-f9c0-7310-9eb7-e117385d511b', 'open', '2026-10-13 04:54:54', NULL, '2026-10-13 04:47:20', '2026-10-13 04:54:54');
INSERT INTO `conversations` (`id`, `property_id`, `visitor_id`, `assigned_agent_id`, `department_id`, `status`, `last_message_at`, `closed_at`, `created_at`, `updated_at`) VALUES ('01a1339c-a5c1-7046-9276-e47647dda7f8', '01a125ab-f9bc-716e-b768-fc08c0639810', '01a125b0-b17e-71e1-8640-d13b60ef85dc', NULL, '01a125ab-f9c0-7310-9eb7-e117385d511b', 'closed', '2026-10-13 04:55:03', '2026-10-13 04:55:30', '2026-10-13 04:54:26', '2026-10-13 04:55:30');

-- Dumping data for table `messages`
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a125ab-f9d0-733b-b114-1c30ef035db0', '01a125ab-f9cf-71fd-b8c8-36e9a97a5378', 'visitor', NULL, 'text', 'Hi, does the Cloud VPS plan support PHP 8.2 and Redis cache?', NULL, 1, NULL, '2026-10-10 11:54:30', '2026-10-10 11:56:30');
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a125ab-f9d1-7178-ab4a-16205668c0f4', '01a125ab-f9cf-71fd-b8c8-36e9a97a5378', 'agent', '01a125ab-f8d8-71fc-aab3-6b6a8e8d990c', 'text', 'Hello Amit! Yes, absolutely. All our Cloud VPS setups come with full root access, PHP 8.2/8.3, and pre-configured Redis caching.', NULL, 1, NULL, '2026-10-10 11:55:30', '2026-10-10 11:56:30');
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a1338b-4940-714f-82ae-e3d6fd48a8fa', '01a1338b-4937-70a6-91b0-220b0d7b8166', 'visitor', NULL, 'text', 'hello', NULL, 1, NULL, '2026-10-13 04:35:28', '2026-10-13 04:41:40');
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a13396-2701-7295-92a5-8fa8a6443468', '01a13396-26f8-7162-904b-3a43ff1ffc75', 'visitor', NULL, 'text', 'hello', NULL, 1, NULL, '2026-10-13 04:47:20', '2026-10-13 04:54:08');
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a1339c-7538-7005-84e3-fcac03700591', '01a13396-26f8-7162-904b-3a43ff1ffc75', 'agent', '01a125ab-f7f3-73c7-a202-803c31808715', 'text', 'yes', NULL, 1, NULL, '2026-10-13 04:54:14', '2026-10-13 04:54:14');
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a1339c-a5c7-7071-8a23-764f86183939', '01a1339c-a5c1-7046-9276-e47647dda7f8', 'visitor', NULL, 'text', 'fghgf', NULL, 1, NULL, '2026-10-13 04:54:26', '2026-10-13 04:55:13');
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a1339d-1513-721e-9e31-7972bf3f7509', '01a13396-26f8-7162-904b-3a43ff1ffc75', 'agent', '01a125ab-f7f3-73c7-a202-803c31808715', 'text', 'let me know your iessue', NULL, 1, NULL, '2026-10-13 04:54:54', '2026-10-13 04:54:54');
INSERT INTO `messages` (`id`, `conversation_id`, `sender_type`, `sender_id`, `message_type`, `content`, `attachment_url`, `is_read`, `metadata`, `created_at`, `updated_at`) VALUES ('01a1339d-3840-72e9-b7db-5cda10804060', '01a1339c-a5c1-7046-9276-e47647dda7f8', 'visitor', NULL, 'text', 'ks', NULL, 1, NULL, '2026-10-13 04:55:04', '2026-10-13 04:55:13');

SET FOREIGN_KEY_CHECKS=1;
