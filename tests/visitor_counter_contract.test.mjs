import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const read = p => fs.readFileSync(new URL('../'+p, import.meta.url),'utf8');

test('only allowed public controllers record views after a rendered view', () => {
  for (const [file, count] of [['Home',1],['Catalog',2],['Product',1],['News',2],['StorefrontPage',2]]) {
    const source=read(`app/Controllers/${file}Controller.php`);
    assert.equal((source.match(/\$this->recordPageView\(/g)||[]).length,count,file);
    assert.ok(source.indexOf('$this->recordPageView(')>source.indexOf('$this->view('),file);
  }
  assert.match(read('app/Controllers/CatalogController.php'),/recordPageView\('category',\s*\(int\) \$category\['id'\],\s*!empty\(\$category\['effective_adult'\]\)/);
  assert.match(read('app/Controllers/ProductController.php'),/recordPageView\('product',\s*\(int\) \$product\['id'\],\s*!empty\(\$productCategory\['effective_adult'\]\)/);
  assert.match(read('app/Core/Controller.php'),/VisitorPageViewCounter::recordRendered/);
  assert.match(read('app/Core/App.php'),/Services\/VisitorPageViewCounter.php/);
});
test('permission is enforced server side and service uses existing builder registry',()=>{
  assert.match(read('app/Models/AdminAccess.php'),/'\/admin\/visitor-stats'\s*=>\s*\['visitor_stats.view', 'visitor_stats.view'\]/);
  assert.match(read('app/Models/AdminAccess.php'),/\['administrators.manage', 'dashboard.manage', 'visitor_stats.view'\]/);
  assert.match(read('app/Models/AdminDashboardServiceRegistry.php'),/'visitor_stats'\s*=>\s*\{?[\s\S]*?'permission' => 'visitor_stats.view'/);
  assert.match(read('routes/AdminSecurity.php'),/AdminVisitorStatsController@index/);
  assert.match(read('views/admin/partials/header.php'),/Статистика відвідувань/);
});
test('aggregate schema and service contain no personal-data persistence',()=>{
  const schema=read('database/migrations/2026-10-23_visitor_counter.sql');
  assert.match(schema,/CREATE TABLE IF NOT EXISTS site_page_view_daily/i);
  assert.match(schema,/PRIMARY KEY\s*\(\s*stat_date,\s*page_type,\s*entity_id\s*\)/i);
  assert.doesNotMatch(schema,/\b(?:ip_address|user_agent|user_id|visitor_id|url|referrer|session_id|cookie_id)\b/i);
  const counter=read('app/Services/VisitorPageViewCounter.php');
  assert.doesNotMatch(counter,/setcookie\s*\(|session_start\s*\(|curl_|file_get_contents\s*\(\s*['"]https?:/i);
  assert.match(counter,/AdminAccess::currentId\(\)/);
  assert.doesNotMatch(counter,/AdminAccess::current\(\)/);
});
