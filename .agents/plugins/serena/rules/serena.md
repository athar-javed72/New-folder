# Serena Semantic Engineering Protocols

1. **Semantic Over Grep**: Prefer Serena's symbol navigation over naive grep/text search when working with typed languages or large codebases.
2. **Impact Assessment First**: Always check symbol references across the project before renaming or modifying public functions or types.
3. **Zero Broken References**: Verify that all imported usages are updated whenever a symbol definition changes.
