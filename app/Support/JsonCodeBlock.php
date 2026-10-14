<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Renders (pretty-printed) JSON as a readable code block: dark background, line numbers, indent guides and
 * colours for keys, strings, numbers and literals. Styles are inline, so no frontend build is needed.
 */
class JsonCodeBlock
{
    private const IndentWidth = 4;

    /**
     * Token colours, keyed by token type.
     *
     * @var array<string, string>
     */
    private const Colors = [
        'key' => '#7dd3fc',
        'string' => '#86efac',
        'number' => '#fdba74',
        'literal' => '#c4b5fd',
        'punctuation' => '#94a3b8',
    ];

    public static function render(string $code, string $language = 'JSON'): Htmlable
    {
        $lines = explode("\n", rtrim($code, "\n"));
        $lineNumberWidth = strlen((string) count($lines));

        $rows = '';
        foreach ($lines as $index => $line) {
            $rows .= '<tr>'
                .'<td style="padding: 0 1rem 0 0.75rem; text-align: right; color: #475569; user-select: none; vertical-align: top; min-width: '.($lineNumberWidth + 1).'ch;">'.($index + 1).'</td>'
                .'<td style="padding: 0 1rem 0 0; white-space: pre; color: #e2e8f0;">'.self::indentGuides($line).self::highlight(ltrim($line, ' ')).'</td>'
                .'</tr>';
        }

        return new HtmlString(
            '<div style="border-radius: 0.75rem; overflow: hidden; background: #0f172a; box-shadow: 0 0 0 1px rgb(255 255 255 / 0.08), 0 1px 3px rgb(0 0 0 / 0.2);">'
                .'<div style="display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; background: #1e293b; border-bottom: 1px solid rgb(255 255 255 / 0.06); font-family: ui-sans-serif, system-ui, sans-serif; font-size: 0.75rem; color: #94a3b8;">'
                    .'<span style="display: inline-flex; align-items: center; gap: 0.375rem;">'
                        .'<span style="width: 0.625rem; height: 0.625rem; border-radius: 9999px; background: #f87171;"></span>'
                        .'<span style="width: 0.625rem; height: 0.625rem; border-radius: 9999px; background: #fbbf24;"></span>'
                        .'<span style="width: 0.625rem; height: 0.625rem; border-radius: 9999px; background: #4ade80;"></span>'
                        .'<span style="margin-left: 0.5rem; font-weight: 600; letter-spacing: 0.04em;">'.e($language).'</span>'
                    .'</span>'
                    .'<span>'.count($lines).' lines · click to copy</span>'
                .'</div>'
                .'<div style="overflow-x: auto; padding: 0.75rem 0;">'
                    .'<table style="border-collapse: collapse; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.8125rem; line-height: 1.6;"><tbody>'
                        .$rows
                    .'</tbody></table>'
                .'</div>'
            .'</div>'
        );
    }

    /**
     * Turn the leading spaces into vertical guide lines, one per indent level.
     */
    private static function indentGuides(string $line): string
    {
        $spaces = strlen($line) - strlen(ltrim($line, ' '));
        $levels = intdiv($spaces, self::IndentWidth);

        return str_repeat('<span data-indent-guide style="display: inline-block; width: '.self::IndentWidth.'ch; box-shadow: inset 1px 0 0 rgb(148 163 184 / 0.22);">'.str_repeat(' ', self::IndentWidth).'</span>', $levels)
            .str_repeat(' ', $spaces % self::IndentWidth);
    }

    /**
     * Colour the JSON tokens in one line, escaping everything else.
     */
    private static function highlight(string $line): string
    {
        $pattern = '/("(?:[^"\\\\]|\\\\.)*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|([{}\[\],])/';

        preg_match_all($pattern, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $html = '';
        $position = 0;

        foreach ($matches as $match) {
            [$text, $offset] = $match[0];
            $html .= e(substr($line, $position, $offset - $position));

            $html .= match (true) {
                isset($match[2]) && $match[2][1] !== -1 => self::token('key', $match[1][0]).e($match[2][0]),
                isset($match[1]) && $match[1][1] !== -1 && $match[1][0] !== '' => self::token('string', $match[1][0]),
                isset($match[3]) && $match[3][1] !== -1 && $match[3][0] !== '' => self::token('literal', $match[3][0]),
                isset($match[4]) && $match[4][1] !== -1 && $match[4][0] !== '' => self::token('number', $match[4][0]),
                default => self::token('punctuation', $text),
            };

            $position = $offset + strlen($text);
        }

        return $html.e(substr($line, $position));
    }

    private static function token(string $type, string $text): string
    {
        return '<span data-token="'.$type.'" style="color: '.self::Colors[$type].';">'.e($text).'</span>';
    }
}
