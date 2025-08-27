# VAX Proof Stamp (GitHub Ledger)

On publish/update, this plugin hashes your post body (SHA-256) and appends a JSON line to a public GitHub repo (a simple `ledger.jsonl`). It stores the commit SHA in post meta and provides a `[vax_proof]` shortcode that links to the exact commit.

## Why
Receipts > vibes. Public, third-party timestamp + immutable history = trust.

## Install
1. Copy to `/wp-content/plugins/vax-proof-stamp/` and activate.
2. **Preferred**: define constants in `wp-config.php`:
   ```php
   define('VAX_GH_TOKEN',  'ghp_xxx');      // fine-grained, Contents:RW, repo-scoped
   define('VAX_GH_OWNER',  'YOURUSER');
   define('VAX_GH_REPO',   'vibeaxis-proofs');
   define('VAX_GH_PATH',   'ledger/ledger.jsonl');
   define('VAX_GH_BRANCH', 'main');
Or set them under Settings → VAX Proof Stamp (dev only).

3. Add [vax_proof] where you want the stamp, or echo it in your template.

Format

Each line in ledger.jsonl:

{"url":"https://…","id":123,"title":"…","sha256":"…","ver":"2025-08-26T01:23:45Z","algo":"sha256(canonical_text_v1)"}

WP-CLI

wp vax-proofs backfill — stamps all published posts that don’t have a commit yet.

License

MIT


---

## 4) Ship it
