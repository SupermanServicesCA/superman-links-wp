<?php
/**
 * End-to-end harness for insert_link_elementor / delete path (v2.3.0 §2).
 * The assertion that matters: on an Elementor page with a valid non-empty
 * tree, post_content is NEVER written — every miss returns a 422 instead.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__meta'] = [];
$GLOBALS['__meta_writes'] = 0;
$GLOBALS['__post_content_writes'] = 0;
$GLOBALS['__post_content'] = '';

function add_action() {}
function add_filter() {}
function __($s, $d = null) { return $s; }
function esc_url($s) { return $s; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function wp_json_encode($d) { return json_encode($d); }
function wp_slash($s) { return $s; }
function update_post_meta($id, $k, $v) { $GLOBALS['__meta']["$id:$k"] = $v; $GLOBALS['__meta_writes']++; return true; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['__meta']["$id:$k"] ?? ''; }
function wp_update_post($a, $b = false) {
    if (array_key_exists('post_content', $a)) { $GLOBALS['__post_content_writes']++; $GLOBALS['__post_content'] = $a['post_content']; }
    return $a['ID'];
}
function get_post($id) { $o = new stdClass(); $o->ID = $id; $o->post_content = $GLOBALS['__post_content']; $o->post_status = 'publish'; return $o; }
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
}

require_once __DIR__ . '/../includes/class-api.php';

$api = new Superman_Links_API();
$ref = new ReflectionClass($api);
$insert = $ref->getMethod('insert_link_elementor'); $insert->setAccessible(true);

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail ? "  --  $detail" : '') . "\n"; }
}

$TARGET = 'https://target.example.com/page/';
$SENT   = 'We handle spider control across the Fraser Valley.';
$ANCHOR = 'spider control';
$POST   = 42;

// The dead-copy scenario: post_content DOES contain the sentence. Pre-2.3.0
// this is exactly what made the wrap "succeed" invisibly.
function reset_world($elementor_data, $sentence) {
    $GLOBALS['__meta'] = ["42:_elementor_data" => $elementor_data];
    $GLOBALS['__meta_writes'] = 0;
    $GLOBALS['__post_content_writes'] = 0;
    $GLOBALS['__post_content'] = "<p>$sentence</p>";
}

function tree_json($widgets) { return json_encode($widgets); }
function w($type, $settings, $id = 'x1') {
    return ['id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings];
}

echo "\n=== A. THE PHANTOM REGRESSION: sentence only in an unsupported widget ===\n";
echo "    (post_content HAS a dead copy — pre-2.3.0 this returned success + wrote it)\n";
reset_world(tree_json([w('button', ['text' => $SENT])]), $SENT);
$r = $insert->invoke($api, $POST, $TARGET, $ANCHOR, $SENT);
check('returns WP_Error', $r instanceof WP_Error, is_array($r) ? json_encode($r) : gettype($r));
check('code = context_not_found_elementor', $r instanceof WP_Error && $r->code === 'context_not_found_elementor', $r instanceof WP_Error ? $r->code : '');
check('HTTP 422', $r instanceof WP_Error && $r->data['status'] === 422);
check('*** post_content NOT written ***', $GLOBALS['__post_content_writes'] === 0, "writes={$GLOBALS['__post_content_writes']}");
check('_elementor_data NOT written', $GLOBALS['__meta_writes'] === 0, "writes={$GLOBALS['__meta_writes']}");

echo "\n=== B. Corrupted _elementor_data (S211d state) ===\n";
reset_world('{"broken": [not json', $SENT);
$r = $insert->invoke($api, $POST, $TARGET, $ANCHOR, $SENT);
check('code = elementor_data_corrupt', $r instanceof WP_Error && $r->code === 'elementor_data_corrupt', $r instanceof WP_Error ? $r->code : gettype($r));
check('HTTP 422', $r instanceof WP_Error && $r->data['status'] === 422);
check('*** post_content NOT written ***', $GLOBALS['__post_content_writes'] === 0, "writes={$GLOBALS['__post_content_writes']}");

echo "\n=== C. Append path, no text-editor widget (UNCONDITIONAL phantom pre-2.3.0) ===\n";
reset_world(tree_json([w('heading', ['title' => 'Just a heading']), w('image', [])]), $SENT);
$r = $insert->invoke($api, $POST, $TARGET, $ANCHOR, null);   // no match_context
check('code = no_text_widget_for_append', $r instanceof WP_Error && $r->code === 'no_text_widget_for_append', $r instanceof WP_Error ? $r->code : gettype($r));
check('HTTP 422', $r instanceof WP_Error && $r->data['status'] === 422);
check('*** post_content NOT written (no "Related:" append) ***', $GLOBALS['__post_content_writes'] === 0, "wrote: {$GLOBALS['__post_content']}");

echo "\n=== D. Already-linked reports the precise cause, not the generic widget 422 ===\n";
$already = '<p>We handle <a href="https://other.example.com/">spider control</a> across the Fraser Valley.</p>';
reset_world(tree_json([w('text-editor', ['editor' => $already])]), $SENT);
$r = $insert->invoke($api, $POST, $TARGET, $ANCHOR, $SENT);
check('code = anchor_already_linked', $r instanceof WP_Error && $r->code === 'anchor_already_linked', $r instanceof WP_Error ? $r->code : gettype($r));
check('post_content NOT written', $GLOBALS['__post_content_writes'] === 0);

echo "\n=== E. Happy path still works: wrap in a heading, placed_in returned ===\n";
reset_world(tree_json([w('heading', ['title' => $SENT], 'h-9')]), $SENT);
$r = $insert->invoke($api, $POST, $TARGET, $ANCHOR, $SENT);
check('returns array with mode=wrap', is_array($r) && $r['mode'] === 'wrap', json_encode($r));
check('placed_in = heading/title/h-9', is_array($r) && $r['placed_in']['widget_type'] === 'heading' && $r['placed_in']['setting'] === 'title' && $r['placed_in']['element_id'] === 'h-9', json_encode($r['placed_in'] ?? null));
check('_elementor_data written exactly once', $GLOBALS['__meta_writes'] === 1, "writes={$GLOBALS['__meta_writes']}");
check('post_content NOT written', $GLOBALS['__post_content_writes'] === 0);
$saved = json_decode($GLOBALS['__meta']['42:_elementor_data'], true);
check('anchor present in saved tree', strpos($saved[0]['settings']['title'], $TARGET) !== false, $saved[0]['settings']['title']);

echo "\n=== F. Happy path: append into an existing text-editor still works ===\n";
reset_world(tree_json([w('text-editor', ['editor' => '<p>Body copy.</p>'])]), $SENT);
$r = $insert->invoke($api, $POST, $TARGET, $ANCHOR, null);
check('mode=append', is_array($r) && $r['mode'] === 'append', json_encode($r));
check('_elementor_data written', $GLOBALS['__meta_writes'] === 1);
check('post_content NOT written', $GLOBALS['__post_content_writes'] === 0);

echo "\n=== G. LEGITIMATE post_content case preserved: no Elementor tree at all ===\n";
echo "    (classic content under a Theme Builder single template)\n";
reset_world('', $SENT);
$r = $insert->invoke($api, $POST, $TARGET, $ANCHOR, $SENT);
check('succeeds via standard path', is_array($r) && $r['mode'] === 'wrap', is_array($r) ? json_encode($r) : ($r instanceof WP_Error ? $r->code : gettype($r)));
check('post_content IS written here (correct)', $GLOBALS['__post_content_writes'] === 1, "writes={$GLOBALS['__post_content_writes']}");
check('anchor landed in post_content', strpos($GLOBALS['__post_content'], $TARGET) !== false, $GLOBALS['__post_content']);

echo "\n" . str_repeat('-', 60) . "\n";
echo "PASS: $pass    FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
