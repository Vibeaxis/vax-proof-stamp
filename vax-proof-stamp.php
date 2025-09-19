<?php
/**
 * Plugin Name: VAX Proof Stamp (GitHub Ledger)
 * Description: On publish/update, hash the post body and append a JSON line to a GitHub repo ledger. Outputs a “Proof” link to the commit.
 * Version: 0.1.0
 * Author: VibeAxis
 * License: MIT
 */

if (!defined('ABSPATH')) exit;

define('VAX_PS_VER', '0.1.0');

/** -------- Settings: repo + token (use constants or options) --------
 * Prefer constants in wp-config.php (don’t store tokens in DB):
 * define('VAX_GH_TOKEN', 'ghp_xxx'); // fine-grained, Contents:RW, repo-scoped
 * define('VAX_GH_OWNER', 'vibeaxis');
 * define('VAX_GH_REPO',  'vibeaxis-proofs');
 * define('VAX_GH_PATH',  'ledger/ledger.jsonl'); // file to append
 * define('VAX_GH_BRANCH','main');
 */
function vax_ps_cfg($k, $default=''){
  $c = defined($k) ? constant($k) : null;
  if ($c !== null && $c !== '') return $c;
  $map = [
    'VAX_GH_OWNER' =>'vax_ps_owner',
    'VAX_GH_REPO'  =>'vax_ps_repo',
    'VAX_GH_PATH'  =>'vax_ps_path',
    'VAX_GH_BRANCH'=>'vax_ps_branch',
  ];
  if ($k === 'VAX_GH_TOKEN') {
    $env = getenv('VAX_GH_TOKEN');
    if ($env) return $env;
    $opt = get_option('vax_ps_token');
    return $opt ?: $default;
  }
  if (isset($map[$k])) {
    $v = get_option($map[$k], '');
    return $v !== '' ? $v : $default;
  }
  return $default;
}

/** -------- Canonicalize post to text + SHA256 -------- */
function vax_ps_hash_post($post_id){
  $raw = get_post_field('post_content', $post_id);
  $canon = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES|ENT_HTML5, 'UTF-8' );
  $canon = preg_replace('/\s+/', ' ', trim($canon));
  return hash('sha256', $canon);
}

/** -------- Append JSONL line to GitHub via Contents API -------- */
function vax_ps_append_to_github($line){
  $token  = vax_ps_cfg('VAX_GH_TOKEN');
  $owner  = vax_ps_cfg('VAX_GH_OWNER');
  $repo   = vax_ps_cfg('VAX_GH_REPO');
  $path   = vax_ps_cfg('VAX_GH_PATH', 'ledger/ledger.jsonl');
  $branch = vax_ps_cfg('VAX_GH_BRANCH', 'main');
  if (!$token || !$owner || !$repo) return new WP_Error('vax_ps_cfg', 'Missing GitHub config');

  $api  = "https://api.github.com/repos/$owner/$repo/contents/".rawurlencode($path);
  $hdrs = [
    'Authorization' => "Bearer $token",
    'User-Agent'    => 'VAX-ProofStamp',
    'Accept'        => 'application/vnd.github+json',
  ];

  // 1) Fetch existing file (to get the sha)
  $res = wp_remote_get($api, ['headers'=>$hdrs, 'timeout'=>15]);
  $sha = ''; $content = '';
  $code = !is_wp_error($res) ? wp_remote_retrieve_response_code($res) : 0;
  if ($code === 200) {
    $obj = json_decode(wp_remote_retrieve_body($res), true);
    $sha = $obj['sha'] ?? '';
    $content = base64_decode($obj['content'] ?? '');
  } elseif ($code !== 404) {
    return new WP_Error('vax_ps_get', 'GitHub GET failed');
  }

  $newContent = $content . $line;

  // 2) PUT updated file
  $payload = [
    'message' => 'stamp: '.$branch.' @ '.gmdate('c'),
    'content' => base64_encode($newContent),
    'branch'  => $branch,
  ];
  if ($sha) $payload['sha'] = $sha;

  $res2 = wp_remote_request($api, [
    'method' => 'PUT',
    'headers'=> $hdrs,
    'timeout'=> 20,
    'body'   => json_encode($payload),
  ]);
  if (is_wp_error($res2)) return $res2;
  $code2 = wp_remote_retrieve_response_code($res2);
  if (!in_array($code2, [200,201], true)) {
    return new WP_Error('vax_ps_put', 'GitHub PUT failed');
  }
  $obj2 = json_decode(wp_remote_retrieve_body($res2), true);
  return $obj2['commit']['sha'] ?? '';
}

