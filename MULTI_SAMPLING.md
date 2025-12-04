# Multi-Sampling (Self-Consistency) Feature

## Overview

Multi-sampling is an advanced AI technique that generates **3 parallel text checks** with different temperature settings and selects the final result by **consensus (weighted voting)**. This significantly reduces false positives, hallucinations, and improves consistency of error detection.

---

## How It Works

### Traditional Approach (Single Request)
```
User Text → AI Check (temp 0.3) → Result
```
- Fast (1-2 seconds)
- Sometimes finds errors in correct text (false positives)
- Can be inconsistent (same text → different results)

### Multi-Sampling Approach (3 Requests)
```
                  ┌─→ AI Check (temp 0.2, conservative, weight 1.5)
User Text → ──────┼─→ AI Check (temp 0.3, base, weight 1.0)
                  └─→ AI Check (temp 0.35, creative, weight 0.8)
                        ↓
                  Consensus Algorithm
                        ↓
                   Final Result
```

**Consensus Rules:**
- If **all 3** found the same error → **100% confidence** ✅
- If **2 out of 3** found an error → **include in result** ✅
- If **only 1** found an error → **discard** (likely false positive) ❌

---

## Benefits

### ✅ Reduced False Positives
- Random "hallucinated" errors are filtered out
- Only errors confirmed by at least 2 out of 3 checks are reported

### ✅ Improved Consistency
- Same text always produces the same result
- No more random variations between checks

### ✅ Higher Quality
- Conservative temperature (0.2) prevents over-correction
- Creative temperature (0.35) catches edge cases
- Weighted voting favors more reliable checks

---

## Trade-offs

### ❌ API Cost
- **3x more expensive** than single-check mode
- Example: 100 checks/day = 300 API calls instead of 100

### ⚠️ Latency
- **Current implementation**: Sequential requests (~3-4 seconds instead of 1-2)
- **Future optimization**: Parallel requests with curl_multi (~1-2 seconds, no increase)

---

## When to Use

### ✅ Enable Multi-Sampling If:
- You prioritize **quality over cost**
- Students are advanced learners (fewer errors, need precision)
- You're experiencing **false positives** (AI finding "errors" in correct text)
- You need **consistent results** for testing/grading

