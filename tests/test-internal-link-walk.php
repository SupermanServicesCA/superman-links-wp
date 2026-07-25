<?php
/**
 * Standalone harness for the v2.3.0 internal-link walk.
 * Stubs just enough WordPress to load class-api.php and exercise the private
 * walk/guard methods via reflection. No WP, no DB, no network.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__meta'] = [];
$GLOBALS['__meta_writes'] = 0;

function add_action() {}
function add_filter() {}
function __($s, $d = null) { return $s; }
function esc_url($s) { return $s; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function wp_json_encode($d) { return json_encode($d); }
function wp_slash($s) { return $s; }
function update_post_meta($id, $k, $v) { $GLOBALS['__meta']["$id:$k"] = $v; $GLOBALS['__meta_writes']++; return true; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['__meta']["$id:$k"] ?? ''; }
function wp_update_post($a, $b = false) { return $a['ID']; }
function get_post($id) { return null; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function current_time($t) { return date('Y-m-d H:i:s'); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function sanitize_text_field($s) { return $s; }
function wp_kses_post($s) { return $s; }
function get_option($k, $d = false) { return $d; }
function home_url($p = '') { return 'https://example.com' . $p; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($c = '', $m = '', $d = '') { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

require_once __DIR__ . '/../includes/class-api.php';

$api = new Superman_Links_API();
$ref = new ReflectionClass($api);
function m($name) { global $ref, $api; $x = $ref->getMethod($name); $x->setAccessible(true); return $x; }

$wrap    = m('wrap_in_elementor_widgets');
$unwrap  = m('unwrap_in_elementor_widgets');
$fields  = m('internal_link_wrappable_fields');
$overlap = m('sentence_overlaps_existing_link');
$writer  = m('write_internal_links_elementor_data');

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail ? "  --  $detail" : '') . "\n"; }
}

function widget($type, $settings, $id = null, $children = null) {
    $w = ['id' => $id ?? substr(md5($type . json_encode($settings)), 0, 8), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings];
    if ($children !== null) $w['elements'] = $children;
    return $w;
}

$TARGET = 'https://target.example.com/page/';
$SENT   = 'We handle spider control across the Fraser Valley.';
$ANCHOR = 'spider control';

echo "\n=== 1. text-editor wrap (regression case) ===\n";
$tree = [['id' => 'sec1', 'elType' => 'section', 'elements' => [widget('text-editor', ['editor' => "<p>$SENT</p>"], 'w-te')]]];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
check('returns array', is_array($r), var_export($r, true));
check('placed_in correct', $r && $r['widget_type'] === 'text-editor' && $r['setting'] === 'editor' && $r['element_id'] === 'w-te', json_encode($r));
$html = $tree[0]['elements'][0]['settings']['editor'];
check('anchor written into editor', strpos($html, '<a href="' . $TARGET . '">spider control</a>') !== false, $html);

echo "\n=== 2. heading wrap (new branch) ===\n";
$tree = [widget('heading', ['title' => $SENT], 'w-h')];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
check('placed_in heading/title', $r && $r['widget_type'] === 'heading' && $r['setting'] === 'title', json_encode($r));
check('anchor written into title', strpos($tree[0]['settings']['title'], '<a href="' . $TARGET . '">') !== false, $tree[0]['settings']['title']);
check('no <p> injected by DOM round-trip', strpos($tree[0]['settings']['title'], '<p>') === false, $tree[0]['settings']['title']);

echo "\n=== 3. NESTED-ANCHOR GUARD: heading with its own link.url is skipped ===\n";
$tree = [widget('heading', ['title' => $SENT, 'link' => ['url' => 'https://elsewhere.example.com/', 'is_external' => '', 'nofollow' => '']], 'w-hl')];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
check('walk returns null (skipped)', $r === null, var_export($r, true));
check('title left byte-identical', $tree[0]['settings']['title'] === $SENT, $tree[0]['settings']['title']);
check('skipped widget not counted as visited', count($visited) === 0, json_encode($visited));

echo "\n=== 4. icon-box description_text ===\n";
$tree = [widget('icon-box', ['title_text' => 'Spiders', 'description_text' => "<p>$SENT</p>"], 'w-ib')];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
check('placed_in icon-box/description_text', $r && $r['widget_type'] === 'icon-box' && $r['setting'] === 'description_text', json_encode($r));
check('icon-box title_text untouched', $tree[0]['settings']['title_text'] === 'Spiders');

echo "\n=== 5. recursion into nested containers ===\n";
$inner = ['elType' => 'container', 'elements' => [
    widget('heading', ['title' => 'Unrelated']),
    widget('text-editor', ['editor' => "<div>$SENT</div>"], 'deep'),
]];
$tree = [['elType' => 'container', 'elements' => [$inner]]];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
check('found at depth 3', $r && $r['element_id'] === 'deep', json_encode($r));

echo "\n=== 6. walk miss on unsupported widget (button) -> null, nothing visited ===\n";
$tree = [widget('button', ['text' => $SENT, 'link' => ['url' => '/contact/']], 'w-btn')];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
check('returns null', $r === null);
check('button text untouched', $tree[0]['settings']['text'] === $SENT);
check('visited empty (not an allowlisted widget)', count($visited) === 0, json_encode($visited));

echo "\n=== 7. ALREADY-LINKED diagnostic survives (the 2a message split) ===\n";
$already = '<p>We handle <a href="https://other.example.com/">spider control</a> across the Fraser Valley.</p>';
$tree = [widget('text-editor', ['editor' => $already], 'w-al')];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
check('walk misses (never nests links)', $r === null, var_export($r, true));
check('visited HTML collected for diagnosis', count($visited) === 1, json_encode($visited));
$ov = $overlap->invoke($api, implode("\n", $visited), $SENT);
check('overlap detector fires -> anchor_already_linked, not the generic 422', $ov === true, var_export($ov, true));

echo "\n=== 8. genuine unsupported-widget miss does NOT report already-linked ===\n";
$tree = [widget('text-editor', ['editor' => '<p>Totally different copy about wasps.</p>'], 'w-x')];
$visited = [];
$r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
$ov = $overlap->invoke($api, implode("\n", $visited), $SENT);
check('walk misses', $r === null);
check('overlap detector stays false -> context_not_found_elementor', $ov === false, var_export($ov, true));

echo "\n=== 9. delete symmetry: wrap then unwrap (heading + icon-box) ===\n";
foreach ([['heading', 'title', $SENT], ['icon-box', 'description_text', "<p>$SENT</p>"], ['text-editor', 'editor', "<p>$SENT</p>"]] as $case) {
    list($wt, $field, $orig) = $case;
    $tree = [widget($wt, [$field => $orig], 'w-' . $wt)];
    $visited = [];
    $r = $wrap->invokeArgs($api, [&$tree, $SENT, $ANCHOR, $TARGET, &$visited]);
    $wrapped_ok = $r !== null && strpos($tree[0]['settings'][$field], $TARGET) !== false;
    $u = $unwrap->invokeArgs($api, [&$tree, $TARGET]);
    $gone = strpos($tree[0]['settings'][$field], $TARGET) === false;
    $text_kept = strpos(strip_tags($tree[0]['settings'][$field]), $ANCHOR) !== false;
    check("$wt: wrap -> unwrap removes the anchor, keeps the text", $wrapped_ok && $u === true && $gone && $text_kept,
        json_encode(['wrapped' => $wrapped_ok, 'unwrap' => $u, 'gone' => $gone, 'text' => $tree[0]['settings'][$field]]));
}

echo "\n=== 10. allowlist shape ===\n";
check('text-editor -> editor', $fields->invoke($api, 'text-editor') === ['editor']);
check('heading -> title', $fields->invoke($api, 'heading') === ['title']);
check('icon-box -> description_text', $fields->invoke($api, 'icon-box') === ['description_text']);
check('image-box -> description_text', $fields->invoke($api, 'image-box') === ['description_text']);
check('button -> [] (unsupported)', $fields->invoke($api, 'button') === []);
check('nested-accordion -> [] (answers reached as child widgets)', $fields->invoke($api, 'nested-accordion') === []);
check('toggle -> [] (no verified dump yet)', $fields->invoke($api, 'toggle') === []);

echo "\n=== 11. WRITE GUARD: wp_json_encode failure must not write (design-wipe guard) ===\n";
$GLOBALS['__meta_writes'] = 0;
$ok = $writer->invoke($api, 999, [widget('heading', ['title' => "valid"])]);
check('valid tree writes', $ok === true && $GLOBALS['__meta_writes'] === 1, "writes={$GLOBALS['__meta_writes']}");
$GLOBALS['__meta_writes'] = 0;
$bad = [widget('heading', ['title' => "bad \xB1\x31 utf8"])];
$ok = $writer->invoke($api, 998, $bad);
check('invalid UTF-8 returns false', $ok === false, var_export($ok, true));
check('invalid UTF-8 writes NOTHING (no empty-string wipe)', $GLOBALS['__meta_writes'] === 0, "writes={$GLOBALS['__meta_writes']}");

echo "\n" . str_repeat('-', 60) . "\n";
echo "PASS: $pass    FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
