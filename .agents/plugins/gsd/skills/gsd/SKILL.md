---
name: gsd
description: "Get Shit Done (GSD) spec-driven development framework for Antigravity. ACTIVATE this skill when the user wants to plan, execute, verify, or manage software projects using GSD workflows, slash commands (/gsd-*), Super Mode (/gsd-super), Anti-Hallucination research (/gsd-no-halluc), or Project Memory (/gsd-commit-memory)."
---

# GSD (Get Shit Done) for Antigravity

A spec-driven development workflow system for Antigravity that eliminates context rot, guarantees atomic commits, prevents hallucinations, and enables autonomous project delivery.

## Core Pillars & Workflows

### 1. ⚡ Super Mode (Full Autonomy)
- **Command**: `/gsd-super [prompt or PRD]`
- **Workflow**: Automated end-to-end execution: PRD analysis → Branching (`gsd-super/feature`) → 5-Tier Verification (Syntax, Types, Lint, Tests, Build) → Visual Verification via browser subagent → Production delivery.

### 2. 🛡️ Anti-Hallucination Q&A (Verified Research)
- **Command**: `/gsd-no-halluc [question]` (or alias `/no-halluc`)
- **Workflow**:
  - Never fabricate or guess.
  - Mandatory external verification loop using `search_web` and `read_url_content`.
  - Report findings tagged with confidence scores: `HIGH`, `MEDIUM`, or `LOW` with exact source citations.

### 3. 🧠 Project Memory (Context Distillation)
- **Command**: `/gsd-commit-memory "[Lesson/Decision]"` (or alias `/gsd memo`)
- **Workflow**: Distills project decisions, tech stack choices, and architecture rules into `.planning/memory/PROJECT-MEMORY.md`. Ensures zero context loss across conversation resets.

---

## Standard GSD Lifecycle

Follow this sequential pipeline for structured development:

1. **Setup & Roadmap**:
   - Command: `/gsd-new-project`
   - Initializes `.planning/` directory (`PROJECT.md`, `REQUIREMENTS.md`, `ROADMAP.md`, `STATE.md`).
2. **Context & Discussion**:
   - Command: `/gsd-discuss [phase-number]`
   - Captures user implementation preferences and locks in architectural decisions before planning.
3. **Blueprint Planning**:
   - Command: `/gsd-plan [phase-number]`
   - Researches phase dependencies, generates atomic XML task blueprints with explicit test/verify criteria.
4. **Execution**:
   - Command: `/gsd-execute [phase-number]`
   - Implements code task by task with atomic git commits, verifying each task before moving to the next.
5. **Verification & Audit**:
   - Command: `/gsd-verify [phase-number]`
   - Conducts User Acceptance Testing (UAT) and regression testing before closing the phase.

---

## Quick & Utility Workflows

- `/gsd-quick [task]`: Execute small, ad-hoc tasks under GSD quality guarantees and atomic commits.
- `/gsd-progress`: Pulse check showing current state, blockers, and next phase.
- `/gsd-help`: Full command reference and interactive guide.

---

## Directory Structure & State Management

All GSD project files live in the `.planning/` directory:

```text
.planning/
├── PROJECT.md          # Project vision, objectives, core constraints
├── REQUIREMENTS.md     # Scoped requirements (v1, v2, out-of-scope)
├── ROADMAP.md          # Phased milestone roadmap
├── STATE.md            # Living state & current active phase
├── config.json         # Workflow & model settings
├── memory/             # Project memory & architectural logs
│   └── PROJECT-MEMORY.md
├── research/           # Stack analysis, pitfalls, dependencies
└── phases/             # Phase-specific context, plans, summaries, and UATs
    ├── 01-phase-name/
    │   ├── 01-CONTEXT.md
    │   ├── 01-RESEARCH.md
    │   ├── 01-01-PLAN.md
    │   └── 01-01-SUMMARY.md
```

## Tools & Helpers

The state management CLI `gsd-tools.js` is available to query and update `.planning/`:
- `node <path>/bin/gsd-tools.js status`: Display project phase status.
- `node <path>/bin/gsd-tools.js commit-memory "decision"`: Record key architectural decisions.