/** -------- On publish/update: create stamp -------- */
add_action('transition_post_status', function($new, $old, $post){
  if ($new !== 'publish' || $post->post_type !== 'post') return;

  $sha256 = vax_ps_hash_post($post->ID);
  $entry = [
    'url'    => get_permalink($post->ID),
    'id'     => (int)$post->ID,
    'title'  => get_the_title($post->ID),
    'sha256' => $sha256,
    'ver'    => get_post_modified_time('c', true, $post->ID), // ISO8601 UTC
    'algo'   => 'sha256(canonical_text_v1)',
  ];
  $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
  $commit = vax_ps_append_to_github($line);
  if (!is_wp_error($commit) && $commit) {
    update_post_meta($post->ID, '_vax_proof_commit', sanitize_text_field($commit));
  }
}, 10, 3);

/** -------- Shortcode: [vax_proof] -------- */
add_shortcode('vax_proof', function($atts){
  if (!is_singular('post')) return '';
  $sha = get_post_meta(get_the_ID(), '_vax_proof_commit', true);
  $owner  = esc_attr( vax_ps_cfg('VAX_GH_OWNER') );
  $repo   = esc_attr( vax_ps_cfg('VAX_GH_REPO') );
  if (!$sha || !$owner || !$repo) return '';
  $short = esc_html( substr($sha,0,7) );
  $href  = esc_url("https://github.com/$owner/$repo/commit/$sha");
  return '<p class="va-proof">Proof: <a href="'.$href.'" rel="nofollow">ledger commit '.$short.'</a></p>';
});

/** -------- Settings page (if you don’t use constants) -------- */
add_action('admin_menu', function(){
  add_options_page('VAX Proof Stamp','VAX Proof Stamp','manage_options','vax-proof-stamp','vax_ps_settings');
});
add_action('admin_init', function(){
  register_setting('vax_ps', 'vax_ps_owner', ['sanitize_callback'=>'sanitize_text_field']);
  register_setting('vax_ps', 'vax_ps_repo',  ['sanitize_callback'=>'sanitize_text_field']);
  register_setting('vax_ps', 'vax_ps_path',  ['sanitize_callback'=>'sanitize_text_field']);
  register_setting('vax_ps', 'vax_ps_branch',['sanitize_callback'=>'sanitize_text_field']);
  register_setting('vax_ps', 'vax_ps_token', ['sanitize_callback'=>function($v){ return $v; }]); // token stored at your risk
});
function vax_ps_settings(){
  if (!current_user_can('manage_options')) return;
  ?>
  <div class="wrap">
    <h1>VAX Proof Stamp</h1>
    <p>Prefer <code>VAX_GH_*</code> constants in <code>wp-config.php</code>. Options below are for dev.</p>
    <form method="post" action="options.php">
      <?php settings_fields('vax_ps'); ?>
      <table class="form-table" role="presentation">
        <tr><th><label>Owner</label></th><td><input name="vax_ps_owner" value="<?php echo esc_attr(get_option('vax_ps_owner','')); ?>" class="regular-text"></td></tr>
        <tr><th><label>Repo</label></th><td><input name="vax_ps_repo"  value="<?php echo esc_attr(get_option('vax_ps_repo',''));  ?>" class="regular-text"></td></tr>
        <tr><th><label>Path</label></th><td><input name="vax_ps_path"  value="<?php echo esc_attr(get_option('vax_ps_path','ledger/ledger.jsonl')); ?>" class="regular-text"></td></tr>
        <tr><th><label>Branch</label></th><td><input name="vax_ps_branch" value="<?php echo esc_attr(get_option('vax_ps_branch','main')); ?>" class="regular-text"></td></tr>
        <tr><th><label>Token</label></th><td><input name="vax_ps_token" value="<?php echo esc_attr(get_option('vax_ps_token','')); ?>" class="regular-text" autocomplete="off"><p class="description">Fine-grained token with Contents: Read &amp; Write, repo-scoped. Better: use <code>VAX_GH_TOKEN</code> constant.</p></td></tr>
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
  <?php
}

