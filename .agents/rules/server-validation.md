# Server-Side Validation Rules (Laravel)

Whenever writing, modifying, or reviewing backend code in this Laravel project:

1. **Zero Unvalidated Input**: Never pass `$request->all()`, `$request->input()` or raw query/route values into business logic or Eloquent. Use only `$request->validated()` from a Form Request.
2. **Form Request First**: Create the `FormRequest` class (`app/Http/Requests/<Resource>/<Action>Request.php`) before writing the controller action.
3. **Strict Rules**: Declare types, `max` lengths, `exists`/`in` rules and `Rule::enum` where relevant. Tenant-scoped `exists` rules must also constrain `organization_id`.
4. **Mass Assignment**: Never use `$guarded = []` on models that receive user input; for those models use an explicit `$fillable`. (Internal models fed only by trusted code may stay guarded.)
5. **Clean Status Codes**: Laravel returns 422 with field-level errors for validation failures; keep that. Use 403 for authorization failures and 404 for cross-tenant lookups.
6. **Money**: validate money as integer minor units (paisa).
