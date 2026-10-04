---
description: Perform an AI-powered CodeRabbit code review on current changes
---

# /coderabbit-review — Comprehensive Code Review

1. Check git status to see modified or staged files:
   `git status --short`
2. Retrieve diff of changes:
   `git diff HEAD` (or staged diff `git diff --staged`)
3. Perform thorough CodeRabbit review:
   - Security vulnerabilities & credentials exposure
   - Logic bugs & uncaught edge cases
   - Performance & scalability bottlenecks
   - Code maintainability & convention compliance
4. Generate structured review report with actionable code diffs.
5. Ask user if they would like automatic remediation of flagged issues.