/** -------- WP-CLI backfill (optional) -------- */
if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('vax-proofs backfill', function($args){
    $q = new WP_Query(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'date','order'=>'ASC']);
    $count=0;
    while($q->have_posts()){ $q->the_post();
      $id = get_the_ID();
      $sha = get_post_meta($id,'_vax_proof_commit',true);
      if ($sha) continue;
      $line = json_encode([
        'url'=>get_permalink($id),
        'id'=>(int)$id,
        'title'=>get_the_title($id),
        'sha256'=>vax_ps_hash_post($id),
        'ver'=>get_post_modified_time('c', true, $id),
        'algo'=>'sha256(canonical_text_v1)'
      ], JSON_UNESCAPED_SLASHES)."\n";
      $commit = vax_ps_append_to_github($line);
      if (!is_wp_error($commit) && $commit){ update_post_meta($id,'_vax_proof_commit',$commit); $count++; }
    }
    wp_reset_postdata();
    WP_CLI::success("Backfilled $count posts");
  });
}
// wherever you render the on-page Proof Stamp:
$sha = get_post_meta(get_the_ID(), '_vax_proof_commit', true);
if ($sha){
  $short = substr($sha,0,7);
  echo '<p class="va-proof">Proof: <a href="https://github.com/vibeaxis/vibeaxis-proofs/commit/'.$sha.'" rel="nofollow">ledger commit '.$short.'</a></p>';
}
echo do_shortcode('[vax_proof style="card" align="center"]');

