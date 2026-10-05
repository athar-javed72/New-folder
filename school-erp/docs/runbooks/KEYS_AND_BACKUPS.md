# Key Management and Backup Runbook

## 1. Secrets That Exist

- **`APP_KEY`**: Encrypts every encrypted column across the platform (`students.b_form_encrypted`, `students.passport_encrypted`, `guardians.national_id_encrypted`, `employees.national_id_encrypted`, `student_medical_profiles` fields, and `student_custody_orders.details`). If lost, all encrypted ciphertext across the database becomes permanently unreadable and irretrievable.
- **`BLIND_INDEX_KEY`**: Secret HMAC key used to compute deterministic lookup hashes (`b_form_hash`, `passport_hash`, `national_id_hash`). If lost, all identifier lookups cease matching until every hash is recomputed from the underlying decrypted data.

## 2. Where Keys Live

- Keys must live in a dedicated secrets store within the hosting environment (e.g., AWS Secrets Manager, HashiCorp Vault, Kubernetes Secrets).
- Never commit keys to the git repository.
- Never store keys in the database backup bucket or object storage containing database dumps.
- Never paste keys into application logs, incident tickets, chat messages, or emails.
- Production keys must be strictly distinct from development and staging keys.
- The key in the local `school-erp/.env` file is a local development key only and must never be reused in production.
- Backups of keys must be stored separately from database backups in at least two secure places (e.g., secondary cloud key vault, offline encrypted vault).

## 3. How to Generate Keys

Always generate keys on a secure workstation or target server. Never put real keys into documentation or version control.

- **Generate `APP_KEY`**:
  ```bash
  php artisan key:generate --show
  ```
  Example output placeholder: `base64:EXAMPLE_APP_KEY_REPLACE_WITH_ACTUAL_GENERATED_KEY=`

- **Generate `BLIND_INDEX_KEY`**:
  ```bash
  openssl rand -base64 32
  ```
  Example output placeholder: `EXAMPLE_BLIND_INDEX_KEY_AT_LEAST_32_CHARS_LONG=`
  *Note: The key must be at least 32 characters long; the application refuses to operate outside the testing environment if it is missing or shorter than 32 characters (D-35).*

## 4. Rotating `APP_KEY`

1. Generate a new application key.
2. Put the new key in `APP_KEY` and prepend the old key to `APP_PREVIOUS_KEYS` (comma-separated list, e.g., `APP_PREVIOUS_KEYS=base64:OLD_KEY_1_PLACEHOLDER,base64:OLD_KEY_2_PLACEHOLDER`).
3. Laravel will automatically decrypt old data with the previous key and encrypt new writes with the new key.
4. Old rows stay encrypted with the old key until they are rewritten. The old key must stay in `APP_PREVIOUS_KEYS` until a re-encrypt command has rewritten every encrypted column.
5. That re-encrypt command is **NOT built yet** (D-31). Do not remove the old key from `APP_PREVIOUS_KEYS` until that command exists, has been run, and has been verified.
6. Changing `APP_KEY` logs out all active user sessions and invalidates existing remember-me cookies.

## 5. Rotating `BLIND_INDEX_KEY`

Every blind index hash (`students.b_form_hash`, `students.passport_hash`, `guardians.national_id_hash`, `employees.national_id_hash`) must be recomputed from the decrypted values when this key changes, because old hashes become useless with a new key.

This key rotation requires a planned, tested command that is **NOT built now**. Until that command exists, rotating `BLIND_INDEX_KEY` is not supported.

When implemented, the rotation procedure must follow these steps:
- Announce a maintenance window and enable maintenance mode to stop incoming writes.
- Decrypt identifiers and recompute hashes with the new key in batches per organization.
- Verify row counts, non-null hash counts, and unique index constraints.
- Switch the application configuration to the new `BLIND_INDEX_KEY` and exit maintenance mode.
- Keep the old key securely in the secrets store until the new key and lookups are fully verified.

## 6. Losing a Key

**WARNING: Losing `APP_KEY` means every encrypted column across the entire database is permanently unreadable. There is no recovery tool, master password, or backdoor. All student B-Forms, student passports, guardian CNICs, employee CNICs, student medical profiles, and custody orders are lost forever.**

**WARNING: Losing `BLIND_INDEX_KEY` means all national ID, B-Form, and passport lookups stop matching until all hashes are recomputed. Recomputation is only possible while `APP_KEY` and the encrypted values still exist.**

## 7. Backup and Restore Checklist

- [ ] A database backup is only restorable if the matching `APP_KEY` (and `APP_PREVIOUS_KEYS`) and `BLIND_INDEX_KEY` are available.
- [ ] Database backup archives must contain ciphertext only; keys are never stored in the same bucket.
- [ ] Maintain a versioned record of which key versions correspond to each database backup.
- [ ] Test a restore on a staging copy with the matching keys before trusting any backup archive.
- [ ] Never put plain-text exports or unencrypted dumps of PII in a backup bucket.
