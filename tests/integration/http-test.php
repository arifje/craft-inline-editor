<?php
/**
 * HTTP integration tests for the inline editor against a fixture-loaded Craft install.
 * Usage: php http-test.php http://127.0.0.1:8085
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? '', '/');
if ($base === '') { fwrite(STDERR, "usage: php http-test.php <base-url>\n"); exit(1); }

$pass = 0; $fail = 0; $failures = [];
function ok(bool $cond, string $label, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  ✔ $label\n"; }
    else { $fail++; $failures[] = $label; echo "  ✘ $label" . ($detail !== '' ? " — " . mb_substr($detail, 0, 400) : '') . "\n"; }
}

final class Client {
    private string $jar;
    public function __construct(private string $base, string $name) {
        $this->jar = sys_get_temp_dir() . "/inline-test-$name-" . getmypid() . '.cookies';
        @unlink($this->jar);
    }
    /** @return array{status:int, body:string, headers:string} */
    public function request(string $method, string $path, array $data = [], array $headers = [], bool $json = true): array {
        $ch = curl_init($this->base . $path);
        $h = $json ? ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'] : ['Accept: text/html'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_merge($h, $headers),
            CURLOPT_USERAGENT => 'inline-editor-integration-test/1.0',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $raw = curl_exec($ch);
        if ($raw === false) { throw new RuntimeException('curl: ' . curl_error($ch)); }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }
    public function get(string $path, bool $json = false): array { return $this->request('GET', $path, [], [], $json); }
    public function jarPath(): string { return $this->jar; }
    public function csrf(): string {
        $page = $this->get('/login');
        if (preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $page['body'], $m)) { return html_entity_decode($m[1]); }
        throw new RuntimeException('no CSRF token on /login: HTTP ' . $page['status']);
    }
    public function login(string $user, string $pass): bool {
        $token = $this->csrf();
        $res = $this->request('POST', '/index.php?p=actions/users/login', ['loginName' => $user, 'password' => $pass, 'CRAFT_CSRF_TOKEN' => $token]);
        $body = json_decode($res['body'], true);
        return $res['status'] === 200 && (($body['success'] ?? false) === true || isset($body['returnUrl']));
    }
    public function action(string $action, array $data, ?string $token, array $headers = []): array {
        if ($token !== null) { $data['CRAFT_CSRF_TOKEN'] = $token; }
        return $this->request('POST', '/index.php?p=actions/' . $action, $data, $headers);
    }
}

function pageConfig(string $html): ?array {
    if (preg_match('/window\.InlineEditorConfig = (\{.*?\});/s', $html, $m)) { return json_decode($m[1], true); }
    return null;
}
function ckConfigs(string $html): array {
    $out = [];
    if (preg_match_all('/var data=(\{.*?\});(data\.js=function\(\)\{.*?\n\};)?registry\[("[^"]+")\]=data;/s', $html, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $data = json_decode($m[1], true);
            $data['_hasJsFn'] = ($m[2] ?? '') !== '';
            $out[json_decode($m[3])] = $data;
        }
    }
    return $out;
}
function wrapper(string $html, string $handle): ?string {
    if (preg_match('/<(span|div) ([^>]*data-field="' . preg_quote($handle, '/') . '"[^>]*)>/', $html, $m)) { return $m[2]; }
    return null;
}
function jsonBody(array $res): array { return json_decode($res['body'], true) ?? []; }

echo "== $base ==\n";
$guest = new Client($base, 'guest');
$admin = new Client($base, 'admin');
$writer = new Client($base, 'writer');
$reader = new Client($base, 'reader');
$outsider = new Client($base, 'outsider');

// ── Guest ──────────────────────────────────────────────────────────────────
echo "Guest\n";
$page = $guest->get('/inline-test/alpha');
ok($page['status'] === 200, 'alpha page renders for guests');
ok(!str_contains($page['body'], 'data-inline-editor'), 'no edit wrappers for guests');
ok(!str_contains($page['body'], 'InlineEditorConfig'), 'no editor assets for guests');
ok(str_contains($page['body'], 'canInlineEdit=no'), 'canInlineEdit() is false for guests');
ok(str_contains($page['body'], '<strong>'), 'CKEditor content rendered for guests');
$res = $guest->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineSummary', 'value' => 'hacked'], null);
ok(in_array($res['status'], [400, 401, 403], true), 'guest save is rejected (HTTP ' . $res['status'] . ')', $res['body']);

