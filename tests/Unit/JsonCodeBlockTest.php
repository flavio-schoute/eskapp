<?php

use App\Support\JsonCodeBlock;

it('numbers every line and shows the line count', function () {
    $html = JsonCodeBlock::render("{\n    \"a\": 1\n}")->toHtml();

    expect(substr_count($html, '<tr>'))->toBe(3)
        ->and($html)->toContain('3 lines · click to copy')
        ->toContain('>JSON<');
});

it('colours keys, strings, numbers and literals', function () {
    $html = JsonCodeBlock::render("{\n    \"status\": \"issued\",\n    \"quantity\": 1,\n    \"memo\": null\n}")->toHtml();

    expect($html)
        ->toContain('<span data-token="key" style="color: #7dd3fc;">&quot;status&quot;</span>:')
        ->toContain('<span data-token="string" style="color: #86efac;">&quot;issued&quot;</span>')
        ->toContain('<span data-token="number" style="color: #fdba74;">1</span>')
        ->toContain('<span data-token="literal" style="color: #c4b5fd;">null</span>');
});

it('draws one indent guide per indent level', function () {
    $html = JsonCodeBlock::render("{\n    \"a\": {\n        \"b\": true\n    }\n}")->toHtml();

    expect(substr_count($html, 'data-indent-guide'))->toBe(4);
});

it('escapes html inside the code', function () {
    $html = JsonCodeBlock::render('{"body": "<script>alert(1)</script>"}')->toHtml();

    expect($html)->not->toContain('<script>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});
