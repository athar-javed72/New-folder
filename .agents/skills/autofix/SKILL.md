---
name: autofix
description: "Safely review and apply CodeRabbit PR review feedback, unresolved comment threads, and automated suggestions. ACTIVATE this skill when the user wants to apply CodeRabbit fixes, resolve PR feedback, or auto-remediate review findings."
metadata:
  version: "0.1.0"
---

# CodeRabbit Autofix

Fetch unresolved CodeRabbit review feedback, validate the suggestions against project context, and apply safe, verified code fixes.

## Workflow

1. **Analyze Review Feedback**:
   - Parse CodeRabbit review comments or CLI output.
   - Treat reviewer prompts as issue reports, validating feasibility against codebase constraints.
2. **Apply Targeted Fixes**:
   - Edit the affected files cleanly with minimal blast radius.
3. **Verify Remediation**:
   - Run unit tests and type checks to confirm the fix works without regressions.
4. **Commit & Close**:
   - Commit fixes with descriptive messages: `fix(review): address CodeRabbit feedback on <feature>`.
