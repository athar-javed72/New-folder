---
name: server-validation
description: "Implement, audit, and enforce production-grade server-side validation across all backend endpoints, server actions, and API routes. ACTIVATE this skill when building APIs, adding backend input validation, auditing endpoint security, or preventing injection attacks."
metadata:
  version: "1.0.0"
---

# Server-Side Validation & Security

Production-grade server-side validation standard adhering to OWASP Application Security Verification Standards (ASVS). Never trust client input, headers, or parameters.

## Core Security Pillars

1. **Gatekeeper Pattern**: Every route handler, server action, or microservice entry point MUST validate input payload at the boundary BEFORE any database queries or business logic execute.
2. **Strict Whitelisting**: Use strict schemas (`zod.strict()`, `extra = 'forbid'` in Pydantic) to reject or strip unexpected properties. This stops mass assignment and prototype pollution.
3. **Defense-in-Depth Sanitization**: Trim strings, normalize emails/identifiers, sanitize HTML/XSS vectors, and enforce min/max length boundaries.
4. **Structured Error Handling**: Return normalized HTTP 400/422 errors with field-level details without leaking database schemas, table names, or internal stack traces.

---

## Standard Implementations

### 1. TypeScript / Node / Next.js / Express (Zod Standard)

```typescript
import { z } from 'zod';

// 1. Define Strict Schema
export const CreateUserSchema = z.object({
  name: z.string().trim().min(2, "Name must be at least 2 characters").max(100),
  email: z.string().trim().toLowerCase().email("Invalid email address"),
  password: z.string()
    .min(8, "Password must be at least 8 characters")
    .regex(/[A-Z]/, "Must contain at least one uppercase letter")
    .regex(/[0-9]/, "Must contain at least one number"),
  age: z.coerce.number().int().min(18).max(120).optional(),
  role: z.enum(["user", "admin"]).default("user")
}).strict(); // Reject unexpected fields

export type CreateUserInput = z.infer<typeof CreateUserSchema>;

// 2. Safe Parsing Utility (Never crash the process)
export function validateInput<T>(schema: z.ZodSchema<T>, data: unknown) {
  const result = schema.safeParse(data);
  if (!result.success) {
    return {
      success: false as const,
      errors: result.error.flatten().fieldErrors,
      statusCode: 422
    };
  }
  return { success: true as const, data: result.data };
}
```

### 2. Next.js App Router (Route Handlers & Server Actions)

```typescript
import { NextResponse } from 'next/server';
import { CreateUserSchema, validateInput } from '@/lib/validations/user';

export async function POST(req: Request) {
  try {
    const rawBody = await req.json();
    const validation = validateInput(CreateUserSchema, rawBody);

    if (!validation.success) {
      return NextResponse.json(
        { error: "Validation Failed", details: validation.errors },
        { status: 422 }
      );
    }

    const cleanData = validation.data; // Fully typed and sanitized
    // Proceed to DB operation...
    return NextResponse.json({ success: true, data: cleanData }, { status: 201 });
  } catch (err) {
    return NextResponse.json({ error: "Malformed JSON payload" }, { status: 400 });
  }
}
```

### 3. Express / Fastify Middleware Wrapper

```typescript
export const validateBody = (schema: z.ZodSchema) => {
  return (req: any, res: any, next: any) => {
    const result = schema.safeParse(req.body);
    if (!result.success) {
      return res.status(422).json({
        error: "Unprocessable Entity",
        details: result.error.flatten().fieldErrors
      });
    }
    req.validatedBody = result.data;
    next();
  };
};
```

---

## Validation Checklist

- [ ] All request bodies validated against strict schema.
- [ ] All URL parameters (`params`) and query strings (`query`) coerced and validated.
- [ ] Unknown properties explicitly forbidden (`.strict()`).
- [ ] String boundaries bounded by `.min()` and `.max()`.
- [ ] Enums validated against explicit allowed values.
- [ ] File uploads validated for MIME type, file signature, and size limit.
