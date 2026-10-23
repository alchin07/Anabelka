<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Isolated behavior tests: no database, HTTP service or customer session needed.
require_once __DIR__ . '/../app/Services/VisitorPageViewCounter.php';

final class VisitorCounterStatement extends PDOStatement
{
    public array $rows = [];
    public function execute(?array $params = null): bool
    {
        $this->rows[] = $params;
        return true;
    }
}
final class VisitorCounterDatabase extends PDO
{
    public array $queries = [];
    public VisitorCounterStatement $statement;
    public function __construct() { $this->statement = new VisitorCounterStatement(); }
    public function inTransaction(): bool { return false; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return $this->statement;
    }
}
$passed = 0;
$check = static function (bool $ok, string $name) use (&$passed): void {
    if (!$ok) { throw new RuntimeException($name); }
    $passed++;
    echo "PASS $name\n";
};
$config = ['enabled' => true, 'allowed_hosts' => ['shop.anabelka.com.ua'],
    'base_path' => '/Anabelka', 'timezone' => 'Europe/Kyiv', 'retention_days' => 400];
$request = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'shop.anabelka.com.ua',
    'REQUEST_URI' => '/Anabelka/', 'REMOTE_ADDR' => '8.8.8.8',
    'HTTP_USER_AGENT' => 'Mozilla/5.0', 'HTTP_ACCEPT' => 'text/html'];
$clock = static fn() => new DateTimeImmutable('2026-10-23T12:00:00Z');
$make = static function (?array $settings = null, ?callable $now = null) use ($config, $clock): array {
    $db = new VisitorCounterDatabase();
    $counter = new VisitorPageViewCounter($settings ?? $config, static fn() => $db, $now ?? $clock);
    return [$counter, $db];
};
[$c, $db] = $make();
$check($c->count('home', 0, $request), 'one successful GET records');
$check(!$c->count('home', 0, $request), 'same request cannot record twice');
$check(count($db->statement->rows) === 1, 'one atomic statement execution');
$check($db->statement->rows[0] === ['stat_date'=>'2026-10-23','page_type'=>'home','entity_id'=>0], 'only aggregate key parameters persisted');
$check(str_contains($db->queries[0], 'ON DUPLICATE KEY UPDATE'), 'atomic increment');
$check(!preg_match('/CREATE|ALTER|SET SESSION|SET GLOBAL/i', $db->queries[0]), 'no runtime schema or session changes');
[$c2] = $make();
$check($c2->count('home', 0, $request), 'reload is a fresh request');
foreach ([['category', 42, '/catalog/lingerie/push-up'], ['product', 7, '/product/lace'],
    ['news', 9, '/news/open'], ['news_index', 0, '/news'], ['catalog', 0, '/catalog'],
    ['discounts', 0, '/discounts'], ['new', 0, '/new']] as [$type,$id,$path]) {
    [$c,$db] = $make(); $s=$request; $s['REQUEST_URI']='/Anabelka'.$path.'?lang=uk&sort=price&page=2';
    $check($c->count($type,$id,$s) && $db->statement->rows[0]['entity_id']===$id, "$type actual ID, queries do not split key");
}
foreach ([404,500,503,301,302,304,204,206] as $status) {
    [$c,$db]=$make();
    $check(!$c->count('home',0,$request,false,$status) && !$db->queries,"status $status excluded");
}
foreach (['HEAD','POST','OPTIONS','PUT','DELETE'] as $method) {
    [$c,$db]=$make(); $s=$request; $s['REQUEST_METHOD']=$method;
    $check(!$c->count('home',0,$s) && !$db->queries,"$method excluded");
}
foreach ([['HTTP_HOST','localhost:8000'],['HTTP_HOST','127.0.0.1:8000'],
    ['HTTP_HOST','[::1]:8000'],['HTTP_HOST','test.anabelka.com.ua'],
    ['HTTP_HOST','shop.anabelka.com.ua.evil.org'],['HTTP_HOST','user@shop.anabelka.com.ua'],
    ['REMOTE_ADDR','127.0.0.1'],['REMOTE_ADDR','::1'],['REMOTE_ADDR','::ffff:127.0.0.1'],
    ['HTTP_USER_AGENT','Googlebot/2.1'],['HTTP_USER_AGENT','bingbot'],
    ['HTTP_USER_AGENT','Mozilla/5.0 (compatible; YandexBot)'],['HTTP_USER_AGENT','curl/8.0'],
    ['HTTP_X_REQUESTED_WITH','XMLHttpRequest'],['HTTP_ACCEPT','application/json'],
    ['HTTP_SEC_FETCH_DEST','image'],['HTTP_SEC_FETCH_MODE','cors'],
    ['HTTP_PURPOSE','prefetch'],['HTTP_SEC_PURPOSE','prefetch;prerender'],
    ['HTTP_X_PURPOSE','preview'],['HTTP_X_MOZ','prefetch'],
    ['REQUEST_URI','/Anabelka-chat-test/'],['REQUEST_URI','/Anabelka/admin/visitor-stats'],
    ['REQUEST_URI','/Anabelka/cart'],['REQUEST_URI','/Anabelka/search?q=lace'],
    ['REQUEST_URI','/Anabelka/?builder_preview=home'],['REQUEST_URI','/Anabelka/?token=secret']] as [$key,$value]) {
    [$c,$db]=$make(); $s=$request;$s[$key]=$value;
    $check(!$c->count('home',0,$s) && !$db->queries,"excluded $key=$value");
}
foreach ([['Location: /Anabelka/'],['Content-Type: application/json'],['Content-Type: image/png']] as $headers) {
    [$c,$db]=$make();
    $check(!$c->count('home',0,$request,false,200,$headers) && !$db->queries,'non HTML/redirect response excluded');
}
foreach ([[true,false],[false,true]] as [$admin,$adult]) {
    [$c,$db]=$make();
    $check(!$c->count('home',0,$request,$admin,200,[],$adult) && !$db->queries,'admin/adult excluded before SQL');
}
foreach ([['anything',1],['category',0],['product',-1],['home',99]] as [$type,$id]) {
    [$c,$db]=$make();$check(!$c->count($type,$id,$request) && !$db->queries,'invalid aggregate key excluded');
}
foreach ([['enabled'=>false],['enabled'=>'false'],['allowed_hosts'=>[]],['timezone'=>'invalid'],['base_path'=>''] /* root deployment allowed, but /Anabelka/ is not root */] as $overrides) {
    [$c,$db]=$make(array_replace($config,$overrides));
    $check(!$c->count('home',0,$request) && !$db->queries,'disabled/invalid config fails closed');
}
[$c] = $make(array_replace($config,['allowed_hosts'=>['localhost']]));
$s=$request;$s['HTTP_HOST']='localhost';
$check(!$c->count('home',0,$s),'localhost excluded even in allowlist');
[$c,$db]=$make(null,static fn()=>new DateTimeImmutable('2026-10-23T20:59:59Z'));
$c->count('home',0,$request);$check($db->statement->rows[0]['stat_date']==='2026-10-23','site day before midnight');
[$c,$db]=$make(null,static fn()=>new DateTimeImmutable('2026-10-23T21:00:00Z'));
$c->count('home',0,$request);$check($db->statement->rows[0]['stat_date']==='2026-10-24','site day after midnight');
$c=new VisitorPageViewCounter($config,static function(){throw new RuntimeException('unavailable');},$clock);
$check(!$c->count('home',0,$request),'connection failure swallowed');
$check((require __DIR__.'/../config/visitor-stats.php')['enabled']===false,'development default off');
echo "$passed visitor counter checks passed\n";
