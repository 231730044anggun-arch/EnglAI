# Phase 3 Live Quiz Rules

## Server scoring

- Every snapshot has a maximum game score of 1,000.
- Reading and Listening: wrong/timeout = 0. Correct = difficulty base plus remaining-time bonus, capped at 1,000.
  - Basic: 700 base
  - Intermediate: 750 base
  - Advanced: 800 base
- Speaking and Writing: backend rubric score (0–100) is normalized to 0–1,000. Empty responses score 0. No minimum score is added.
- Speaking evaluates relevance, task completion, grammar, vocabulary, completeness, and clarity based on transcription. It does not assess phonemes, accent, stress, intonation, or pronunciation accuracy.

Tie-break order: total score, objective correct count, rubric performance, average objective response time, completion count, display name, then join order.

## Assessment workflow

Speaking and Writing create an idempotent assessment job: `PENDING → PROCESSING → COMPLETED` or `FALLBACK_COMPLETED`. At the final round, the session becomes `EVALUATING`; the final leaderboard is persisted only after no pending job remains.

## Listening fairness

Browser Speech Synthesis is labeled “Generated Listening Audio”. Script and playback configuration are identical, but installed voices and speech duration can differ between devices. The server answer window remains authoritative. Persistent generated audio is the recommended production upgrade.
