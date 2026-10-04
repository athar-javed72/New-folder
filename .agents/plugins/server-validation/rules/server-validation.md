# Server-Side Validation Rules

Whenever writing, modifying, or reviewing backend code:

1. **Zero Unvalidated Endpoints**: Under NO circumstances should an API route handler, server action, or microservice endpoint accept raw `req.body`, `req.query`, or `req.params` directly into business logic or database queries.
2. **Schema-First Design**: Define a dedicated validation schema file (e.g. `lib/validations/<resource>.ts` or `schemas/<resource>.py`) before writing endpoint handlers.
3. **Use Safe Parsing**: Always use `.safeParse()` or try/except validation handlers to avoid unhandled exceptions.
4. **Sanitize & Strip**: Automatically trim strings, normalize casing where appropriate, and reject unknown parameters with `.strict()`.
5. **Clean Status Codes**: Return `400 Bad Request` for malformed payloads and `422 Unprocessable Entity` for schema validation failures with precise field-level errors.
