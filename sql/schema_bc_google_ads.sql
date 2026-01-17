CREATE TABLE `bc_google_ads` (
  `id` int(10) UNSIGNED NOT NULL,
  `tag_name` varchar(191) NOT NULL,
  `conversion_id` varchar(50) NOT NULL,
  `lead_label` varchar(100) DEFAULT NULL,
  `contact_label` varchar(100) DEFAULT NULL,
  `schedule_label` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
