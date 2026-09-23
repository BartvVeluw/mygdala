<?php

/**
 * The eyebrow above a block's heading, shared by every block that has one.
 *
 * An eyebrow is optional everywhere: an empty one prints nothing at all, no
 * empty <p> that would still draw the decorative line of .eyebrow::before
 * and keep a line box. The spacing that only makes sense next to an eyebrow
 * hangs off the eyebrow itself (.section-head .eyebrow + h2 in core.css),
 * so nothing is left behind when it is not printed.
 *
 * Plain text only; escaped here.
 */

if (!function_exists('render_eyebrow')) {
    function render_eyebrow(string $text, string $extraClass = ''): void
    {
        if (trim($text) === '') {
            return;
        }

        $class = trim('eyebrow ' . $extraClass);
        echo '<p class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }
}
