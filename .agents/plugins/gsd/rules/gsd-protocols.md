# GSD Core Protocols & Guidelines

When the GSD (Get Shit Done) workflow is active:

1. **File-First Context**: Always re-read target files using tools before editing. Never rely solely on memory or conversation history.
2. **Atomic Commits**: Every task must be committed independently with clear, descriptive commit messages describing the specific changes.
3. **Anti-Hallucination Verification**:
   - Always verify external APIs, libraries, and functions against official documentation or web search.
   - If an answer is uncertain, explicitly state the uncertainty instead of guessing.
4. **5-Tier Verification Gate**:
   - Syntax validation
   - Type checking
   - Linting
   - Automated tests
   - Build verification
   Never mark a phase or task complete until verification passes.
5. **Continuous State Tracking**:
   - Keep .planning/STATE.md updated with the current progress, completed tasks, and blockers.
   - Start a new conversation or clear context between major phases for maximum context freshness.