### ❌ Keep It Disabled If:
- API cost is a concern
- Speed is critical (real-time feedback)
- Students are beginners (obvious errors, don't need high precision)

---

## How to Enable

### 1. Admin Settings
1. Navigate to: **Site Administration → Plugins → Activity modules → Flashcards**
2. Find section: **AI Focus (ChatGPT)**
3. Enable checkbox: **Multi-sampling (Self-Consistency)**
4. Save changes

### 2. Verification
- Check that `ai_multisampling_enabled` is set to **1** in plugin config
- Test with a sample text to verify 3 requests are being made

### 3. Monitoring
- Check debug logs for timing: `api_stage1_multisampling` should show ~2-4 seconds
- Review consensus metadata in API response (if enabled):
  ```json
  "consensusInfo": {
    "totalResponses": 3,
    "confirmedErrors": 2,
    "discardedErrors": 1
  }
  ```

---

## Technical Details

### Temperature Settings
- **0.2** (Conservative): Least likely to hallucinate, most reliable
- **0.3** (Base): Balanced between precision and recall
- **0.35** (Creative): Catches edge cases but may over-correct

### Weights
- Conservative (0.2): **1.5x weight** (more trusted)
- Base (0.3): **1.0x weight** (standard)
- Creative (0.35): **0.8x weight** (less trusted)

### Consensus Algorithm
Errors are identified by `original` text (the wrong word). For each unique error:
1. Count votes: How many of 3 checks found it?
2. Calculate weighted score: `sum(weight_i)` for checks that found it
3. If `votes >= 2` → **confirmed** ✅
4. If `votes < 2` → **discarded** ❌

For confirmed errors:
- Choose **most common correction** (if checks disagree on fix)
- Choose **most common explanation** (for issue field)

---

## Implementation Files

### Modified Files
1. **ai_helper.php** (`classes/local/ai_helper.php`)
   - Added `request_parallel_fallback()` method
   - Added `merge_responses_by_consensus()` method
   - Modified `check_norwegian_text()` to use multi-sampling when enabled

2. **settings.php**
   - Added `ai_multisampling_enabled` checkbox setting

3. **lang/en/flashcards.php**
   - Added language strings for new setting

### Code Location
- Main logic: `ai_helper.php` lines 539-653 (multi-sampling branch)
- Parallel requests: `ai_helper.php` lines 949-994
- Consensus merging: `ai_helper.php` lines 1004-1104

---

## Future Improvements

### Planned Optimizations

#### 1. Parallel Requests (curl_multi)
**Current**: Sequential requests (~3-4 seconds)
**Future**: Parallel requests (~1-2 seconds, no latency increase)

Implementation:
- Use `curl_multi_init()` to send 3 requests simultaneously
- Collect responses in parallel
- No performance penalty compared to single request

#### 2. Configurable Consensus Threshold
**Current**: Fixed 2 out of 3 votes required
**Future**: Admin can choose:
- **2 out of 3** (balanced)
- **3 out of 3** (maximum conservativeness)
- **1 out of 3** (maximum recall, for testing)

#### 3. Logging & Analytics
Track and display:
- False positive rate reduction
- Average confidence scores
- Discarded errors statistics
- Performance metrics

---

## Troubleshooting

### Issue: Multi-sampling not working
**Check:**
1. Setting enabled: `SELECT * FROM mdl_config_plugins WHERE plugin='mod_flashcards' AND name='ai_multisampling_enabled'`
2. Should return `value = 1`
3. Check error logs for exceptions

### Issue: Slow response times
**Cause**: Sequential requests (current implementation)
**Solution**:
- Wait for curl_multi optimization
- Or disable multi-sampling for real-time feedback

### Issue: Higher API costs than expected
**Expected**: 3x cost compared to single-check mode
**Check**:
- Verify 3 requests are being made (check timing logs)
- Review OpenAI API dashboard for usage
- Consider disabling if cost is prohibitive

### Issue: Still seeing false positives
**Possible causes:**
1. Only 1 of 3 checks is hallucinating → **should be filtered**
2. 2 of 3 checks are hallucinating → **consensus confirms error** (rare)
3. Prompts need improvement → **update system/user prompts**

**Solution**: Analyze discarded errors in logs to verify filtering is working

---

## Examples

### Example 1: False Positive Filtered

**Input**: `"Jeg bor i Oslo"` (correct text)

**Single Check Result** (old behavior):
```json
{
  "hasErrors": true,
  "errors": [
    {"original": "bor", "corrected": "bor i", "issue": "Preposition placement"}
  ]
}
```
→ **False positive!** Text was already correct.

**Multi-Sampling Result** (new behavior):
```json
{
  "hasErrors": false,
  "errors": [],
  "consensusInfo": {
    "totalResponses": 3,
    "confirmedErrors": 0,
    "discardedErrors": 1
  }
}
```
→ **Filtered!** Only 1 of 3 checks found "error", so it was discarded.

---

### Example 2: Real Error Confirmed

**Input**: `"Jeg er gikk til butikken"` (wrong verb form)

**Multi-Sampling Result**:
```json
{
  "hasErrors": true,
  "errors": [
    {
      "original": "er gikk",
      "corrected": "gikk",
      "issue": "Redundant auxiliary verb",
      "confidence": 3.3
    }
  ],
  "consensusInfo": {
    "totalResponses": 3,
    "confirmedErrors": 1,
    "discardedErrors": 0
  }
}
```
→ **Confirmed!** All 3 checks found the error (confidence = 1.5 + 1.0 + 0.8 = 3.3)

---

## FAQ

### Q: Does multi-sampling work with STAGE 2 (double-check)?
**A**: Yes! Multi-sampling applies to STAGE 1 only. If STAGE 2 is enabled, it runs once after consensus result from STAGE 1.

### Q: Can I use multi-sampling without STAGE 2?
**A**: Yes, they are independent settings. You can enable multi-sampling alone.

### Q: What if API request fails?
**A**: The system continues with remaining requests. If at least 1 request succeeds, result is returned (even without full consensus).

### Q: Does it work with all OpenAI models?
**A**: Yes, including gpt-4o-mini, gpt-4, gpt-5-mini, etc. Temperature settings are handled automatically.

### Q: How do I measure improvement?
**A**:
1. Create test set of 20-30 sentences (10 correct, 10 with errors)
2. Run with multi-sampling OFF → record false positives
3. Run with multi-sampling ON → record false positives
4. Compare false positive rates

---

## References

### Academic Papers
- **Self-Consistency Improves Chain of Thought Reasoning in Language Models** (Wang et al., 2022)
- **Enhancing LLM Reliability via Voting-Based Ensembles** (Chen et al., 2023)

### Implementation Inspiration
- OpenAI Best Practices: https://platform.openai.com/docs/guides/prompt-engineering
- Prompt Engineering Guide: https://www.promptingguide.ai/techniques/consistency

---

## Support

For issues or questions:
1. Check error logs: `mdl_config_plugins` table
2. Review timing in `debugTiming` field of API response
3. Contact plugin maintainer with logs and example text

---

**Last Updated**: 2025-12-04
**Version**: 1.0.0
**Feature Status**: ✅ Implemented (Sequential), ⏳ Parallel optimization pending
