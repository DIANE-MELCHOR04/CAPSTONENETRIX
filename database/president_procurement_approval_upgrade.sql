-- Run this once on an existing installation before deploying the updated PHP files.
-- New installations already include these changes in the main database schema.

ALTER TABLE `purchase_orders`
  MODIFY `status` enum('submitted','open','partially_received','received','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  ADD COLUMN `approved_by` int(11) DEFAULT NULL AFTER `remarks`,
  ADD COLUMN `approved_at` datetime DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN `approval_remarks` text DEFAULT NULL AFTER `approved_at`,
  ADD KEY `fk_po_approver` (`approved_by`),
  ADD CONSTRAINT `fk_po_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Existing active orders remain valid and do not require retroactive approval.
-- All orders created by the updated application begin as `submitted`.