// Override [vax_proof] to a pretty, flexible UI.
add_action('init', function () {
  if (shortcode_exists('vax_proof')) remove_shortcode('vax_proof');

  add_shortcode('vax_proof', function ($atts = []) {
    $a = shortcode_atts([
      'style' => 'card',         // card | pill | inline
      'align' => 'center',       // left | center | right
      'label' => 'Proof',        // leading label text
    ], $atts, 'vax_proof');

    $post_id = get_the_ID();
    if (!$post_id) return '';

    $commit = get_post_meta($post_id, '_vax_proof_commit', true);
    $hash   = get_post_meta($post_id, '_vax_proof_sha', true); // local fallback
    $title  = get_the_title($post_id);
    $time   = get_post_modified_time('c', true, $post_id);     // ISO for datetime
    $time_h = get_post_modified_time('M j, Y', true, $post_id);

    $is_commit = !empty($commit);
    $owner = function_exists('vax_ps_cfg') ? vax_ps_cfg('VAX_GH_OWNER') : '';
    $repo  = function_exists('vax_ps_cfg') ? vax_ps_cfg('VAX_GH_REPO')  : '';
    $href  = ($is_commit && $owner && $repo) ? "https://github.com/$owner/$repo/commit/$commit" : '';
    $short = $is_commit ? substr($commit, 0, 7) : ($hash ? substr($hash, 0, 12).'…' : '');

    $mode_text = $is_commit ? "ledger commit" : "local hash";
    $hash_for_copy = $is_commit ? $commit : ($hash ?: '');

    // classes
    $cls = [
      'va-proof',
      'va-proof--'.$a['style'],
      'va-proof-align--'.$a['align'],
      $is_commit ? 'is-ledger' : 'is-local'
    ];
    $cls = implode(' ', array_map('sanitize_html_class', $cls));

    // SVG check icon
    $icon = '<svg class="va-proof__svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.1 13.6-3.5-3.5 1.4-1.4 2.1 2.1 4.8-4.8 1.4 1.4-6.2 6.2Z"/></svg>';

    // link or code
    $core = $is_commit && $href
      ? '<a class="va-proof__link" href="'.esc_url($href).'" target="_blank" rel="nofollow noopener">'.$mode_text.' <b>'.$short.'</b></a>'
      : '<code class="va-proof__code">'.$mode_text.' <b>'.$short.'</b></code>';

    // copy button only if we have a value
    $btn = $hash_for_copy
      ? '<button type="button" class="va-proof__btn" data-hash="'.esc_attr($hash_for_copy).'" aria-label="Copy proof to clipboard">Copy</button>'
      : '';

    // Build HTML
    $out  = '<section class="'.$cls.'" data-proof-ts="'.esc_attr($time).'">';
    $out .=    '<div class="va-proof__inner">';
    $out .=      '<span class="va-proof__icon" aria-hidden="true">'.$icon.'</span>';
    $out .=      '<span class="va-proof__label">'.esc_html($a['label']).':</span> ';
    $out .=       $core;
    if ($btn) $out .= $btn;
    $out .=      '<time class="va-proof__time" datetime="'.esc_attr($time).'"> • '.$time_h.'</time>';
    $out .=    '</div>';
    $out .= '</section>';

    return $out;
  });
}, 20);
// Add a bulk action to Posts: "Stamp to Ledger"
add_filter('bulk_actions-edit-post', function($actions){
  $actions['vax_stamp_ledger'] = 'Stamp to Ledger';
  return $actions;
});

// Handle it (re-uses the plugin's helpers if present)
add_filter('handle_bulk_actions-edit-post', function($redirect, $action, $post_ids){
  if ($action !== 'vax_stamp_ledger') return $redirect;

  $ok = 0; $fail = 0;
  foreach ($post_ids as $id) {
    // Always compute + store local hash
    if (function_exists('vax_ps_hash_post')) {
      $sha = vax_ps_hash_post($id);
      update_post_meta($id, '_vax_proof_sha', $sha);
    }

    // Only try ledger if token exists
    if (function_exists('vax_ps_cfg') && vax_ps_cfg('VAX_GH_TOKEN')) {
      // Build JSON line like the plugin does
      $entry = [
        'url'    => get_permalink($id),
        'id'     => (int)$id,
        'title'  => get_the_title($id),
        'sha256' => vax_ps_hash_post($id),
        'ver'    => get_post_modified_time('c', true, $id),
        'algo'   => 'sha256(canonical_text_v1)',
      ];
      $line = json_encode($entry, JSON_UNESCAPED_SLASHES)."\n";

      // Append via the plugin's GitHub helper
      if (function_exists('vax_ps_append_to_github')) {
        $commit = vax_ps_append_to_github($line);
        if (!is_wp_error($commit) && $commit) { update_post_meta($id, '_vax_proof_commit', $commit); $ok++; }
        else { $fail++; }
      } else {
        // Fallback: bump modified to trigger normal hook later
        wp_update_post(['ID'=>$id]);
      }
    } else {
      // No token: just local hashes updated
      $ok++;
    }
  }
  return add_query_arg(['vax_bulk_ok'=>$ok, 'vax_bulk_fail'=>$fail], $redirect);
}, 10, 3);

