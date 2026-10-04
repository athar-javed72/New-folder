# Ralph Loop Execution Rules

When executing tasks under the Ralph Loop:

1. **Strict Single-Task Focus**: Do NOT attempt to complete multiple tasks from `PRD.md` in a single turn. Focus entirely on completing the single current active task cleanly.
2. **File-Based State Only**: Always read `PRD.md` and `progress.txt` from disk at the beginning of each loop. Do not assume prior conversation context.
3. **Mandatory Verification**: Every task MUST be verified (syntax, lint, tests, build) before committing. Never mark a task complete without verification.
4. **Atomic Commit per Task**: Every finished task must produce a clean, focused git commit.
5. **Progress Log Integrity**: Always log task completion, key decisions, and any newly discovered edge cases to `progress.txt`.
