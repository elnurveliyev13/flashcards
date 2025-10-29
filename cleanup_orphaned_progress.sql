-- ============================================================================
-- Cleanup Orphaned Progress Records
-- ============================================================================
-- Purpose: Remove flashcards_progress records where the card no longer exists
-- Safe to run: Yes (only deletes orphaned records with no matching cards)
-- Run this ONCE after deploying the fix in ajax.php
-- ============================================================================

-- Step 1: Check how many orphaned records exist (DRY RUN)
-- Run this first to see what will be deleted

SELECT COUNT(*) AS orphaned_count
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL;

-- Expected result: Shows number of orphaned records
-- Example output: orphaned_count = 47


-- Step 2: See details of orphaned records (OPTIONAL - for review)
-- Shows which users and cards are affected

SELECT
    p.id AS progress_id,
    p.userid,
    p.deckid,
    p.cardid,
    p.flashcardsid,
    p.step,
    FROM_UNIXTIME(p.due) AS due_date,
    FROM_UNIXTIME(p.addedat) AS added_date
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL
ORDER BY p.userid, p.deckid, p.cardid
LIMIT 100;


-- Step 3: DELETE orphaned records (ACTUAL CLEANUP)
-- ⚠️ WARNING: This actually deletes data! Make sure you've reviewed Step 1 & 2

DELETE p
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL;

-- After running, check the result:
-- Query OK, 47 rows affected (0.03 sec)


-- Step 4: Verify cleanup (should return 0)

SELECT COUNT(*) AS orphaned_count
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL;

-- Expected result: orphaned_count = 0


-- ============================================================================
-- For PostgreSQL (if using Postgres instead of MySQL)
-- ============================================================================

-- Step 1 (Postgres): Check count
/*
SELECT COUNT(*) AS orphaned_count
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL;
*/

-- Step 2 (Postgres): See details
/*
SELECT
    p.id AS progress_id,
    p.userid,
    p.deckid,
    p.cardid,
    p.flashcardsid,
    p.step,
    TO_TIMESTAMP(p.due) AS due_date,
    TO_TIMESTAMP(p.addedat) AS added_date
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL
ORDER BY p.userid, p.deckid, p.cardid
LIMIT 100;
*/

-- Step 3 (Postgres): Delete
/*
DELETE FROM mdl_flashcards_progress p
USING mdl_flashcards_cards c
WHERE c.deckid = p.deckid
  AND c.cardid = p.cardid
  AND c.id IS NULL;
*/

-- OR simpler Postgres version:
/*
DELETE FROM mdl_flashcards_progress
WHERE id IN (
    SELECT p.id
    FROM mdl_flashcards_progress p
    LEFT JOIN mdl_flashcards_cards c
      ON c.deckid = p.deckid AND c.cardid = p.cardid
    WHERE c.id IS NULL
);
*/

-- Step 4 (Postgres): Verify
/*
SELECT COUNT(*) AS orphaned_count
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL;
*/


-- ============================================================================
-- Notes
-- ============================================================================

-- 1. This script only removes progress where the card was fully deleted
--    It does NOT remove progress for "hidden" cards (soft delete)

-- 2. Safe to run multiple times (idempotent)

-- 3. After cleanup, the fixed ajax.php code will prevent new orphaned records

-- 4. If you have a LOT of orphaned records (>10000), consider running in batches:

-- MySQL batch delete (1000 at a time):
/*
DELETE p
FROM mdl_flashcards_progress p
LEFT JOIN mdl_flashcards_cards c
  ON c.deckid = p.deckid AND c.cardid = p.cardid
WHERE c.id IS NULL
LIMIT 1000;

-- Run multiple times until "0 rows affected"
*/

-- 5. Table prefix 'mdl_' may be different in your installation
--    Replace 'mdl_' with your actual prefix (check in config.php: $CFG->prefix)
