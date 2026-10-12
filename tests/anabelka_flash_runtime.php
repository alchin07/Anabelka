<?php

require_once __DIR__ . '/../app/Core/NotificationBootstrap.php';

function flashExpect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function flashPage($content = '')
{
    return '<!doctype html><html><head><title>Test</title></head><body>'
        . $content . '</body></html>';
}

function flashPayload($html)
{
    flashExpect(
        preg_match('/<script\b[^>]*id="anabelka-flash"[^>]*>(.*?)<\/script>/s', $html, $match) === 1,
        'A nonempty PHP flash queue must be emitted as JSON in the page body.'
    );
    $payload = json_decode($match[1], true);
    flashExpect(is_array($payload), 'The flash bridge must contain valid JSON.');
    return $payload;
}

function flashSubprocess($directory, $id, $action)
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'session.use_cookies=0', __FILE__, '--session-probe', $directory, $id, $action],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    flashExpect(is_resource($process), 'The session persistence probe must start.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    flashExpect($status === 0 && $errors === '', 'Session probe failed: ' . $errors);
    return $output;
}

if (($argv[1] ?? '') === '--session-probe') {
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    session_save_path($argv[2]);
    session_id($argv[3]);
    NotificationBootstrap::register();
    if ($argv[4] === 'store') {
        flashExpect(class_exists('AnabelkaFlash'), 'NotificationBootstrap must load the flash API.');
        flashExpect(AnabelkaFlash::success('Після перенаправлення.'), 'Flash must start the session before storing.');
        echo '{"redirect":true}';
    } else {
        session_start();
        echo flashPage();
    }
    exit;
}

