-- Run this once on an existing installation before deploying the updated PHP files.
-- It adds traceability from an AI recommendation to its converted Purchase Request.

ALTER TABLE `purchase_requests`
  ADD COLUMN `ai_recommendation_id` bigint(20) DEFAULT NULL AFTER `source_type`,
  ADD UNIQUE KEY `uq_pr_ai_recommendation` (`ai_recommendation_id`),
  ADD CONSTRAINT `fk_pr_ai_recommendation`
    FOREIGN KEY (`ai_recommendation_id`) REFERENCES `ai_recommendations` (`id`) ON DELETE SET NULL;
