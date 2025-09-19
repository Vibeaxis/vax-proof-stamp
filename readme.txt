=== VAX Proof Stamp ===
Contributors: vibeaxis
Author: VibeAxis
Author URI: https://vibeaxis.com
Tags: verification, integrity, ledger, github, receipts
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT
Text Domain: vax-proof-stamp

On publish/update, hash the post content (canonicalized) and append a JSON line to a GitHub "ledger" file. Adds a [vax_proof] shortcode that links to the exact commit.

== Description ==

**What it does**
- On post save (published posts), computes a SHA-256 over canonicalized content.
- Appends a JSON line to a GitHub repo path (e.g., `ledger/ledger.jsonl`) via the Contents API.
- Stores the resulting commit SHA to post meta, so you can render a "Proof" link.
- Shortcodes:
  - `[vax_proof]` → render a small proof pill/card with copy-to-clipboard
  - `[vax_verify]` → optional UI to verify a URL against local/ledger (same-origin only)

**Why**
Receipts. Show that a post hasn't silently changed since publish (or show when it did). Commits are public; diffs are obvious.

**Security**
Prefer constants in `wp-config.php`. If you use the token option, understand it stores in the DB. The REST `verify` endpoint only allows same-origin URLs to avoid SSRF.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/` or install from a ZIP.
2. Activate the plugin.
3. In `wp-config.php` set:
```
define('VAX_GH_TOKEN',  'ghp_xxx');          // fine-grained, repo-scoped, Contents: RW
define('VAX_GH_OWNER',  'your-org');
define('VAX_GH_REPO',   'your-ledger-repo');
define('VAX_GH_PATH',   'ledger/ledger.jsonl');
define('VAX_GH_BRANCH', 'main');
```
4. (Optional) Use Settings → VAX Proof Stamp for dev-time config.

== Frequently Asked Questions ==

= Does this stamp on edits? =
Yes. We hook `save_post` for published posts and skip if content hash hasn't changed to reduce noisy commits.

= What if two edits happen at once? =
We include the file `sha` when updating and retry on a 409 conflict with a fresh GET.

= Can I verify non-VibeAxis sites? =
No. The public verify endpoint only supports same-origin URLs (your own site).

== Changelog ==
= 0.1.0 =
* Initial release.
