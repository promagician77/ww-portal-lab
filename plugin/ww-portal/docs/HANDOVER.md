# ww-portal: technical handover notes

This is the small custom layer that sits beside LearnDash and WooCommerce. It owns four things: finance snapshots, programme access windows, per-client resources, and the client dashboard. Everything else (courses, lessons, quizzes, assignments, checkout, subscriptions) stays in those plugins.

## 1. Install

1. Upload `ww-portal/` to `wp-content/plugins/` and activate it. Activation creates the tables, adds the `Coach` role, and creates `uploads/wwp-private/`.
2. Add the finance key to `wp-config.php`, above "That's all, stop editing":

   ```php
   define( 'WWP_DATA_KEY', 'paste 32 random bytes, base64-encoded' );
   // Generate one with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```

   Store a copy of this key somewhere other than the site and its backups, such as a password manager. **Without it, finance snapshots can't be read.** Keeping it out of the backups is also what makes a leaked backup useless to an attacker.
3. Use a real cron instead of WP-Cron for the nightly access sweep: set `define( 'DISABLE_WP_CRON', true );` and add a server cron entry, `*/15 * * * * wget -q -O - https://example.co.za/wp-cron.php?doing_wp_cron >/dev/null`. Paid renewals don't wait for cron, because they restore access on the same request.
4. **Nginx only:** block direct access to private files. On Apache, the `.htaccess` file the plugin writes already does this.

   ```nginx
   location ^~ /wp-content/uploads/wwp-private/ { deny all; return 403; }
   ```

## 2. LearnDash settings this relies on

- Course → Access settings → **Course Access Expiration: OFF.** The portal's access windows decide expiry, so LearnDash's optional "delete course data after expiration" can never run.
- Don't put programme courses into a LearnDash group that has an **end date**. A group end date overrides extensions, which would block renewals.
- Assignments: switch them on for worksheet lessons, with approval required. A coach reviews them as a Group Leader, or as an admin.

## 3. Programme configuration

The plugin reads the `wwp_programmes` option (it moves to a settings screen in Stage 2):

```php
update_option( 'wwp_programmes', array(
  'money-reset' => array(
    'label'     => 'Money Reset',
    'courses'   => array( 123 ),          // LearnDash course IDs
    'weeks'     => 10,                    // access length from the agreed start date
    'renew_url' => home_url( '/product/continue-monthly/' ),
  ),
) );
```

On each WooCommerce product, set the meta `_wwp_programme = money-reset`. If an order carries `_wwp_programme_start` (YYYY-MM-DD), access is granted straight away. If not, the client appears under **Client portal → Client access** as *Paid · needs start date*.

## 4. Shortcodes

| Shortcode | Where |
|---|---|
| `[wwp_dashboard]` | the client portal page |
| `[wwp_finance_form source="course:money-reset/week-2" title="…"]` | inside any lesson, or a standalone "My numbers" page |
| `[wwp_coach]` | the coach page (coaches and admins only) |

## 5. Data rules

- `wp_wwp_snapshots` is **append-only**: one row per save, and no code path updates a row. Each row stores a SHA-256 of the previous row, so `WWP_Finance::verify_chain( $user_id )` flags any row edited or deleted directly in the database.
- Payloads are XChaCha20-Poly1305 ciphertext. The client's user ID and the snapshot date are bound in as additional data, so moving a row to another user makes it unreadable instead of wrong.
- Expiry changes LMS access only. Snapshots, progress, assignments, orders and the WordPress role are never touched.
- The only delete path is **Tools → Erase Personal Data**, the WordPress core eraser. It is logged in **Client portal → Audit log**.

## 6. REST API

All routes live under `/wp-json/wwp/v1/` and use cookie auth with the `wp_rest` nonce.

- `GET /me/summary`, `GET|POST /me/snapshots`, `GET /me/snapshots/compare?from=&to=`, `GET /me/resources`. These take no user ID; they always mean the logged-in user.
- `GET /coach/clients`, `GET /coach/clients/{id}/snapshots`. These need the `wwp_coach` capability, and the client must be assigned to that coach (user meta `wwp_coach_id`). Every read is audited.

## 7. Tests

`php tests/run.php` runs the suite against a throwaway copy of a clean database. In the lab repo, it runs on WordPress 7.1.4 + SQLite. On staging, run it against a copy of the staging database, never production.

## 8. Not yet built (Stage 2)

- A settings screen for programmes, replacing the option above.
- Reminder emails 7 days and 1 day before access ends.
- A key-rotation command that re-encrypts rows from an old `key_id`.
- The LearnDash adapter and WooCommerce hooks need to be run against licensed installs on staging.
