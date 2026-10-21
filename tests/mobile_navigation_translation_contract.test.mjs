import assert from 'node:assert/strict';
import fs from 'node:fs';

const baseServicePath = 'app/Services/TranslationDashboardService.php';
const servicePath = 'app/Services/MobileNavigationTranslationDashboardService.php';
const translatorPath = 'app/Models/MobileNavigationTranslator.php';
const controllerPath = 'app/Controllers/AdminMobileNavigationTranslationController.php';
const missingViewPath = 'views/admin/translations/missing.php';
const routesPath = 'routes/Web.php';

for (const file of [
    baseServicePath,
    servicePath,
    translatorPath,
    controllerPath,
    missingViewPath,
    routesPath
]) {
    assert.ok(fs.existsSync(file), `${file} must exist`);
}

const service = fs.readFileSync(servicePath, 'utf8');
const translator = fs.readFileSync(translatorPath, 'utf8');
const controller = fs.readFileSync(controllerPath, 'utf8');
const missingView = fs.readFileSync(missingViewPath, 'utf8');
const routes = fs.readFileSync(routesPath, 'utf8');

assert.match(
    service,
    /extends\s+TranslationDashboardService/,
    'mobile navigation translation dashboard must preserve the existing dashboard'
);
assert.match(
    service,
    /SECTION\s*=\s*['"]mobile_navigation['"]/,
    'translation dashboard must register the mobile_navigation section'
);
assert.match(
    service,
    /missingMobileNavigation\s*\(/,
    'translation dashboard must expose missing mobile-menu translations'
);
assert.match(
    service,
    /mobile_navigation_item_translations/i,
    'translation dashboard must read mobile-menu translations'
);
assert.match(
    service,
    /mobile_navigation_items/i,
    'translation dashboard must count mobile-menu source items'
);
assert.match(
    service,
    /\/Anabelka\/admin\/mobile-navigation/,
    'missing mobile-menu translations must link back to the mobile-menu editor'
);
assert.match(
    service,
    /mobile_navigation_item/i,
    'missing translation rows must identify mobile-navigation entities'
);
assert.match(
    service,
    /unavailable/,
    'missing migration must be represented as unavailable instead of 100% complete'
);

assert.match(translator, /status\s*=\s*['"]outdated['"]|STATUS_OUTDATED/i);
assert.match(translator, /Language::SOURCE_CODE/);
assert.match(translator, /mobile_navigation_item_translations/i);
assert.match(
    translator,
    /status\s+IN\s*\(\s*['"]approved['"]\s*,\s*['"]outdated['"]\s*\)/i,
    'public localization must allow approved/outdated nonblank translations with UA fallback'
);

assert.match(
    controller,
    /MobileNavigationTranslationDashboardService/,
    'translation routes must use the mobile-navigation-aware dashboard service'
);
assert.match(
    controller,
    /language|filters/,
    'translation navigation must preserve a requested language filter'
);
assert.match(
    routes,
    /\/admin\/translations['"],\s*\n\s*['"]AdminMobileNavigationTranslationController@index['"]/,
    'translation dashboard route must use the integrated controller'
);
assert.match(
    routes,
    /\/admin\/translations\/missing['"],\s*\n\s*['"]AdminMobileNavigationTranslationController@missing['"]/,
    'missing translations route must use the integrated controller'
);
assert.match(
    missingView,
    /missing_languages|language_states/,
    'missing-translations view must expose language state for each item'
);

process.stdout.write('mobile navigation translation contract passed\n');
