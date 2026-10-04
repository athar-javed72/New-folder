---
name: code-review
description: "Run CodeRabbit code reviews, analyze git diffs for bugs, security vulnerabilities, performance anti-patterns, and code quality. ACTIVATE this skill when the user asks to review code, check quality, perform security audits, or prepare for PR creation."
metadata:
  version: "0.1.0"
---

# CodeRabbit Code Review

AI-powered code review using CodeRabbit methodology. Enables developers and autonomous agents to review code changes, catch subtle bugs, verify security standards, and maintain clean architectures.

## Capabilities

- **Deep Bug Detection**: Logical errors, race conditions, edge case mishandling, null pointers.
- **Security Audit**: Detection of hardcoded secrets, injection vectors, unsafe data handling.
- **Performance Profiling**: N+1 queries, memory leaks, redundant computations.
- **Severity Rating**:
  - `Critical`: Must be resolved before commit/merge.
  - `Major`: Serious issue requiring attention.
  - `Minor`: Quality / readability improvement.
  - `Info`: Suggestion or style alignment.

## How to Review

### 1. Identify Review Scope
Determine whether the user wants to review:
- Working directory uncommitted changes (`git diff`)
- Staged changes (`git diff --staged`)
- Specific files or directories
- Commits relative to base branch (`git diff main...HEAD`)

### 2. Run CodeRabbit Review
- If CodeRabbit CLI is available:
  ```bash
  coderabbit review --agent
  ```
- If running directly via Antigravity Agent:
  - Inspect git diff using grep/terminal tools.
  - Evaluate against security, performance, and best practice checklists.
  - Format output clearly with file references, line numbers, and proposed fixes.

### 3. Review Report Format

```markdown
## 🐇 CodeRabbit Review Summary

### 🚨 Critical & Major Issues
- **[file.ext:L12-L18]**: Description of issue.
  - **Risk**: Why this is dangerous.
  - **Recommended Fix**: Code snippet.

### ⚠️ Minor Improvements & Suggestions
- **[file.ext:L45]**: Code readability or performance suggestion.

### ✅ Positive Findings
- Clean modular structure, thorough test coverage.
```
