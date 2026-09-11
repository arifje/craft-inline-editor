# Integration tests

These scripts exercise the plugin against a **real Craft install** (database, CKEditor plugin, HTTP). They are not run by `composer test`; they document how the release was verified and let you repeat it.

They were run for 1.8.0 against:

| Install | Craft | craftcms/ckeditor | Editor build on the frontend |
|---------|-------|-------------------|------------------------------|
| craft4  | 4.18.8 | 3.15.0 | CKEditor CDN 43.3.1 |
| craft5b | 5.11.1 | 4.11.4 | CKEditor CDN 43.3.1 |
| craft5  | 5.11.1 | 5.7.0  | build shipped with the CKEditor plugin (48.0.1) |

## Setup

1. Create a Craft project (`composer create-project craftcms/craft <dir> --no-install`), require the CKEditor plugin line you want to test and this plugin (e.g. as a `path` repository), run `craft install`, and install both plugins.
2. Load the fixtures:

   ```bash
   php tests/integration/fixtures.php /path/to/install
   ```

   This creates a `uploads` filesystem/volume, a `topics` tag group, CKEditor fields with different configurations (`inlineBody`, `inlineIntro`, `inlineFromFile` with a `config/ckeditor/inline-test.js` file, `inlineMissingFile` pointing at a file that does not exist), plain text, URL/Link, Tags and Assets fields, an `inlineTest` section with two entries (`alpha`, and `beta` whose body contains nested-entry markup written straight to the database), user groups `inlineWriters` (section + volume permissions) and `inlineReaders` (no permissions), users `writer`, `reader` and `outsider` (password `password123`), the plugin settings, and the templates `templates/inline-test.twig` and `templates/login.twig`. On craftcms/ckeditor 3.x/4.x it also creates the shared configs "Inline Full" and "Inline Minimal". It is safe to run more than once.
3. Serve the install, e.g. with PHP's built-in server and the router in this directory:

   ```bash
   php -S 127.0.0.1:8085 -t /path/to/install/web tests/integration/router.php
   ```

   The admin user must be `admin` / `password123` (set with `php craft users/set-password admin`).

## Run

```bash
php tests/integration/http-test.php http://127.0.0.1:8085
```

The script logs in as guest, admin, writer, reader and outsider and checks rendering (wrappers only for permitted users, per-field CKEditor config registration, raw content with reference tags, read-only rendering of nested entries), the editor build URLs, saves of every supported field type, nested-entry guards, tag creation/search, asset upload into a restricted location and removal, CSRF rejection, and element/volume authorisation.

The CKEditor UI itself (toolbar contents, dropped items, config options, discard/re-open, save round trip, link attribute preservation) was verified in a browser on the same installs; there is no scripted browser test.
