import {phpJson} from './php-probe.mjs';

export function productEditorFixture() {
    return phpJson("require 'tests/fixtures/product_editor_sections.php';");
}
