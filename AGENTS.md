# AGENTS.md

## Purpose
This file helps AI coding agents understand the Dazz repository quickly. It explains core files, runtime assumptions, and the safest way to change behavior.

## Repository overview
- Legacy PHP automation for job lead scraping, email generation, and message sending.
- Uses Composer dependencies in `vendor/` and classes under `src/`.
- Main workflows are implemented as top-level PHP scripts rather than a formal application framework.
- The project runs on Windows with a local MySQL database and a hard-coded Chrome executable path.

## Key files
- `composer.json` - dependency manifest. `vendor/autoload.php` is required by most scripts.
- `src/Config.php` - environment constants for Chrome and database access.
- `src/Scraper.php` - headless browser scraper using `chrome-php/chrome`.
- `src/Scraperprofesia.php` - a second scraper variant used for Profesia scraping.
- `src/Mailer.php` - core email-sending logic, including spintax and SMTP via PHPMailer.
- `src/MailerSk.php` - language-specific mailing helper with placeholders for company/contact data.
- `dashboard.php` - reporting/dashboard script.
- `run_euro.php`, `run_euroos.php`, `2run_latvia.php`, `3run_eures.php`, `4run_profesia.php`, `6run_Slovak_Euros.php`, `run_profesia_live.php` - main scraper/email runner scripts.
- `EmailTemplate/` - HTML templates used by the mailer.
- `Datas/` - data folders for blocked addresses, mailing lists, sent reports, and archives.

## Run and install guidance
- Install dependencies with Composer if needed: `composer install` from repository root.
- Scripts expect PHP CLI or Apache/PHP server on Windows.
- Chrome is expected at `C:\Program Files\Google\Chrome\Application\chrome.exe` unless `src/Config.php` is changed.
- `2run_latvia.php` uses Playwright; the repo also contains `playwright-php/playwright` as a dev dependency.

## Agent guidance
- Prefer edits in `src/` classes for reusable logic instead of patching multiple top-level scripts.
- When fixing scraping behavior, inspect the relevant script first to determine whether it uses `src/Scraper.php`, `src/Scraperprofesia.php`, or Playwright directly.
- When modifying email flow, preserve the existing cooldown/blocked domain logic in `src/Mailer.php` and `src/MailerSk.php`.
- Do not assume modern PHP frameworks or unit tests exist. Keep changes minimal and explicit.

## Important conventions and risks
- The repo contains real email sending and database update code. Avoid executing mailer scripts on production-like data without explicit user approval.
- Sensitive settings are hard-coded in `src/Config.php` (DB credentials, Chrome path), so do not change them unless requested.
- The repository is primarily procedural; many scripts are intended to be run directly by filename.

## When in doubt
Ask before making large structural changes. If a task involves scraping or email sending, verify which runner script is in use and whether the user wants production data affected.
