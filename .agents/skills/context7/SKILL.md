---
name: context7
description: "Retrieve up-to-date, version-specific library documentation, code examples, and API references using Context7 MCP. ACTIVATE this skill when needing exact documentation for third-party libraries, modern APIs, or avoiding deprecated methods."
metadata:
  version: "1.0.0"
---

# Context7 Documentation & API Research

Context7 gives AI coding agents real-time access to source-level, versioned documentation for thousands of modern libraries, frameworks, and tools.

## Why Use Context7

- **Eliminate Hallucinations**: Get actual API signatures, parameters, and return types rather than hallucinating deprecated methods.
- **Version Specificity**: Query docs matching the exact version in `package.json`, `requirements.txt`, or `Cargo.toml`.
- **Verified Code Examples**: Copy production-ready usage patterns directly from the official upstream documentation.

## When to Activate

- When integrating a third-party package (e.g. Next.js, Tailwind v4, Prisma, LangChain, TanStack Query, FastAPI, etc.).
- When unsure about a method name, breaking change, or configuration schema.
- When an API gives unexpected runtime errors or type errors.

## Workflow

1. **Resolve Library**: Look up the library identifier using Context7 tools.
2. **Fetch Documentation**: Retrieve the relevant topic, function, or component guide.
3. **Apply Directly**: Write code based on the fresh, verified documentation.
