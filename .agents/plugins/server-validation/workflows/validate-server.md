---
description: Audit and apply strict server-side validation across all project endpoints
---

# /validate-server — Comprehensive Server-Side Validation

1. **Scan Codebase**:
   - Detect project framework (Next.js, Express, Fastify, NestJS, Django, FastAPI).
   - Find all API routes, controller files, and server actions.
2. **Security Audit**:
   - Identify endpoints lacking schema validation.
   - Flag direct usage of unvalidated `req.body`, `req.query`, or `params`.
   - Check for missing length limits, missing type coercion, and potential injection points.
3. **Generate Validation Schemas**:
   - Create or update schemas using Zod (TypeScript) or Pydantic (Python).
   - Apply `.strict()` to prevent parameter tampering.
4. **Implement Validation Middleware / Wrappers**:
   - Safely parse input and return clean, standardized 422 JSON error responses.
5. **Verify**:
   - Test invalid payloads to ensure 422 is returned.
   - Test valid payloads to ensure clean execution.
