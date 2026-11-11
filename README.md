# BDRIS Birth Registration Form

A standalone PHP form for submitting birth registration applications to the BDRIS portal.

## Features

- Bengali-language single-page form
- Division, district, upazila, union and ward cascade
- Attachment type detection by date of birth and file upload
- OTP send and verify flow
- Local success page with the full application summary and print/PDF action
- No database; state and cookies are stored in a per-session cache directory

## Requirements

- PHP 8.1 or newer
- PHP extensions: `curl`, `mbstring`, `json`, `session`
- A writable cache directory

## Setup

1. Copy `form.php` to a web-accessible directory.
2. Create the cache directory next to `form.php`:

   ```sh
   mkdir -p cache/ff
   chmod 700 cache cache/ff
   ```

3. Serve `form.php` directly with PHP-FPM, Apache, Nginx, or the PHP built-in server:

   ```sh
   php -S 127.0.0.1:8099
   ```

4. Open `http://127.0.0.1:8099/form.php`.

The defaults at the top of `form.php` can be changed:

```php
define('FF_UP', 'https://bdris.gov.bd');
define('FF_TTL', 1500);
define('FF_CACHE', __DIR__ . '/cache/');
```

## Usage

1. Fill in the child, parent, applicant, address and contact fields.
2. Upload the required attachments.
3. Send and verify the mobile OTP.
4. Submit the application.
5. Save or print the local acknowledgement page.

## Single-word names

BDRIS splits the child's name into first and last parts. For a one-word name, leave the first-name field empty and put the full name in the last-name field. The form joins the parts with a space, so the submitted full name stays exactly the one word. The same rule applies to the father's and mother's names.

For a multi-word name, put everything except the last word in the first-name field and the last word in the last-name field. Example: `মোছাঃ সুরাইয়া আকতার` → first part `মোছাঃ সুরাইয়া`, last part `আকতার`.

## Notes

- The cache directory stores session state and upstream cookies. Keep it outside the public web root or deny direct HTTP access to it.
- Serve the form over HTTPS in production.
- This project is not affiliated with BDRIS.
