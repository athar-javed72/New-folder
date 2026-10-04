---
name: serena
description: "Perform semantic code navigation, symbol search, type inspection, and precise cross-file refactoring using Serena Language Server MCP. ACTIVATE this skill when analyzing complex codebases, finding references, or renaming/refactoring symbols across multiple files."
metadata:
  version: "1.0.0"
---

# Serena Semantic Code Intelligence

Serena transforms the AI agent from a blind text-searcher into an IDE-aware semantic engineer. Using Language Server Protocol (LSP), Serena provides deep structural understanding of the project's code graph.

## Capabilities

- **Symbol-Level Navigation**: Locate classes, interfaces, methods, functions, and types with 100% precision.
- **Find All References**: Trace exact callers and usages across the entire codebase before modifying any public interface.
- **Safe Semantic Refactoring**: Rename or restructure symbols across all dependent files without missing imports or breaking references.
- **Token Efficiency**: Inspect symbol signatures and documentation without loading entire multi-thousand line files into context.

## When to Use

- When planning a refactor that touches widely shared classes, functions, or types.
- When tracing how data flows through multiple layers of an application.
- When finding where an interface or abstract method is implemented.
- When performing high-confidence symbol renames.

## Workflow

1. **Locate Symbol**: Query Serena for symbol definitions, types, and references.
2. **Impact Analysis**: Trace all call-sites and consumers across files.
3. **Execute Refactoring**: Apply edits safely, ensuring all references update synchronously.
4. **Validation**: Use Serena's diagnostic tools to verify no broken references or syntax errors remain.
