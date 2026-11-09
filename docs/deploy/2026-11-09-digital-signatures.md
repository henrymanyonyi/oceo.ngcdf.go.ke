# Deployment: digital signatures and document integrity (9 November 2026, revised 10 November 2026)

**Revised 10 November 2026: signatures are drawn, not uploaded.** Officers draw their signature on a signature pad (mouse, finger or pen) in Profile › Signature, saved with their password, or in the Submit, Forward or Approve popup the first time they sign, saved with their password and code. The server accepts only a drawing from the pad (right size, real ink, not blank or a filled block), redraws it so nothing but the pixels is kept, and emails and texts the officer that their signature was saved. There is no verification step any more: the Signatures page (System Administrator, CEO) lists the signatures in use and who still has to draw one, and deactivates one. **Every** authorisation now needs a saved signature as well as the password and code; an officer without one is asked to draw it in the popup. Run `npm ci && npm run build` (the pad uses new styles). Where this guide below speaks of uploading and verifying, read drawing.

Also on 10 November 2026: **Estimated budget (KES)** is removed from every memo form and page (the department memo form, the Office of the CEO's form and the officer's delegated memo page) and from the memo checks. Older memos keep their stored figure; it is not shown and not changed.

## What changes

| Area | Change |
|---|---|
| Profile › Signature | Every officer who signs memos (Heads of Department, Assistant, Chief of Staff, CEO) uploads a PNG or JPEG of their signature (up to 512 KB). It is **not used** until someone else verifies it. A new upload waits for verification while the current signature stays in use. The officer can deactivate theirs at any time. |
| Signatures (menu, System Administrator and CEO) | Uploads awaiting verification, to **Activate** or **Do not accept** with a reason; signatures in use, to **Deactivate**. Nobody verifies their own: the CEO's signature is verified by the System Administrator. |
| Signing a memo | **Submit** (Head of Department), **Forward** (Assistant, Chief of Staff) and **Approve** (CEO) now ask for the officer's **password** and a **six-digit code** sent to their own email and mobile (**Send me a code** in the popup). The code works once, for that memo and that step, for 10 minutes; 5 wrong tries and it stops working; 3 codes per 10 minutes. Returning, declining, commenting and drafting are unchanged. |
| The memo PDF | Each signature block shows the officer's verified signature, the date and time, and a **verification code**, only if that officer signed that step in OCEO. If the memo has changed since (for example after an amendment), the block says the signature was for an earlier version and shows no image. A block nobody signed in OCEO stays blank. Memos from before this release are unchanged. |
| Decisions recorded on the CEO's behalf | The "Approved by" block no longer prints the name of the person who recorded it. It says the decision was given outside the system, with the memo reference and who recorded it, and carries no signature. |
| Verify a signature | `/verify` (signed-in users): type a code from a memo to see who signed, as what, when, how they confirmed it, and whether the memo still reads the same. Only for memos the user may read. |
| Logs | Codes sent, failed confirmations and signature views are in the access log; signature uploads, activations and deactivations in the audit trail; codes are scrubbed from the Email and SMS log once sent. |

## Before deploying

- Every account that signs needs an **email or a mobile number** (the account's, or its officer's on the staff list). Without one, the officer cannot receive a code and cannot submit, forward or approve. Check: `php artisan tinker --execute 'App\Models\User::query()->where("is_active", true)->get()->filter(fn ($u) => blank($u->email) && ! $u->mobileNumber())->each(fn ($u) => print($u->name.PHP_EOL));'` prints nobody.
- Mail and SMS must work in production (`MAIL_*`, `SMSLEOPARD_*`): a code that does not arrive blocks the step.

## Steps

1. **Back up** the production database.
2. Pull the code, `composer install --no-dev --optimize-autoloader`.
3. `php artisan migrate --force`. One new migration, `2026_11_09_231022_create_digital_signature_tables`: three new tables (`user_signatures`, `signing_challenges`, `signing_events`). No existing data is changed.
4. `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (adds `signatures.verify` to the System Administrator and the CEO).
5. Make sure `.env` does **not** set `ACTIVITIES_SIGNING_STEP_UP=false`. Only the test suite switches it off.
6. `npm ci && npm run build`, then `php artisan optimize:clear && php artisan optimize`, restart PHP-FPM, `php artisan queue:restart`.
7. Ask each signing officer to upload their signature in Profile, and the System Administrator to verify them (the CEO's first). Until then memos carry the officer's name and the verification code without an image.

## Rollback

Code only, once anything has been signed: the signing records are part of the official record and must not be dropped (CLAUDE.md, database safety). `php artisan migrate:rollback --step=1` is safe only before the first signature or signing.

## Check after deploying

- Profile shows a **Signature** section for a Head of Department; the menu shows **Signatures** for the System Administrator.
- Submitting a memo asks for the password and a code; the code arrives by email and SMS.
- The Email and SMS log shows the signing code message with the code replaced by `******`.
