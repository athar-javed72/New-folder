---
name: ralph-loop
description: "Autonomous iterative development loop for Antigravity based on the Ralph Loop methodology. ACTIVATE this skill when the user wants to run autonomous coding loops, implement a PRD iteratively, work with PRD.md and progress.txt, or run unattended multi-step development."
---

# Ralph Loop for Antigravity

The **Ralph Loop** is a stateless, iterative development methodology designed to overcome LLM context window limits and maintain consistent peak code quality over long-running autonomous tasks.

## Core Principles

1. **Stateless Iteration**: State is NEVER preserved in LLM context. State lives exclusively on disk in:
   - `PRD.md`: Master task specification and acceptance criteria.
   - `progress.txt`: Living execution log of completed tasks, learnings, and next steps.
   - `git`: Version control commit history per task.
2. **One Task per Cycle**: In each iteration of the loop, the agent:
   - Reads `PRD.md` and `progress.txt` to determine the current task.
   - Implements only that single discrete task.
   - Runs verification (lint, typecheck, tests).
   - Commits changes to Git with a concise commit message.
   - Appends completion notes to `progress.txt`.
   - Clears / refreshes context for the next cycle.
3. **Emergency Stop & Circuit Breaker**:
   - Loop automatically halts if tests fail repeatedly or if an unexpected blocking error occurs.

## Commands & Workflow

- **/ralph-loop**: Trigger the Ralph Loop autonomous cycle.
- **CLI Helper**:
  - `node .agent/bin/ralph-loop.js init`: Initialize template `PRD.md` and `progress.txt`.
  - `node .agent/bin/ralph-loop.js status`: View task progress and remaining items.
  - `node .agent/bin/ralph-loop.js next`: Fetch the next active task.
  - `node .agent/bin/ralph-loop.js complete "<task-id>" "<notes>"`: Mark a task complete and log to `progress.txt`.

## Standard Loop Execution Steps

1. **Step 1: Check State**:
   - Ensure `PRD.md` exists. If not, generate it from user requirements.
   - Read `progress.txt` to find what tasks are already done.
2. **Step 2: Pick Single Task**:
   - Select the earliest unchecked task in `PRD.md`.
3. **Step 3: Implement & Verify**:
   - Write or modify the required code files.
   - Run tests/validation commands.
4. **Step 4: Commit & Log**:
   - Git commit: `git commit -m "feat(<task>): implement <task-name>"`
   - Update `progress.txt` with timestamp, files modified, and verification results.
5. **Step 5: Iterate**:
   - If more tasks remain, proceed to the next iteration.
   - When all tasks are checked, print final completion summary.
