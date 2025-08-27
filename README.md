# vax-proof-stamp
On publish/update, this plugin hashes your post body (SHA-256) and appends a JSON line to a public GitHub repo (a simple `ledger.jsonl`). It stores the commit SHA in post meta and provides a `[vax_proof]` shortcode that links to the exact commit.
