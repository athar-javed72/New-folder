---
description: Start or resume an autonomous Ralph Loop development session
---

# /ralph-loop — Autonomous Development Loop

Execute an autonomous development cycle following the Ralph Loop methodology:

1. Check if `PRD.md` and `progress.txt` exist in the project root:
   - If not found, run `node .agent/bin/ralph-loop.js init` or ask the user for the goal/PRD to initialize them.
2. Run `node .agent/bin/ralph-loop.js next` to determine the current task.
3. If all tasks are complete, celebrate and output the final project summary.
4. If a task is pending:
   - Analyze requirements and existing codebase.
   - Implement the code changes required for that task.
   - Run verification tests to ensure nothing is broken.
   - Commit changes via git.
   - Run `node .agent/bin/ralph-loop.js complete "<task_name>" "<summary_of_work>"`.
   - Report progress and seamlessly proceed to the next task.