// Tiny admin notice
add_action('admin_notices', function(){
  if (!isset($_GET['vax_bulk_ok'])) return;
  $ok = (int)$_GET['vax_bulk_ok']; $fail = (int)($_GET['vax_bulk_fail'] ?? 0);
  echo '<div class="notice notice-success"><p>VAX Proof: stamped '.$ok.' posts'
     . ($fail ? ' ('.$fail.' failed).' : '.') .'</p></div>';
});
add_action('rest_api_init', function(){
  register_rest_route('vax/v1', '/proof/(?P<id>\d+)', [
    'methods'  => 'GET',
    'callback' => function($req){
      $id = (int)$req['id']; if (!$id || get_post_status($id) !== 'publish') {
        return new WP_Error('vax_not_found','Post not found', ['status'=>404]);
      }
      $commit = get_post_meta($id, '_vax_proof_commit', true);
      $hash   = get_post_meta($id, '_vax_proof_sha', true);
      return [
        'mode'   => $commit ? 'ledger' : ($hash ? 'local' : 'none'),
        'commit' => $commit ?: null,
        'hash'   => $hash   ?: null,
        'url'    => get_permalink($id),
        'ver'    => get_post_modified_time('c', true, $id),
      ];
    },
    'permission_callback' => '__return_true'
  ]);
});
add_action('wp_footer', function(){ ?>
<script id="vax-proof-copy">
document.addEventListener('click',e=>{
  const b=e.target.closest('.va-proof__btn'); if(!b) return;
  const v=b.getAttribute('data-hash'); if(!v) return;
  navigator.clipboard.writeText(v).then(()=>{
    const t=b.textContent; b.textContent='Copied!'; setTimeout(()=>b.textContent=t,1200);
  }).catch(()=>{});
},{passive:true});
</script>
<?php }, 99);
// === VAX Verify REST endpoint ===
// GET /wp-json/vax/v1/verify?url=https://vibeaxis.com/some-post/
add_action('rest_api_init', function () {
  register_rest_route('vax/v1', '/verify', [
    'methods'  => 'GET',
    'callback' => function($req) {
      $url = trim((string)$req->get_param('url'));
      if (!$url) return new WP_Error('vax_bad_req','Missing url', ['status'=>400]);

      // 1) Resolve to local post id (only for your domain)
      $site = home_url('/');
      $is_local = stripos(rtrim($url,'/').'/',$site) === 0;
      $post_id = $is_local ? url_to_postid($url) : 0;

      // 2) Compute fresh hash from live content (canonicalized)
      $compute = function($id_or_url) use ($is_local, $post_id) {
        if ($is_local && $post_id) {
          $raw = get_post_field('post_content', $post_id);
        } else {
          // remote fetch (same-origin is ideal; CORS not relevant server-side)
          $res = wp_remote_get($id_or_url, ['timeout'=>15]);
          if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
          $raw = wp_remote_retrieve_body($res);
        }
        $text = html_entity_decode( wp_strip_all_tags($raw), ENT_QUOTES|ENT_HTML5, 'UTF-8' );
        $text = preg_replace('/\s+/', ' ', trim($text));
        return hash('sha256', $text);
      };
      $computed = $compute($url);

      // 3) Local stored hash + commit (if post is ours)
      $stored = $commit = null;
      $verISO = null;
      if ($post_id) {
        $stored = get_post_meta($post_id, '_vax_proof_sha', true) ?: null;
        $commit = get_post_meta($post_id, '_vax_proof_commit', true) ?: null;
        $verISO = get_post_modified_time('c', true, $post_id);
      }

      // 4) Ledger lookup (optional, if repo settings exist)
      $ledger_sha = null;
      if (function_exists('vax_ps_cfg')) {
        $owner  = vax_ps_cfg('VAX_GH_OWNER');
        $repo   = vax_ps_cfg('VAX_GH_REPO');
        $branch = vax_ps_cfg('VAX_GH_BRANCH', 'main');
        $path   = vax_ps_cfg('VAX_GH_PATH', 'ledger/ledger.jsonl');
        if ($owner && $repo && $branch && $path) {
          $raw_url = "https://raw.githubusercontent.com/$owner/$repo/$branch/$path";
          $res = wp_remote_get($raw_url, ['timeout'=>15, 'headers'=>['User-Agent'=>'VAX-Verify']]);
          if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $body = wp_remote_retrieve_body($res);
            // scan from bottom for the most recent matching URL
            $lines = preg_split('/\r\n|\r|\n/', $body);
            $needle = rtrim($url,'/').'/';
            for ($i = count($lines)-1; $i >= 0; $i--) {
              $line = trim($lines[$i]);
              if ($line === '') continue;
              $j = json_decode($line, true);
              if (isset($j['url'])) {
                $u = rtrim((string)$j['url'],'/').'/';
                if ($u === $needle) { $ledger_sha = (string)($j['sha256'] ?? ''); break; }
              }
            }
          }
        }
      }

      // 5) Verdicts
      $match_local  = ($computed && $stored) ? hash_equals($computed, $stored) : null;
      $match_ledger = ($computed && $ledger_sha) ? hash_equals($computed, $ledger_sha) : null;

      // 6) Useful links
      $commit_url = null;
      if ($commit && function_exists('vax_ps_cfg')) {
        $owner  = vax_ps_cfg('VAX_GH_OWNER');
        $repo   = vax_ps_cfg('VAX_GH_REPO');
        if ($owner && $repo) $commit_url = "https://github.com/$owner/$repo/commit/$commit";
      }

      return [
        'url'           => $url,
        'post_id'       => $post_id ?: null,
        'computed_sha'  => $computed ?: null,
        'stored_sha'    => $stored,
        'ledger_sha'    => $ledger_sha,
        'match_local'   => $match_local,   // true/false/null
        'match_ledger'  => $match_ledger,  // true/false/null
        'commit'        => $commit,
        'commit_url'    => $commit_url,
        'last_modified' => $verISO,
      ];
    },
    'permission_callback' => '__return_true',
  ]);
});
// === VAX Verify UI shortcode ===
// Usage: [vax_verify]
add_shortcode('vax_verify', function(){
  ob_start(); ?>
  <section class="va-verify">
    <style>
      .va-verify{ --ink:#0B0B0F; --muted:#576071; --line:#E5E7EB; --mint:#2CE5B5; --sky:#7AB6FF; max-width:880px; margin:24px auto; padding:14px }
      .va-verify h2{ margin:0 0 10px; font:800 28px/1.15 system-ui,Segoe UI,Inter,Arial,sans-serif; color:var(--ink) }
      .va-verify p{ margin:6px 0 14px; color:var(--muted) }
      .va-verify .bar{ display:flex; gap:8px; margin:8px 0 14px }
      .va-verify input[type="url"]{ flex:1; padding:12px 14px; border:1px solid var(--line); border-radius:12px; font:600 14px/1 system-ui,Segoe UI,Inter,Arial,sans-serif }
      .va-verify button{ padding:12px 16px; border-radius:12px; border:0; cursor:pointer; font:800 14px/1 system-ui,Segoe UI,Inter,Arial,sans-serif; color:#fff;
        background:linear-gradient(135deg,var(--mint),var(--sky)) }
      .va-verify .card{ border:1px solid var(--line); border-radius:14px; padding:14px 16px; background:#fff; box-shadow:0 8px 20px rgba(0,0,0,.05) }
      .va-verify .row{ display:flex; align-items:center; gap:10px; margin:6px 0 }
      .va-verify .dot{ width:10px; height:10px; border-radius:999px; }
      .va-verify .ok{ background:#22c55e } .va-verify .bad{ background:#ef4444 } .va-verify .meh{ background:#a8a29e }
      .va-verify code{ background:#F5F7FA; border:1px solid var(--line); padding:2px 6px; border-radius:8px; font:600 12px ui-monospace,SFMono-Regular,Consolas,Menlo,monospace }
      .va-verify .muted{ color:var(--muted) }
      .va-verify a{ color:inherit; text-decoration: none; border-bottom:1px dotted rgba(0,0,0,.25) }
      .va-verify a:hover{ opacity:.85 }

    </style>

    <h2>Verify a VibeAxis post</h2>
    <p>Paste a VibeAxis URL. We re-hash the live page, compare to the stored stamp, and (if present) the public ledger.</p>

    <div class="bar">
      <input id="va-v-url" type="url" placeholder="https://vibeaxis.com/your-post/" inputmode="url" spellcheck="false" />
      <button id="va-v-go" type="button">Verify</button>
    </div>

    <div id="va-v-out" class="card" hidden>
      <div class="row"><div class="dot meh" id="va-v-local-dot"></div><div><b>Local stamp</b> <span class="muted">(stored hash)</span></div></div>
      <div class="row"><div class="dot meh" id="va-v-ledger-dot"></div><div><b>Ledger</b> <span class="muted">(GitHub)</span> — <a id="va-v-commit" href="#" target="_blank" rel="nofollow" hidden>commit</a></div></div>
      <div class="row"><div class="muted">Live (now):</div> <code id="va-v-now">—</code></div>
      <div class="row"><div class="muted">Stored:</div> <code id="va-v-stored">—</code></div>
      <div class="row"><div class="muted">Ledger:</div> <code id="va-v-ledger">—</code></div>
    </div>

    <script>
      (function(){
        const $ = sel => document.querySelector(sel);
        const urlIn = $('#va-v-url');
        const go = $('#va-v-go');
        const out = $('#va-v-out');
        const now = $('#va-v-now'), stored = $('#va-v-stored'), ledg = $('#va-v-ledger');
        const dLocal = $('#va-v-local-dot'), dLedg = $('#va-v-ledger-dot'), aCommit = $('#va-v-commit');
        function dot(el, state){ el.className = 'dot ' + (state==='ok'?'ok':state==='bad'?'bad':'meh'); }
        async function verify(u){
          out.hidden = true;
          dot(dLocal,'meh'); dot(dLedg,'meh'); aCommit.hidden = true; aCommit.removeAttribute('href');
          now.textContent = stored.textContent = ledg.textContent = '—';
          try{
            const ep = new URL('/wp-json/vax/v1/verify', window.location.origin);
            ep.searchParams.set('url', u);
            const res = await fetch(ep.toString(), {credentials:'same-origin'});
            const j = await res.json();
            if(j && j.url){
              now.textContent    = j.computed_sha || '—';
              stored.textContent = j.stored_sha   || '—';
              ledg.textContent   = j.ledger_sha   || '—';
              if (j.match_local === true)  dot(dLocal,'ok');
              else if (j.match_local === false) dot(dLocal,'bad');
              if (j.commit_url){ aCommit.href = j.commit_url; aCommit.hidden = false; }
              if (j.match_ledger === true) dot(dLedg,'ok');
              else if (j.match_ledger === false) dot(dLedg,'bad');
              out.hidden = false;
            } else {
              alert('Could not verify that URL.');
            }
          }catch(e){ alert('Verify failed.'); }
        }
        go.addEventListener('click', ()=>{ if(urlIn.value) verify(urlIn.value.trim()); });
        urlIn.addEventListener('keydown', (e)=>{ if(e.key==='Enter') go.click(); });
      })();
    </script>
  </section>
  <?php
  return ob_get_clean();
});
add_shortcode('vax_verify_button', function($atts){
  $a = shortcode_atts([
    'text' => 'Verify us',
    'href' => '/verify',
  ], $atts, 'vax_verify_button');
  $txt = esc_html($a['text']);
  $url = esc_url($a['href']);
  return '<a class="va-verify-btn" href="'.$url.'" aria-label="'.$txt.'">'.$txt.'</a>';
});
