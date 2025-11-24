# Technical Specification: Sentence Checking Algorithm

## 0. Scope
Detect and visualize discrepancies between `student_text` and `original_text`: spelling/punctuation errors, missing and extra tokens, and word-order violations. Show minimal moves (prefer fewer moves), either as arrows between tokens or as a rewrite block (strikethrough + corrected order) when arrows would overlap.

## 1. Inputs and Tokens
- Inputs: `original_text`, `student_text`.
- Tokenization regex: `/[\p{L}\p{M}]+|\d+|[^\s\p{L}\p{M}\d]/u`.
- Token fields: `{ raw, norm (lowercase), type: word|punct, index (0-based) }`.
- Punctuation normalization map: `—/–/− -> -`, `… -> ...`, `«/»/“/”/„ -> "`, `’/‘ -> '`.

## 2. Similarity Scoring (ALIGN)
- `tokenSimilarity(a,b)`:
  - Different types: ~0.05.
  - Punct vs punct: normalize; exact -> ~0.8, else ~0.2.
  - Word vs word:
    - Exact norm -> strong anchor (≈5).
    - Stem match (simple suffix stripper) -> ≈0.85.
    - Levenshtein on norm: `closeness = 1 - dist/maxLen`; ≥0.8 -> ~0.6..0.8; ≥0.6 -> ~0.5..0.6; else `closeness*0.5`.
- `MIN_SIMILARITY_SCORE` ≈ 0.35.
- Similarity matrix: `weight = base + proximityBonus - distancePenalty`, where `dist = |i-j|/maxLen`, `proximityBonus ≈ (1-dist)*0.35`, `distancePenalty ≈ dist*0.25` (bias toward diagonal/aligned positions).

## 3. Assignment
- Run Hungarian (Kuhn–Munkres) on the similarity matrix to maximize total weight.
- Drop pairs with `weight < MIN_SIMILARITY_SCORE`.
- Outputs: `matches = [(student_pos, orig_pos, score)]`, `missing` = original tokens without a match, `extra` = student tokens without a match.

## 4. Ordering via LIS
- Sort `matches` by `student_pos`; take array of `orig_pos`.
- Compute LIS; tie-breakers: (1) longer, (2) fewer “breaks” (moves), (3) earlier start.
- Interpretation: any match not in LIS violates order.

## 5. Movable Blocks
- `movable = matches \ LIS`.
- Group movable tokens into a block if they are adjacent in `student_pos` **and** share the same target gap (between two LIS neighbors).
- Block structure: `{ student_start, student_end, tokens[], targetGapKey, targetGap={before, after} }`.
- Merge rule: contiguous movable tokens with the same target gap form one block.

## 6. Target Segment and Boundaries
- For each block, determine target gap by LIS neighbors (`before`, `after`) and store `beforeUser`/`afterUser` positions (student-side).
- Boundaries (S-boundaries) are between student tokens: `Sb0` before token 0, `Sb1` between token 0/1, …, `SbN` after last token.

## 7. Boundary Crossing Counts (boundaryCount)
- Boundaries are student-side. For each move block, find the target boundary (gap `beforeUser+1` or 0/end). Collect all student boundaries crossed when moving from source `[start..end]` to target; increment counts for those boundaries.
- Overload: boundary with count > 1 is overloaded.

## 8. When to Switch to Rewrite
- Boundaries (Sb0..SbN) refer exclusively to boundaries between tokens in `student_text`, not `original_text`.
- If any boundary is overloaded, or if move targets cross geometrically (order of targets inverts, or a target falls inside another block's range), switch to rewrite mode.

## 9. Building the Rewrite Segment
- Pick “problem” move blocks (overloaded/crossing).
- Collect their `orig_pos` -> `origMin/origMax`.
- Collect all `user_pos` whose `orig_pos` lies in `[origMin..origMax]` -> `userMin/userMax`.
- Expand `origMin/origMax` to include all `orig_pos` whose `user_pos` lies in `[userMin..userMax]`.
- Rewrite covers student tokens `[userMin..userMax]`; show correct order of `[origMin..origMax]` on top, and strikethrough the student range below.

## 10. Arrows (if not rewrite)
- One gap-anchor per needed target gap; one arrow per move block.
- SVG path: start at top of block center, end at target gap center; cubic Bezier with control points above the row; marker-end arrowhead.

## 11. Error Marking
- If `score < 1.0`, mark spelling/punctuation difference (raw vs orig.raw) on the student token; show correction inline if desired.
- Missing: caret + word in the gap. Extra: strike-through or “extra” styling.

## 12. Result Object (for integration)
- `{ isCorrect, mode: "arrows"|"rewrite", matches, extras, missing, movePlan: { moveBlocks, rewriteGroups, tokenMeta, gapMeta, gapsNeeded, missingByPosition }, errorCount, orderIssues, moveIssues }`.
- `orderIssues`: count of matched pairs not in the anchor/LIS sequence (order violations).
- `moveIssues`: number of move blocks required; if rewrite is used, treat it as a single move issue (or 0 if counting separately).

## 13. Rendering / UX Notes
- Row “Your answer”: tokens with classes ok/warn/error/extra; move-blocks with dashed outline; missing as `^ word`; rewrite-block with top (correct) and bottom (strikethrough student range).
- Row “Should be”: tokens ok.
- Switch between arrows and rewrite per rules above to avoid overlapping arrows.

## 14. Testing Checklist
- Single and double word swaps: prefers fewer moves.
- Repeated words: anchors remain stable.
- Missing/extra tokens: correct gap placement.
- Punctuation normalization (quotes/dashes/ellipsis).
- Crossing moves trigger rewrite instead of overlapping arrows.
- Regression cases from UI test page.
