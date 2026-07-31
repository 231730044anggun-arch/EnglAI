# Phase 4 Analytics Rules

- Metrics are recalculated from `learning_attempts`, `learning_activities`, `quiz_answers`, `quiz_participants`, and `quiz_sessions`. AI text never becomes a source score.
- Mastery: Mastered ≥ 80%, Developing 60–79%, Needs Practice < 60%. Configure the mastery boundary with `MASTERY_THRESHOLD_PERCENT`.
- Question “Needs Review”: at least 5 attempts and correct rate below 30%. This is a review signal, not a declaration that an item is incorrect.
- Active Student: `classroom_members.last_seen_at` within 30 days.
- Speaking metrics are transcription-based and do not represent phoneme, stress, accent, intonation, or pronunciation accuracy.
- Teacher overrides preserve the original and previous score in `score_overrides`, append an audit event, and update the participant aggregate by the score delta.
- Refresh a deterministic cache snapshot with:

  `php scripts/refresh_analytics.php <classroom_id>`

Primary Phase 4 indexes cover classroom/time snapshots, audit history, recommendation scope, override assessment lookup, and export history. Dashboard queries use aggregate SQL rather than per-Student query loops.
