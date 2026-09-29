<?php
// Themes live in themes/<name>/: templates/*.php for the pages and assets/* for CSS, JS and images.
// A theme only needs the files it changes: anything missing comes from themes/default.
// Choose one with 'theme' => 'name' in config.php. Themes kept elsewhere (a private folder with your
// own branding, say) are found through 'theme_paths' => ['/path/to/themes'] in config.php.
// A theme's assets/custom.css is loaded after the main stylesheet, so a theme can just change the
// color and font tokens instead of copying everything.

declare(strict_types=1);

function lf_theme_dirs(): array
{
    $dirs = [];
    foreach ((array) lf_cfg('theme_paths', []) as $path) {
        $dirs[] = rtrim((string) $path, '/');
    }
    $dirs[] = LF_ROOT . '/themes';
    return $dirs;
}

function lf_theme(): string
{
    $name = (string) lf_cfg('theme', 'default');
    if (!preg_match('~^[a-z0-9-]+$~', $name)) {
        return 'default';
    }
    foreach (lf_theme_dirs() as $dir) {
        if (is_dir("$dir/$name")) {
            return $name;
        }
    }
    return 'default';
}

// The active theme's copy of a file, or the default theme's. $onlyActive skips the fallback.
function lf_theme_file(string $kind, string $file, bool $onlyActive = false): ?string
{
    foreach ($onlyActive ? [lf_theme()] : array_unique([lf_theme(), 'default']) as $theme) {
        foreach (lf_theme_dirs() as $dir) {
            $path = "$dir/$theme/$kind/$file";
            if (is_file($path)) {
                return $path;
            }
        }
    }
    return null;
}

// Renders a template with $vars as local variables.
function lf_render(string $template, array $vars = []): void
{
    $file = lf_theme_file('templates', "$template.php");
    if ($file === null) {
        throw new RuntimeException("LibreForum: no template $template");
    }
    extract($vars, EXTR_SKIP);
    include $file;
}

// A page: the template's own content, wrapped in the theme's layout (head, bar, footer).
function lf_page(string $template, array $vars = [], int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    ob_start();
    lf_render($template, $vars);
    $content = (string) ob_get_clean();
    lf_render('layout', $vars + ['content' => $content]);
}

// Address of a theme asset, with a version so browsers pick up changes.
function lf_asset(string $file): string
{
    $path = lf_theme_file('assets', $file);
    return lf_url('asset.php') . '?f=' . rawurlencode($file) . ($path ? '&v=' . filemtime($path) : '');
}

if (!function_exists('h')) {
    function h(string|int|float|null $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// The opening tag of a form that posts to a page of the forum, with the login's csrf value already in it.
// $confirm asks "are you sure?" before sending (theme's forum.js does the asking).
function lf_form(string $path, string $csrf, string $class = '', string $confirm = ''): string
{
    return '<form method="post" action="' . h(lf_url($path)) . '"' . ($class !== '' ? ' class="' . h($class) . '"' : '')
        . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
}

// Small line icons, drawn here so nothing is loaded from anywhere. Colored by the text around them.
function lf_icon(string $name): string
{
    static $icons = [
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'pin' => '<circle cx="12" cy="8" r="4"/><path d="M12 12v9"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'flag' => '<path d="M6 21V4"/><path d="M6 5h11l-2 4 2 4H6"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
        'folder' => '<path d="M3 7h6l2 2h10v10H3z"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'check' => '<path d="M5 12l5 5 9-10"/>',
        'back' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4z"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'up' => '<path d="M6 15l6-6 6 6"/>',
        'down' => '<path d="M6 9l6 6 6-6"/>',
    ];
    return isset($icons[$name]) ? '<svg class="lf-icon" viewBox="0 0 24 24" aria-hidden="true">' . $icons[$name] . '</svg>' : '';
}
