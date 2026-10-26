# MediTrail Pro starter

A PHP, Bootstrap, and MySQL dashboard starter based on the MediTrail Pro blueprint.
The current milestone includes a responsive dashboard, family-member CRUD
(create, view, edit, archive/restore, and guarded permanent deletion), and a
small MySQL schema with clearly fictional demonstration records.

## Run with XAMPP

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin`.
3. Select **Import**, choose `database/schema.sql`, and run the import.
4. If you already imported an older `schema.sql`, also import
   `database/migrations/002_add_users_and_ownership.sql` (adds accounts).
5. Open `http://localhost:8080/MedicalRecords/register.php` and create an
   account. The **first** account adopts the demo family members; later accounts
   start empty and can never see another account's records.
6. Choose **Family members** in the navigation, or open
   `http://localhost:8080/MedicalRecords/family_members.php`, to manage profiles.
7. Choose **Appointments**, or open
   `http://localhost:8080/MedicalRecords/appointments.php`, to add, edit, and
   delete appointments for family members.
8. Choose **Medications**, or open
   `http://localhost:8080/MedicalRecords/medications.php`, to add, edit, and
   delete medication records. Instructions are entered by the user; the app
   does not generate dosing guidance.
9. Choose **Medical profile** to record allergies, conditions and emergency
   contacts per family member. Existing databases need
   `database/migrations/003_add_profile_entries.sql`.
10. Choose **Reminders** to see today's active medications and mark each dose
    Taken or Skipped (with Undo). Existing databases need
    `database/migrations/004_add_dose_events.sql`. These are on-screen
    reminders only; no emails or push notifications are sent.
11. Choose **Health tracking** to log weight, blood pressure, blood glucose,
    heart rate and temperature per family member, with a trend chart. Existing
    databases need `database/migrations/005_add_health_readings.sql`. The app
    records numbers only and never interprets them.
12. Choose **Documents** to upload PDF, JPG or PNG files (up to 5 MB) per family
    member. Existing databases need `database/migrations/006_add_documents.sql`.
    Files are saved in `storage/documents` under random names, are blocked from
    direct web access, and can only be downloaded by their owner.
13. Choose **Settings** to change your password, download your data as JSON, or
    delete your account.
14. Choose **Activity** (in the sidebar or header) to see your recent sign-ins and
    changes. Existing installs must first run
    `database/migrations/007_add_audit_log.sql`. Only the action, record type,
    time and IP address are stored, never health details.
15. If you forget your password, use **Forgot your password?** on the sign-in
    page. Existing installs must first run
    `database/migrations/008_add_password_resets.sql`. Links are single-use and
    expire after 30 minutes. This local build has no mail server, so the email
    is written to `storage/mail.log`; open that file to copy the link. For real
    email set `APP_MAIL_MODE=smtp` (with PHP `mail()` configured) and `APP_URL`
    to your public address.
16. Security hardening: every page sends a Content-Security-Policy and other
    security headers, and changing or resetting a password signs out every other
    device. Existing installs must first run
    `database/migrations/009_add_session_version.sql`. Destructive-action
    confirmations use `assets/js/confirm.js` (`data-confirm` on a form).
17. The dashboard shows today's dose progress, the latest reading per family
    member and a real recent-records count. Choose **Health summary** to open a
    one-page printable summary (allergies, conditions, medications, readings,
    appointments, emergency contacts) and use **Print or save as PDF** to share
    it with a clinician. Each view is recorded on the Activity page.
18. Choose **Calendar** for a month view of all appointments (click one to edit
    it). The dashboard marks appointments due today, tomorrow or within a week.
    On **Health tracking**, **Export all readings (CSV)** downloads every reading
    for the selected member; the export is recorded on the Activity page.
19. Run `database/migrations/010_add_medication_supply.sql` on an existing
    database. Medications can then track "doses on hand": Taken on the Reminders
    page uses one up (Skip or Undo gives it back), the Medications page has a
    Refill box, and the dashboard warns at 7 doses or fewer. **Dose history**
    shows a 30-day taken / skipped / not-recorded grid per medication.
20. Run `database/migrations/011_add_appointment_details.sql` on an existing
    database. Appointments can then record a visit category and mode, optional
    time, location, visit notes and private photo/PDF attachments. Details from
    uploaded screenshots are not automatically extracted.

The default local database settings are in `config/database.php`: host
`127.0.0.1`, database `meditrail_pro`, user `root`, and an empty password. To use
different local settings, set the `DB_HOST`, `DB_NAME`, `DB_USER`, and
`DB_PASSWORD` environment variables before starting Apache.

The page needs PHP's PDO MySQL extension enabled. If the dashboard reports a
database error, check that MySQL is running, the schema import succeeded, and
the connection settings match your local XAMPP setup.

## Current scope and safety

This is an early, local-development starter—not a finished medical-records
system. Family profiles can be created, viewed, edited, archived, restored, and
permanently deleted only when they have no associated appointments or
medications. Archive preserves linked records rather than permanently deleting
them. Family,
medication, and appointment entries in the SQL script are synthetic demo data;
do not put real health information in this application.

Accounts use hashed passwords, CSRF-protected forms, a 30-minute idle timeout and
a 15-minute lockout after 5 failed sign-ins. There is no email confirmation,
password reset, family-sharing workflow, secure document
storage, or production security review yet. Do not deploy this site or use it
with real health records until those controls and the blueprint's privacy,
security, testing, and legal review requirements have been implemented.

The stylesheet and Bootstrap assets are loaded from CDNs, so an internet
connection is needed to load those assets in the default setup.