$tests = [];
$tests['nomodule classics do not suppress modern executable resources'] = function () {
    foreach (['nomodule', 'nomodule="false"'] as $attribute) {
        $html = '<html><head><script ' . $attribute . ' src="/Anabelka/js/anabelka-notify.js?v=old"></script></head><body></body></html>';
        $result = NotificationBootstrap::inject($html);
        flashExpect(strpos($result, '<script src="/Anabelka/js/anabelka-notify.js?v=3"></script>') !== false, 'Modern browsers need an executable script in addition to nomodule.');
    }
    $module = '<html><head><script type="module" nomodule src="/Anabelka/js/anabelka-notify.js?v=old"></script></head><body></body></html>';
    flashExpect(substr_count(NotificationBootstrap::inject($module), 'anabelka-notify.js') === 1, 'Nomodule has no effect on module scripts.');
};
$tests['disabled stylesheets do not suppress active notification styles'] = function () {
    foreach (['disabled', 'disabled="false"'] as $attribute) {
        $html = '<html><head><link rel="stylesheet" ' . $attribute . ' href="/Anabelka/css/anabelka-notify.css?v=old"></head><body></body></html>';
        flashExpect(strpos(NotificationBootstrap::inject($html), '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=3">') !== false, 'A disabled stylesheet cannot style live notifications.');
    }
};
$tests['iframe and legacy raw text cannot suppress live resources or the host'] = function () {
    foreach (['iframe', 'noembed', 'xmp'] as $tag) {
        $html = flashPage('<' . $tag . '><div id="site-message"></div><script src="/Anabelka/js/anabelka-notify.js?v=old"></script><link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=old"></' . $tag . '>');
        $result = NotificationBootstrap::inject($html);
        $head = substr($result, 0, strpos($result, '</head>'));
        flashExpect(strpos($head, 'anabelka-notify.js?v=3') !== false && strpos($head, 'anabelka-notify.css?v=3') !== false, 'Raw fallback contents are not connected resources.');
        flashExpect(strpos($result, '<div id="site-message" class="site-message anabelka-notify"') !== false, 'A live host is required outside raw fallback contents.');
    }
};
$tests['assets are detected from actual tags independently of cache version'] = function () {
    $html = '<html><head><LINK REL=stylesheet HREF=/Anabelka/css/anabelka-notify.css?v=1&amp;x=2>'
        . '</head><body><SCRIPT SRC=\'/Anabelka/js/anabelka-notify.js?v=42#cache\'></SCRIPT>'
        . '<script src="/Anabelka/js/admin-flash-message.js?v=2"></script></body></html>';
    $result = NotificationBootstrap::inject($html);
    flashExpect(substr_count($result, '/Anabelka/js/anabelka-notify.js') === 1, 'Existing JS must not be injected a second time because its version differs.');
    flashExpect(substr_count($result, '/Anabelka/css/anabelka-notify.css') === 1, 'Existing CSS must not be injected a second time because its version differs.');
    flashExpect(strpos($result, 'anabelka-notify.js') < strpos($result, 'admin-flash-message.js'), 'Existing shared-module/wrapper ordering must be retained.');
};
$tests['mentions in comments attributes and script text cannot suppress required assets'] = function () {
    $html = flashPage('<!-- <script src="/Anabelka/js/anabelka-notify.js?v=3"></script> -->'
        . '<script>const sample = \"<link rel=stylesheet href=/Anabelka/css/anabelka-notify.css?v=3>\";</script>'
        . '<div data-src="/Anabelka/js/anabelka-notify.js?v=3"></div>');
    $result = NotificationBootstrap::inject($html);
    $head = substr($result, 0, strpos($result, '</head>'));
    flashExpect(strpos($head, '<script src="/Anabelka/js/anabelka-notify.js?v=3"></script>') !== false, 'A JS URL mentioned outside an actual src must not suppress loading.');
    flashExpect(strpos($head, '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=3">') !== false, 'CSS mentioned inside script text must not suppress loading.');
};
$tests['absolute asset URLs are recognized and unrelated resources preserved'] = function () {
    $html = '<html><head><link rel="stylesheet" href="https://example.test/Anabelka/css/anabelka-notify.css?v=77">'
        . '<script src="https://example.test/Anabelka/js/anabelka-notify.js?v=77"></script>'
        . '<script src="/Anabelka/js/admin-flash-message.js?v=2"></script></head><body></body></html>';
    $result = NotificationBootstrap::inject($html);
    flashExpect(substr_count($result, 'anabelka-notify.js') === 1 && substr_count($result, 'anabelka-notify.css') === 1, 'Asset path detection must work with an absolute URL.');
    flashExpect(strpos($result, 'admin-flash-message.js?v=2') !== false, 'Legacy compatibility resources must remain present.');
};
$tests['an existing actual message host is retained and repeated injection is stable'] = function () {
    $html = flashPage('<div class="custom-host" id=site-message></div>');
    $result = NotificationBootstrap::inject($html);
    flashExpect(substr_count($result, 'site-message') === 1, 'An unquoted existing message-host ID must not create another host.');
    flashExpect(NotificationBootstrap::inject($result) === $result, 'Reprocessing the HTML must not duplicate notification resources or the host.');
};
$tests['nested template descendants cannot suppress live resources host or flash bridge'] = function () {
    $_SESSION = [];
    AnabelkaFlash::info('Живе повідомлення.');
    $html = flashPage('<template><template><script src="/Anabelka/js/anabelka-notify.js?v=1"></script></template>'
        . '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=1">'
        . '<div id="site-message"></div><script type="application/json" id="anabelka-flash">[]</script></template>');
    $result = NotificationBootstrap::inject($html);
    $head = substr($result, 0, strpos($result, '</head>'));
    flashExpect(strpos($head, '<script src="/Anabelka/js/anabelka-notify.js?v=3"></script>') !== false, 'An inert template script must not suppress live JS.');
    flashExpect(strpos($head, '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=3">') !== false, 'An inert template stylesheet must not suppress live CSS.');
    flashExpect(strpos($result, '<body>' . "\n" . '<div id="site-message"') !== false, 'A template host must not suppress the live message container.');
    flashExpect(flashPayload($result)[0]['message'] === 'Живе повідомлення.', 'A template JSON block must not suppress the live flash bridge.');
};
$tests['live resources following nested templates remain recognized'] = function () {
    $html = flashPage('<template><script>const sample = "</template>";</script>'
        . '<template><div>Nested</div></template></template>'
        . '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=1">'
        . '<script type="module" src="/Anabelka/js/anabelka-notify.js?v=1"></script><div id="site-message"></div>');
    $result = NotificationBootstrap::inject($html);
    flashExpect(substr_count($result, 'anabelka-notify.js') === 1, 'The live script after a nested template must be recognized.');
    flashExpect(substr_count($result, 'anabelka-notify.css') === 1, 'The live stylesheet after a nested template must be recognized.');
    flashExpect(substr_count($result, 'site-message') === 1, 'The live host after a nested template must be recognized.');
};
$tests['noscript content cannot suppress resources or the host when scripting is enabled'] = function () {
    $_SESSION = [];
    $html = flashPage('<noscript><script src="/Anabelka/js/anabelka-notify.js?v=1"></script>'
        . '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=1"><div id="site-message"></div></noscript>');
    $result = NotificationBootstrap::inject($html);
    $head = substr($result, 0, strpos($result, '</head>'));
    flashExpect(strpos($head, 'anabelka-notify.js?v=3') !== false, 'A noscript script must not suppress live JS.');
    flashExpect(strpos($head, 'anabelka-notify.css?v=3') !== false, 'A noscript stylesheet must not suppress live CSS.');
    flashExpect(strpos($result, '<body>' . "\n" . '<div id="site-message"') !== false, 'A noscript host must not suppress the live container.');
};
$tests['nonexecuting script data types cannot suppress shared JavaScript'] = function () {
    foreach (['application/json', 'text/plain', 'importmap', 'speculationrules', 'text/javascript; charset=utf-8', ' module '] as $type) {
        $html = flashPage('<script type="' . $type . '" src="/Anabelka/js/anabelka-notify.js?v=1"></script>');
        $result = NotificationBootstrap::inject($html);
        $head = substr($result, 0, strpos($result, '</head>'));
        flashExpect(strpos($head, 'anabelka-notify.js?v=3') !== false, 'An inert script type must not suppress live JS: ' . $type);
    }
};
$tests['executing classic and module script types retain existing shared JavaScript'] = function () {
    foreach ([null, '', 'text/javascript', 'application/javascript', 'TeXt/JaVaScRiPt', 'text/javascript1.5', "\ttext/javascript \n", 'MODULE'] as $type) {
        $attribute = $type === null ? '' : ' type="' . $type . '"';
        $html = flashPage('<script' . $attribute . ' src="/Anabelka/js/anabelka-notify.js?v=1"></script>');
        $result = NotificationBootstrap::inject($html);
        flashExpect(substr_count($result, 'anabelka-notify.js') === 1, 'An executing script type must prevent duplicate JS: ' . ($type ?? '(missing)'));
    }
};
$tests['flash API queues four severities with deduplication and consumes once'] = function () {
    flashExpect(class_exists('AnabelkaFlash'), 'NotificationBootstrap must load AnabelkaFlash.');
    $_SESSION = ['admin_review_flash' => ['type' => 'success', 'message' => 'Legacy.']];
    flashExpect(AnabelkaFlash::success(' Збережено. ', ['duration' => 1500, 'html' => true]), 'A success flash must queue.');
    flashExpect(!AnabelkaFlash::success('Збережено.', ['duration' => 9999]), 'The same type and message must be deduplicated.');
    AnabelkaFlash::error('Помилка.');
    AnabelkaFlash::warning('Увага.');
    AnabelkaFlash::info('До відома.');
    flashExpect(AnabelkaFlash::consume() === [
        ['type' => 'success', 'message' => 'Збережено.', 'options' => ['duration' => 1500]],
        ['type' => 'error', 'message' => 'Помилка.', 'options' => []],
        ['type' => 'warning', 'message' => 'Увага.', 'options' => []],
        ['type' => 'info', 'message' => 'До відома.', 'options' => []],
    ], 'The flash queue must retain order, safe options, and all severities.');
    flashExpect(AnabelkaFlash::consume() === [], 'Flash must be consumed once.');
    flashExpect(isset($_SESSION['admin_review_flash']), 'Legacy flash keys must remain untouched.');
};
$tests['flash input validation rejects blank or structured text and repairs malformed queue'] = function () {
    $_SESSION = ['anabelka_flash_queue' => [null, ['type' => 'success', 'message' => []], ['type' => 'warning', 'message' => 'Увага.', 'options' => ['duration' => -1]], ['type' => 'warning', 'message' => 'Увага.']]];
    flashExpect(!AnabelkaFlash::store('success', '  '), 'Blank text must not queue.');
    flashExpect(!AnabelkaFlash::error(['private' => 'data']), 'Structured values must not be cast into user text.');
    AnabelkaFlash::store('unknown', 'Текст.', ['duration' => INF]);
    flashExpect(AnabelkaFlash::consume() === [
        ['type' => 'warning', 'message' => 'Увага.', 'options' => []],
        ['type' => 'info', 'message' => 'Текст.', 'options' => []],
    ], 'Malformed queue entries and invalid duration must be ignored safely.');
};
$tests['JSON bridge safely preserves user text and consumes on an HTML document only'] = function () {
    $_SESSION = [];
    $text = '</script><script>alert("x")</script> & \' <b>Текст</b>';
    AnabelkaFlash::success($text);
    flashExpect(NotificationBootstrap::inject('{"ok":true}') === '{"ok":true}', 'JSON responses must not be rewritten.');
    flashExpect(NotificationBootstrap::inject('<div>Fragment</div>') === '<div>Fragment</div>', 'HTML fragments must not be rewritten.');
    $result = NotificationBootstrap::inject(flashPage());
    flashExpect(flashPayload($result) === [['type' => 'success', 'message' => $text, 'options' => []]], 'Safe JSON must preserve the user text exactly.');
    flashExpect(strpos($result, '\\u003C\\/script\\u003E') !== false, 'Script-closing text must be JSON hex escaped.');
    flashExpect(strpos($result, $text) === false, 'User text must never be inserted as executable markup.');
    flashExpect(strpos($result, '<body>') < strpos($result, 'id="anabelka-flash"'), 'Flash JSON must be placed in the body.');
    flashExpect(strpos(NotificationBootstrap::inject(flashPage()), 'id="anabelka-flash"') === false, 'The next document must not repeat consumed PHP flash.');
};
$tests['invalid UTF-8 cannot prevent a queued message from being consumed'] = function () {
    $_SESSION = [];
    AnabelkaFlash::info("Safe\xB1 text");
    $payload = flashPayload(NotificationBootstrap::inject(flashPage()));
    flashExpect($payload[0]['message'] === "Safe\xEF\xBF\xBD text", 'Invalid UTF-8 must become replacement text in valid JSON.');
    flashExpect(AnabelkaFlash::consume() === [], 'An invalid byte sequence must not leave a repeating flash.');
};
$tests['an existing JSON bridge retains pending flash for a subsequent document'] = function () {
    $_SESSION = [];
    AnabelkaFlash::info('Пізніше.');
    $result = NotificationBootstrap::inject(flashPage('<script type="application/json" id="anabelka-flash">[]</script>'));
    flashExpect(substr_count($result, 'id="anabelka-flash"') === 1, 'A document may contain only one PHP flash bridge.');
    flashExpect(AnabelkaFlash::consume() === [['type' => 'info', 'message' => 'Пізніше.', 'options' => []]], 'A message must not be lost when an existing bridge prevents injection.');
};
$tests['redirect response retains flash and rendered shutdown persists consumption'] = function () use (&$sessionDirectory) {
    $id = 'flash-probe-' . bin2hex(random_bytes(8));
    flashExpect(flashSubprocess($sessionDirectory, $id, 'store') === '{"redirect":true}', 'A non-HTML redirect response must remain untouched.');
    $firstPage = flashSubprocess($sessionDirectory, $id, 'page');
    flashExpect(flashPayload($firstPage)[0]['message'] === 'Після перенаправлення.', 'Flash must survive the redirect request.');
    $secondPage = flashSubprocess($sessionDirectory, $id, 'page');
    flashExpect(strpos($secondPage, 'id="anabelka-flash"') === false, 'Consumption in the output-buffer shutdown callback must persist to the next request.');
};

$sessionDirectory = sys_get_temp_dir() . '/anabelka-flash-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($sessionDirectory);
session_id('anabelka-flash-test');
session_start();
$failures = [];
foreach ($tests as $name => $test) {
    try {
        $test();
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
    }
}
session_write_close();
foreach (glob($sessionDirectory . '/*') as $file) {
    unlink($file);
}
rmdir($sessionDirectory);
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'anabelka flash runtime passed (' . count($tests) . " checks)\n";
