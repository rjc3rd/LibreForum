<?php
// What people type, made safe. Posts are plain text: paragraphs, clickable web links, `inline code` and
// ``` fenced code blocks ```. Nothing else is interpreted, and everything is escaped before it is shown.

declare(strict_types=1);

const LF_TITLE_MIN = 3;
const LF_TITLE_MAX = 150;
const LF_BODY_MAX = 10000;

// Text as typed becomes text as stored: valid UTF-8, plain newlines, no control characters and no
// characters that flip the reading direction (they can make a link or a name look like something else).
function lf_clean_text(string $text): string
{
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', $text) ?? '';
    return preg_replace('~[\x{202A}-\x{202E}\x{2066}-\x{2069}]~u', '', $text) ?? '';
}

// Any text as one tidy line.
function lf_clean_line(string $text): string
{
    return trim(preg_replace('~\s+~u', ' ', lf_clean_text($text)) ?? '');
}

// A title is one line.
function lf_clean_title(string $title): string
{
    return trim(preg_replace('~\s+~u', ' ', lf_clean_text($title)) ?? '');
}

function lf_title_problem(string $title): ?string
{
    $length = mb_strlen($title);
    if ($length < LF_TITLE_MIN) {
        return 'Please give the thread a title of at least ' . LF_TITLE_MIN . ' characters.';
    }
    if ($length > LF_TITLE_MAX) {
        return 'That title is too long. Please keep it under ' . LF_TITLE_MAX . ' characters.';
    }
    return null;
}

function lf_clean_body(string $body): string
{
    return trim(lf_clean_text($body));
}

function lf_body_problem(string $body): ?string
{
    if ($body === '') {
        return 'Please write something first.';
    }
    if (mb_strlen($body) > LF_BODY_MAX) {
        return 'That post is too long. Please keep it under ' . number_format(LF_BODY_MAX) . ' characters.';
    }
    return null;
}

// The first characters of a post on one line, for lists.
function lf_excerpt(string $body, int $length = 140): string
{
    $line = trim(preg_replace('~\s+~u', ' ', $body) ?? '');
    return mb_strlen($line) > $length ? rtrim(mb_substr($line, 0, $length - 1)) . '…' : $line;
}

// A post as HTML.
function lf_body_html(string $body): string
{
    $parts = preg_split('~^```[A-Za-z0-9_+.-]*[ \t]*\n(.*?)^```[ \t]*(?:\n|$)~ms', str_replace("\r\n", "\n", $body), -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        $parts = [$body];
    }
    $html = [];
    foreach ($parts as $i => $part) {
        // With the pattern's one capture, odd pieces are code blocks and even pieces are ordinary text.
        $piece = $i % 2 === 1 ? '<pre><code>' . h(rtrim($part, "\n")) . '</code></pre>' : lf_paragraphs_html($part);
        if ($piece !== '') {
            $html[] = $piece;
        }
    }
    return implode("\n", $html);
}

function lf_paragraphs_html(string $text): string
{
    $html = [];
    foreach (preg_split('~\n{2,}~', trim($text, "\n")) ?: [] as $paragraph) {
        if (trim($paragraph) !== '') {
            $html[] = '<p>' . nl2br(lf_inline_html(trim($paragraph, "\n")), false) . '</p>';
        }
    }
    return implode("\n", $html);
}

function lf_inline_html(string $text): string
{
    $html = '';
    foreach (preg_split('~`([^`\n]+)`~', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text] as $i => $part) {
        $html .= $i % 2 === 1 ? '<code>' . h($part) . '</code>' : lf_links_html($part);
    }
    return $html;
}

// Escapes text and turns web addresses (http and https only) into links that don't tell the other site
// where the visitor came from and don't help its search ranking.
function lf_links_html(string $text): string
{
    if (!preg_match_all('~https?://[^\s<>"\'`]+~iu', $text, $found, PREG_OFFSET_CAPTURE)) {
        return h($text);
    }
    $pairs = [')' => '(', ']' => '[', '}' => '{'];
    $html = '';
    $pos = 0;
    foreach ($found[0] as [$url, $offset]) {
        // Punctuation after a link belongs to the sentence, and so does a closing bracket that has no opening one.
        while ($url !== '') {
            $last = $url[strlen($url) - 1];
            if (str_contains('.,;:!?', $last) || (isset($pairs[$last]) && substr_count($url, $last) > substr_count($url, $pairs[$last]))) {
                $url = substr($url, 0, -1);
            } else {
                break;
            }
        }
        $host = parse_url($url, PHP_URL_HOST);
        $html .= h(substr($text, $pos, $offset - $pos));
        $html .= is_string($host) && $host !== ''
            ? '<a href="' . h($url) . '" rel="noopener noreferrer nofollow ugc">' . h($url) . '</a>'
            : h($url);
        $pos = $offset + strlen($url);
    }
    return $html . h(substr($text, $pos));
}
