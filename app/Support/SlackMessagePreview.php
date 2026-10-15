<?php

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Shows a Slack message as it will look in Slack: *bold*, mentions, links and line breaks.
 */
class SlackMessagePreview
{
    public static function html(string $message, ?string $attachment = null): Htmlable
    {
        $text = e($message);
        $text = preg_replace('/&lt;@([A-Z0-9]+)&gt;/', '<span style="color: #1d9bd1; background: rgb(29 155 209 / 0.12); border-radius: 0.25rem; padding: 0 0.125rem;">@$1</span>', $text);
        $text = preg_replace('/\*([^*\n]+)\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('~(https?://[^\s<]+)~', '<span style="color: #1d9bd1; text-decoration: underline;">$1</span>', $text);

        return new HtmlString(
            '<div style="border-left: 4px solid #4a154b; border-radius: 0.375rem; padding: 0.5rem 0.75rem; background: rgb(127 127 127 / 0.06);">'
                .'<div style="white-space: pre-line;">'.$text.'</div>'
                .($attachment ? '<div style="display: inline-flex; align-items: center; gap: 0.375rem; margin-top: 0.5rem; padding: 0.25rem 0.5rem; border-radius: 0.375rem; box-shadow: inset 0 0 0 1px rgb(127 127 127 / 0.25); font-size: 0.75rem;">📄 '.e($attachment).'</div>' : '')
            .'</div>'
        );
    }
}
