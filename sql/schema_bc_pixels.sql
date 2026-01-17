CREATE TABLE `bc_pixels` (
  `id` int(10) UNSIGNED NOT NULL,
  `pixel_id` varchar(32) NOT NULL,
  `pixel_name` varchar(191) NOT NULL,
  `access_token` text DEFAULT NULL,
  `test_code` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