// ── Admin ──────────────────────────────────────────────────────────────────
echo "Admin\n";
ok($admin->login('admin', 'password123'), 'admin can log in');
$page = $admin->get('/inline-test/alpha');
$html = $page['body'];
ok($page['status'] === 200, 'alpha page renders for admin');
ok(str_contains($html, 'canInlineEdit=yes'), 'canInlineEdit() is true for admin');
$cfg = pageConfig($html);
ok(is_array($cfg) && isset($cfg['ckeditor']['scriptUrl']), 'InlineEditorConfig with ckeditor build registered', json_encode($cfg));
$build = $cfg['ckeditor'] ?? [];
echo "    build: " . json_encode($build) . "\n";
if (!empty($build['scriptUrl'])) {
    $ch = curl_init($build['scriptUrl']); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]); curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $len = (float)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    ok($code === 200, "editor build script reachable (HTTP $code, " . round($len / 1024) . " KB)");
    if (!empty($build['cssUrl'])) {
        $ch = curl_init($build['cssUrl']); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]); curl_exec($ch);
        ok((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200, 'editor build CSS reachable');
    }
}
foreach (['title', 'inlineBody', 'inlineIntro', 'inlineFromFile', 'inlineMissingFile', 'inlineSummary', 'inlineWebsite', 'inlineTopics', 'inlineImage'] as $handle) {
    $w = wrapper($html, $handle);
    ok($w !== null && str_contains($w, 'data-inline-editor'), "wrapper rendered for $handle");
}
$bodyWrapper = wrapper($html, 'inlineBody');
ok($bodyWrapper !== null && preg_match('/data-field-uid="[0-9a-f-]{36}"/', $bodyWrapper), 'CKEditor wrapper carries data-field-uid');
ok($bodyWrapper !== null && str_contains($bodyWrapper, 'data-value="') && str_contains($bodyWrapper, '{entry:2@1:url'), 'data-value holds the raw content with reference tags intact', (string)$bodyWrapper);
ok(str_contains($html, 'href="http://127.0.0.1') || str_contains($html, 'href="' . $base), 'displayed content has the reference tag resolved to a URL');
$ck = ckConfigs($html);
echo "    registered CK configs: " . implode(', ', array_map(fn($c) => $c['handle'] . '(' . $c['source'] . ')', $ck)) . "\n";
$byHandle = [];
foreach ($ck as $uid => $c) { $byHandle[$c['handle']] = $c; }
ok(count($ck) === 4, 'four per-field CKEditor configs registered (one per rendered CKEditor field)');
ok(isset($byHandle['inlineBody']) && $byHandle['inlineBody']['toolbar'] === ['heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'insertTable', 'sourceEditing', 'insertImage'], 'inlineBody toolbar matches the field config (incl. sourceEditing for admins)', json_encode($byHandle['inlineBody'] ?? null));
ok(isset($byHandle['inlineBody']) && $byHandle['inlineBody']['headingLevels'] === [2, 3], 'inlineBody heading levels [2,3]');
ok(isset($byHandle['inlineBody']) && $byHandle['inlineBody']['_hasJsFn'] === true && $byHandle['inlineBody']['options'] === null, 'inlineBody inline JS config emitted as a function');
ok(str_contains($html, "placeholder: 'Full config placeholder'"), 'inline JS body is present verbatim');
ok(isset($byHandle['inlineIntro']) && $byHandle['inlineIntro']['toolbar'] === ['bold', 'italic'] && $byHandle['inlineIntro']['headingLevels'] === false, 'inlineIntro has its own minimal toolbar and no headings');
ok(isset($byHandle['inlineIntro']) && ($byHandle['inlineIntro']['options']['placeholder'] ?? null) === 'Minimal JSON placeholder', 'inlineIntro JSON options preserved');
$isFieldLevel = ($byHandle['inlineBody']['source'] ?? '') === 'field';
if ($isFieldLevel) {
    ok(isset($byHandle['inlineFromFile']) && $byHandle['inlineFromFile']['_hasJsFn'] === true && str_contains($html, "Placeholder from inline-test.js"), 'inlineFromFile config read from config/ckeditor/inline-test.js');
    ok(isset($byHandle['inlineMissingFile']) && $byHandle['inlineMissingFile']['toolbar'] === ['bold'] && $byHandle['inlineMissingFile']['_hasJsFn'] === false, 'inlineMissingFile keeps its toolbar without the missing file');
} else {
    ok(isset($byHandle['inlineFromFile']) && $byHandle['inlineFromFile']['source'] === 'default', 'inlineFromFile (no shared config selected) uses defaults on 3.x/4.x');
}

// nested entries: beta body must be read-only
$beta = $admin->get('/inline-test/beta')['body'];
ok(wrapper($beta, 'inlineBody') === null, 'beta: CKEditor field with nested entries is rendered read-only');
ok(wrapper($beta, 'inlineIntro') !== null, 'beta: other fields remain editable');
preg_match('/data-element-id="(\d+)"/', (string)wrapper($beta, 'inlineIntro'), $bm);
$betaId = (int)($bm[1] ?? 0);
ok($betaId > 0, "beta entry id discovered (#$betaId)");

// saves
$token = $cfg['csrfToken'] ?? null;
ok(is_string($token) && $token !== '', 'CSRF token exposed to the editor');
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineSummary', 'value' => "Updated\nsummary"], $token);
ok($res['status'] === 200 && (jsonBody($res)['value'] ?? null) === "Updated\nsummary", 'admin saves a plain text field', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'title', 'value' => 'Alpha entry (edited)'], $token);
ok($res['status'] === 200 && (jsonBody($res)['value'] ?? null) === 'Alpha entry (edited)', 'admin saves the title', $res['body']);
$newBody = '<h2>Edited heading</h2><p>Edited <strong>body</strong> with <a href="https://example.com/ext" target="_blank" rel="noopener" title="Ext">link</a> and <a href="{entry:2@1:url}">ref</a>.</p>';
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineBody', 'value' => $newBody], $token);
$b = jsonBody($res);
ok($res['status'] === 200 && ($b['success'] ?? false) === true, 'admin saves CKEditor content', $res['body']);
ok(str_contains($b['rawValue'] ?? '', '{entry:2@1:url'), 'rawValue in response keeps the reference tag', $res['body']);
ok(str_contains($b['value'] ?? '', 'inline-test/alpha'), 'value in response is rendered (reference tag resolved)', $res['body']);
ok(str_contains($b['rawValue'] ?? '', 'target="_blank"') && str_contains($b['rawValue'] ?? '', 'rel="noopener"'), 'link attributes survive the field purifier', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineBody', 'value' => '<p>x</p><craft-entry data-entry-id="1"></craft-entry>'], $token);
ok($res['status'] === 400 && str_contains($res['body'], 'Nested entries'), 'submitting nested-entry markup is rejected', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => $betaId, 'field' => 'inlineBody', 'value' => '<p>overwrite</p>'], $token);
ok($res['status'] === 400 && str_contains($res['body'], 'nested entries'), 'saving over content with nested entries is rejected', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineWebsite', 'value' => 'https://example.org/new'], $token);
ok($res['status'] === 200 && (jsonBody($res)['value'] ?? null) === 'https://example.org/new', 'admin saves the URL/Link field', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineImage', 'value' => 'x'], $token);
ok($res['status'] === 400, 'saving a non-text field through save is rejected (HTTP ' . $res['status'] . ')', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'nope', 'value' => 'x'], $token);
ok($res['status'] === 400 && str_contains($res['body'], 'does not exist'), 'unknown field handle is rejected with a clear message', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineSummary', 'value' => 'no csrf'], null);
ok($res['status'] === 400, 'missing CSRF token is rejected (HTTP ' . $res['status'] . ')', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineSummary', 'value' => 'bad csrf'], 'not-a-token');
ok($res['status'] === 400, 'invalid CSRF token is rejected (HTTP ' . $res['status'] . ')', $res['body']);
$res = $admin->request('GET', '/index.php?p=actions/inline-editor/default/search-tags&groupId=1&search=', [], [], true);
ok($res['status'] === 200 && isset(jsonBody($res)['tags']), 'tag search works for admin', $res['body']);
$res = $admin->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineTopics', 'newTags' => ['Alpha topic', 'Beta topic']], $token);
$b = jsonBody($res);
ok($res['status'] === 200 && count($b['tags'] ?? []) === 2, 'admin creates and assigns tags', $res['body']);
$res = $admin->request('GET', '/index.php?p=actions/inline-editor/default/search-tags&groupId=1&search=', [], [], true);
ok(in_array('Alpha topic', array_column(jsonBody($res)['tags'] ?? [], 'title'), true), 'tag listing includes the new tag (search index is queue-driven, so no keyword search here)', $res['body']);
// asset replace + clear (multipart upload)
$png = sys_get_temp_dir() . '/inline-test-' . getmypid() . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$ch = curl_init($base . '/index.php?p=actions/inline-editor/default/replace-asset');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $admin->jarPath(), CURLOPT_COOKIEFILE => $admin->jarPath(), CURLOPT_USERAGENT => 'inline-editor-integration-test/1.0',
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'], CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['elementId' => 2, 'siteId' => 1, 'field' => 'inlineImage', 'CRAFT_CSRF_TOKEN' => $token, 'file' => new CURLFile($png, 'image/png', 'inline-test.png')]]);
$raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$up = json_decode((string)$raw, true) ?? [];
ok($status === 200 && ($up['success'] ?? false) === true && !empty($up['id']) && str_contains((string)($up['url'] ?? ''), '/uploads/inline/alpha/'), 'admin uploads an image into the restricted upload location (inline/{slug})', (string)$raw);
$assetId = (int)($up['id'] ?? 0);
$page = $admin->get('/inline-test/alpha')['body'];
ok($assetId > 0 && str_contains($page, 'data-asset-ids="[' . $assetId . ']"'), 'uploaded asset is now the field value', (string)wrapper($page, 'inlineImage'));
$res = $admin->action('inline-editor/default/replace-asset', ['elementId' => 2, 'field' => 'inlineImage', 'clear' => 1, 'removeAssetId' => 999999], $token);
ok($res['status'] === 400, 'clearing an asset that is not in the field is rejected (HTTP ' . $res['status'] . ')', $res['body']);
$res = $admin->action('inline-editor/default/replace-asset', ['elementId' => 2, 'field' => 'inlineImage', 'clear' => 1, 'removeAssetId' => $assetId], $token);
ok($res['status'] === 200 && (jsonBody($res)['success'] ?? false) === true, 'admin removes the uploaded asset', $res['body']);
$page = $admin->get('/inline-test/alpha')['body'];
ok(str_contains($page, 'data-asset-ids="[]"'), 'field is empty again after removal');
@unlink($png);

// page reflects saves
$page = $admin->get('/inline-test/alpha')['body'];
ok(str_contains($page, 'Alpha entry (edited)') && str_contains($page, 'Edited heading') && str_contains($page, 'https://example.org/new'), 'page reflects the saved values');

// ── Writer (allowed group, section permissions) ────────────────────────────
echo "Writer\n";
ok($writer->login('writer', 'password123'), 'writer can log in');
$page = $writer->get('/inline-test/alpha')['body'];
ok(str_contains($page, 'canInlineEdit=yes') && wrapper($page, 'inlineSummary') !== null, 'writer sees edit wrappers');
$wck = ckConfigs($page); $wByHandle = []; foreach ($wck as $c) { $wByHandle[$c['handle']] = $c; }
ok(isset($wByHandle['inlineBody']) && !in_array('sourceEditing', $wByHandle['inlineBody']['toolbar'], true), 'sourceEditing is hidden from non-admins (field allows admins only)', json_encode($wByHandle['inlineBody'] ?? null));
$wtoken = pageConfig($page)['csrfToken'] ?? null;
$res = $writer->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineSummary', 'value' => 'writer edit'], $wtoken);
ok($res['status'] === 200, 'writer with section permission can save', $res['body']);

// ── Reader (allowed group, no section permissions) ─────────────────────────
echo "Reader\n";
ok($reader->login('reader', 'password123'), 'reader can log in');
$page = $reader->get('/inline-test/alpha')['body'];
ok(str_contains($page, 'canInlineEdit=yes'), 'reader is allowed to use the editor UI (group setting)');
$rtoken = pageConfig($page)['csrfToken'] ?? null;
$res = $reader->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineSummary', 'value' => 'reader edit'], $rtoken);
ok($res['status'] === 403, 'reader without section permission gets 403 on save (HTTP ' . $res['status'] . ')', $res['body']);
$res = $reader->action('inline-editor/default/replace-asset', ['elementId' => 2, 'field' => 'inlineImage', 'clear' => 1, 'removeAssetId' => 1], $rtoken);
ok($res['status'] === 403, 'reader gets 403 on replace-asset (HTTP ' . $res['status'] . ')', $res['body']);

// ── Outsider (logged in, not in an allowed group) ──────────────────────────
echo "Outsider\n";
ok($outsider->login('outsider', 'password123'), 'outsider can log in');
$page = $outsider->get('/inline-test/alpha')['body'];
ok(str_contains($page, 'canInlineEdit=no') && !str_contains($page, 'data-inline-editor'), 'outsider sees no edit wrappers');
$otoken = $outsider->csrf();
$res = $outsider->action('inline-editor/default/save', ['elementId' => 2, 'field' => 'inlineSummary', 'value' => 'outsider edit'], $otoken);
ok($res['status'] === 403, 'outsider gets 403 on save (HTTP ' . $res['status'] . ')', $res['body']);
$res = $outsider->request('GET', '/index.php?p=actions/inline-editor/default/search-tags&groupId=1&search=', [], [], true);
ok($res['status'] === 403, 'outsider gets 403 on tag search (HTTP ' . $res['status'] . ')', $res['body']);

// verify the reader/outsider edits did not land
$page = $guest->get('/inline-test/alpha')['body'];
ok(str_contains($page, 'writer edit') && !str_contains($page, 'reader edit') && !str_contains($page, 'outsider edit'), 'only authorised edits were persisted');

echo "\n$pass passed, $fail failed" . ($fail ? ': ' . implode(' | ', $failures) : '') . "\n";
exit($fail ? 1 : 0);
