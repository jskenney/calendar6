<?php

declare(strict_types=1);

namespace Cal6\Render;

/**
 * Markdown to HTML, ported from calendar-core 4.6 (calendar_markdown.php) by way of Calendar 5 (same
 * author, MIT).  The output is then handled like an .html file, so every Cal4
 * tag (<inst>, <student>, <inject>, <replace>, <lock>, ...) works in .md files.
 *
 * Supported syntax (CommonMark / GitHub style subset):
 *   - ATX (# Title) and setext (Title / =====) headings, optional {#custom-id}
 *   - paragraphs, hard line breaks (two trailing spaces or a trailing \)
 *   - *emphasis*, **strong**, ***both***, _emphasis_, __strong__, ~~strike~~
 *   - `code spans`, fenced code blocks (``` or ~~~, with a language), indented code
 *   - [links](url "title"), ![images](src "title"), <https://autolinks>,
 *     bare https:// URLs, and reference links [text][ref] with [ref]: url
 *   - ordered and unordered lists (nested by indentation), task items [ ] / [x]
 *   - > block quotes, horizontal rules (---, ***, ___), GitHub tables
 *   - raw HTML, both as blocks and inline (so Cal4 tags pass straight through)
 *   - MathJax: \( \), \[ \] and $$ $$ are left untouched for MathJax.
 *
 * Markdown inside an HTML block is converted only when it is separated from
 * the tags by blank lines.
 */
final class Markdown
{

    # Headings at these levels get an id (and so appear in the navbar menu).
    public $menuLevels = array(2);

    private $refs = array();
    private $slugs = array();
    private $holds = array();
    private $quoteDepth = 0;
    private $listDepth = 0;

    # Elements that start an inline run rather than an HTML block
    private static $inlineTags = array('a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'img',
        'input', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
        'del', 'ins', 'font', 'replace', 'url', 'showlink', 'search', 'keyword');

    public function text($text) {
        $this->refs = array();
        $this->slugs = array();
        $this->holds = array();
        $text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
        $text = str_replace("\t", '    ', $text);
        # Remove a UTF-8 byte order mark
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);
        }
        $lines = $this->removeComments(explode("\n", $text));
        $lines = $this->collectReferences($lines);
        $html = $this->blocks($lines);
        return $this->restore($html);
    }

    # --------------------------------------------------------------- helpers

    private function esc($s) {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8', False);
    }

    # Escape text, keeping existing HTML entities such as &copy; or &#8212;
    private function escText($s) {
        $s = preg_replace('/&(?!#[0-9]+;|#x[0-9a-fA-F]+;|[A-Za-z][A-Za-z0-9]*;)/', '&amp;', $s);
        return str_replace(array('<', '>'), array('&lt;', '&gt;'), $s);
    }

    # Store a finished HTML fragment and return a placeholder for it
    private function hold($html) {
        $this->holds[] = $html;
        return "\x1A" . (count($this->holds) - 1) . "\x1A";
    }

    private function restore($html) {
        # Placeholders can be nested, so repeat until none are left
        for ($i = 0; $i < 10 && strpos($html, "\x1A") !== False; $i++) {
            $html = preg_replace_callback('/\x1A(\d+)\x1A/', function ($m) {
                return isset($this->holds[intval($m[1])]) ? $this->holds[intval($m[1])] : '';
            }, $html);
        }
        return $html;
    }

    # HTML comments are removed before conversion (M-30), so one that starts mid-line or spans a
    # blank line is not shown as text.  Fenced and indented code and `code spans` keep theirs
    # (they are shown, escaped, as written); a comment that is never closed removes the rest.
    private function removeComments($lines) {
        $out = array();
        $fence = '';
        $inComment = False;
        $codeAllowed = True; # an indented line starts code only after a blank line or code
        foreach ($lines as $line) {
            if (!$inComment) {
                if ($fence !== '') {
                    if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $line)) {
                        $fence = '';
                    }
                    $out[] = $line;
                    continue;
                }
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                    $fence = $m[1];
                    $out[] = $line;
                    continue;
                }
                if ($this->indent($line) >= 4 && $codeAllowed && !$this->isHiddenTag($line)) {
                    $out[] = $line;
                    continue;
                }
            }
            $kept = '';
            $pos = 0;
            $length = strlen($line);
            while ($pos < $length) {
                if ($inComment) {
                    $end = strpos($line, '-->', $pos);
                    if ($end === False) { $pos = $length; break; }
                    $pos = $end + 3;
                    $inComment = False;
                    continue;
                }
                $next = strcspn($line, '`<', $pos) + $pos;
                $kept .= substr($line, $pos, $next - $pos);
                if ($next >= $length) { break; }
                if ($line[$next] === '`') {
                    $run = strspn($line, '`', $next);
                    $close = strpos($line, str_repeat('`', $run), $next + $run);
                    $stop = $close === False ? $next + $run : $close + $run;
                    $kept .= substr($line, $next, $stop - $next);
                    $pos = $stop;
                } elseif (substr($line, $next, 4) === '<!--') {
                    $inComment = True;
                    $pos = $next + 4;
                    # <!--> and <!---> end at once, as in a browser
                    if (substr($line, $pos, 1) === '>') { $pos++; $inComment = False; }
                    elseif (substr($line, $pos, 2) === '->') { $pos += 2; $inComment = False; }
                } elseif (preg_match('/\G<\/?[A-Za-z][^\s\/>]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>/', $line, $tag, 0, $next)) {
                    # a tag is copied whole: <!-- inside its attribute values is not a comment
                    $kept .= $tag[0];
                    $pos = $next + strlen($tag[0]);
                } else {
                    $kept .= '<';
                    $pos = $next + 1;
                }
            }
            $out[] = $kept;
            $codeAllowed = $this->isBlank($kept) || $this->indent($line) >= 4 && $codeAllowed;
        }
        return $out;
    }

    # A hidden block's tag (<inst>, <lock>, <postmeeting>, <host>, <student>) on its own line is
    # HTML however far it is indented, never code (L-42): an indented <inst> hides its content.
    private function isHiddenTag($line) {
        return (bool)preg_match('/^\s*<\/?(inst|lock|postmeeting|host|student)(\s|>|$)/i', $line);
    }

    private function isBlank($line) {
        return trim($line) === '';
    }

    private function indent($line) {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    # Remove up to $n leading spaces
    private function outdent($line, $n) {
        $i = 0;
        while ($i < $n && $i < strlen($line) && $line[$i] === ' ') {
            $i++;
        }
        return substr($line, $i);
    }

    # Clean a link/image destination.  Relative paths are URL-decoded so that
    # they match the file names Cal4 rewrites (for example "my%20pic.png").
    private function destination($url) {
        $url = trim($url);
        if (strlen($url) > 1 && $url[0] === '<' && substr($url, -1) === '>') {
            $url = substr($url, 1, -1);
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && substr($url, 0, 2) !== '//') {
            $url = rawurldecode($url);
        }
        # Never allow script URLs
        if (preg_match('/^\s*(javascript|vbscript|data):/i', $url) && !preg_match('#^data:image/(png|gif|jpe?g|webp);#i', $url)) {
            $url = '#';
        }
        return $url;
    }

    private function slug($text, $level) {
        # hidden blocks leave no trace in the id (L-58)
        $plain = html_entity_decode(strip_tags($this->withoutHiddenBlocks($this->restore($text))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $plain), '-'));
        if ($slug === '') {
            $slug = 'section';
        }
        $base = $slug;
        $n = 1;
        while (isset($this->slugs[$slug])) {
            $n++;
            $slug = "$base-$n";
        }
        $this->slugs[$slug] = True;
        return $slug;
    }

    # ------------------------------------------------------------ references

    private function collectReferences($lines) {
        $out = array();
        $infence = False;
        $hidden = 0; # inside a hidden block, a definition is part of the block, not a link (L-62)
        foreach ($lines as $line) {
            if (preg_match('/^ {0,3}(```|~~~)/', $line)) {
                $infence = !$infence;
            }
            if (!$infence) {
                $opens = preg_match_all('/<(inst|lock|postmeeting|host|student)(?=[\s>\/])/i', $line);
                $closes = preg_match_all('/<\/(inst|lock|postmeeting|host|student)\s*>/i', $line);
                $wasHidden = $hidden > 0;
                $hidden = max(0, $hidden + $opens - $closes);
                if ($wasHidden || $hidden > 0) {
                    $out[] = $line;
                    continue;
                }
            }
            if (!$infence && preg_match('/^ {0,3}\[([^\]]+)\]:\s*(<[^>]*>|\S+)(?:\s+(?:"([^"]*)"|\'([^\']*)\'|\(([^)]*)\)))?\s*$/', $line, $m)) {
                $label = strtolower(preg_replace('/\s+/', ' ', trim($m[1])));
                if (!isset($this->refs[$label])) {
                    $title = '';
                    foreach (array(3, 4, 5) as $t) {
                        if (isset($m[$t]) && $m[$t] !== '') { $title = $m[$t]; }
                    }
                    $this->refs[$label] = array($this->destination($m[2]), $title);
                }
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    # ---------------------------------------------------------------- blocks

    private function listMarker($line) {
        if (preg_match('/^( {0,3})([-*+])( +|$)(.*)$/', $line, $m)) {
            if (preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $line)) {
                return False; # this is a horizontal rule
            }
            return array('ordered' => False, 'indent' => strlen($m[1]), 'width' => strlen($m[1]) + 1 + max(1, min(4, strlen($m[3]))), 'text' => $m[4], 'start' => 1, 'char' => $m[2]);
        }
        if (preg_match('/^( {0,3})(\d{1,9})([.)])( +|$)(.*)$/', $line, $m)) {
            return array('ordered' => True, 'indent' => strlen($m[1]), 'width' => strlen($m[1]) + strlen($m[2]) + 1 + max(1, min(4, strlen($m[4]))), 'text' => $m[5], 'start' => intval($m[2]), 'char' => $m[3]);
        }
        return False;
    }

    private function htmlBlockStart($line) {
        if ($this->isHiddenTag($line)) {
            return True;
        }
        if (preg_match('/^ {0,3}<!--/', $line)) {
            return True;
        }
        if (preg_match('/^ {0,3}<\/?([A-Za-z][A-Za-z0-9-]*)(\s|\/?>|$)/', $line, $m)) {
            return !in_array(strtolower($m[1]), self::$inlineTags, True);
        }
        return False;
    }

    private function isTableSeparator($line) {
        return (bool)preg_match('/^ {0,3}\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $line) && strpos($line, '-') !== False
            && (strpos($line, '|') !== False);
    }

    private function splitRow($line) {
        $line = trim($line);
        if (substr($line, 0, 1) === '|') { $line = substr($line, 1); }
        if (substr($line, -1) === '|' && substr($line, -2) !== '\\|') { $line = substr($line, 0, -1); }
        $cells = preg_split('/(?<!\\\\)\|/', $line);
        return array_map(function ($c) { return trim(str_replace('\\|', '|', $c)); }, $cells);
    }

    # Does this line interrupt a paragraph?
    private function startsBlock($line) {
        return preg_match('/^ {0,3}(#{1,6})(\s|$)/', $line)
                || preg_match('/^ {0,3}(```|~~~)/', $line)
                || preg_match('/^ {0,3}>/', $line)
                || preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $line)
                || $this->htmlBlockStart($line)
                || (($lm = $this->listMarker($line)) !== False && trim($lm['text']) !== '' && (!$lm['ordered'] || $lm['start'] == 1));
    }

    private function blocks($lines) {
        $out = array();
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];

            if ($this->isBlank($line)) {
                $i++;
                continue;
            }

            # Fenced code
            if (preg_match('/^( {0,3})(`{3,}|~{3,})\s*([^`\s]*)[^`]*$/', $line, $m)) {
                $fence = $m[2];
                $pad = strlen($m[1]);
                $lang = $m[3];
                $code = array();
                $i++;
                while ($i < $n && !preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $lines[$i])) {
                    $code[] = $this->outdent($lines[$i], $pad);
                    $i++;
                }
                $i++; # closing fence
                $class = $lang !== '' ? ' class="language-' . $this->esc($lang) . '"' : '';
                $body = $this->esc(implode("\n", $code));
                $out[] = $this->hold("<pre><code$class>" . ($body === '' ? '' : $body . "\n") . "</code></pre>");
                continue;
            }

            # Indented code
            if ($this->indent($line) >= 4 && !$this->isHiddenTag($line)) {
                $code = array();
                while ($i < $n && ($this->isBlank($lines[$i]) || $this->indent($lines[$i]) >= 4 && !$this->isHiddenTag($lines[$i]))) {
                    $code[] = $this->outdent($lines[$i], 4);
                    $i++;
                }
                while (!empty($code) && trim(end($code)) === '') {
                    array_pop($code);
                }
                $out[] = $this->hold('<pre><code>' . $this->esc(implode("\n", $code)) . "\n</code></pre>");
                continue;
            }

            # ATX heading
            if (preg_match('/^ {0,3}(#{1,6})(?:\s+(.*?))?(?:\s+#+)?\s*$/', $line, $m)) {
                $out[] = $this->heading(strlen($m[1]), isset($m[2]) ? $m[2] : '');
                $i++;
                continue;
            }

            # Horizontal rule
            if (preg_match('/^ {0,3}([-*_])( *\1){2,} *$/', $line)) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            # Block quote (at most 16 deep: deeper ">" are text, so a line of thousands of
            # ">" can not exhaust memory, L-34)
            if ($this->quoteDepth < 16 && preg_match('/^ {0,3}>/', $line)) {
                $quote = array();
                while ($i < $n && !$this->isBlank($lines[$i])) {
                    if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $m)) {
                        $quote[] = $m[1];
                    } elseif (!empty($quote) && !$this->startsBlock($lines[$i])) {
                        $quote[] = $lines[$i]; # lazy continuation
                    } else {
                        break;
                    }
                    $i++;
                    # A blank line followed by more quoted lines continues the quote
                    if ($i + 1 < $n && $this->isBlank($lines[$i]) && preg_match('/^ {0,3}>/', $lines[$i + 1])) {
                        $quote[] = '';
                        $i++;
                    }
                }
                $this->quoteDepth++;
                try {
                    $out[] = "<blockquote>\n" . $this->blocks($quote) . "\n</blockquote>";
                } finally {
                    $this->quoteDepth--;
                }
                continue;
            }

            # HTML block (also Cal4 tags such as <inst>, <inject>, <lock>)
            if ($this->htmlBlockStart($line)) {
                $block = array();
                if (preg_match('/^ {0,3}<!--/', $line)) {
                    while ($i < $n) {
                        $block[] = $lines[$i];
                        $i++;
                        if (strpos(end($block), '-->') !== False) { break; }
                    }
                } else {
                    while ($i < $n && !$this->isBlank($lines[$i])) {
                        $block[] = $lines[$i];
                        $i++;
                    }
                }
                $out[] = $this->hold(implode("\n", $block));
                continue;
            }

            # Table
            if ($i + 1 < $n && strpos($line, '|') !== False && $this->isTableSeparator($lines[$i + 1])) {
                $header = $this->splitRow($line);
                $aligns = array();
                foreach ($this->splitRow($lines[$i + 1]) as $sep) {
                    $left = substr($sep, 0, 1) === ':';
                    $right = substr($sep, -1) === ':';
                    $aligns[] = ($left && $right) ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
                }
                if (count($aligns) === count($header)) {
                    $i += 2;
                    $html = "<table class=\"table table-bordered\">\n<thead>\n<tr>";
                    foreach ($header as $c => $cell) {
                        $html .= '<th' . ($aligns[$c] ? ' style="text-align: ' . $aligns[$c] . '"' : '') . '>' . $this->inline($cell) . '</th>';
                    }
                    $html .= "</tr>\n</thead>\n<tbody>\n";
                    while ($i < $n && !$this->isBlank($lines[$i]) && strpos($lines[$i], '|') !== False) {
                        $cells = $this->splitRow($lines[$i]);
                        $html .= '<tr>';
                        foreach ($header as $c => $unused) {
                            $cell = isset($cells[$c]) ? $cells[$c] : '';
                            $html .= '<td' . ($aligns[$c] ? ' style="text-align: ' . $aligns[$c] . '"' : '') . '>' . $this->inline($cell) . '</td>';
                        }
                        $html .= "</tr>\n";
                        $i++;
                    }
                    $out[] = $html . "</tbody>\n</table>";
                    continue;
                }
            }

            # List
            if ($this->listDepth < 16 && ($lm = $this->listMarker($line)) !== False) { # at most 16 deep (L-37)
                $this->listDepth++;
                try {
                    list($html, $i) = $this->parseList($lines, $i, $lm);
                } finally {
                    $this->listDepth--;
                }
                $out[] = $html;
                continue;
            }

            # Paragraph (or setext heading)
            $para = array();
            while ($i < $n && !$this->isBlank($lines[$i])) {
                # Setext heading underline (checked before "---" is read as a rule)
                if (!empty($para) && preg_match('/^ {0,3}(=+|-+)\s*$/', $lines[$i], $m)) {
                    $out[] = $this->heading($m[1][0] === '=' ? 1 : 2, implode("\n", array_map('trim', $para)));
                    $para = array();
                    $i++;
                    continue 2;
                }
                if (!empty($para) && $this->startsBlock($lines[$i])) {
                    break;
                }
                if (!empty($para) && strpos($lines[$i], '|') !== False && $i + 1 < $n && $this->isTableSeparator($lines[$i + 1])) {
                    break; # a table starts here
                }
                $para[] = $lines[$i];
                $i++;
            }
            if (!empty($para)) {
                $out[] = '<p>' . $this->inline($this->joinParagraph($para)) . '</p>';
            }
        }
        return implode("\n", $out);
    }

    private function joinParagraph($para) {
        $last = count($para) - 1;
        foreach ($para as $k => $l) {
            $l = ltrim($l);
            if ($k < $last) {
                if (preg_match('/ {2,}$/', $l) || preg_match('/(?<!\\\\)\\\\$/', $l)) {
                    $l = preg_replace('/( {2,}|\\\\)$/', '', $l) . "\x1B"; # hard line break marker
                } else {
                    $l = rtrim($l);
                }
            } else {
                $l = rtrim($l);
            }
            $para[$k] = $l;
        }
        return implode("\n", $para);
    }

    private function heading($level, $text) {
        $id = '';
        if (preg_match('/^(.*?)\s*\{#([A-Za-z][A-Za-z0-9_:.-]*)\}\s*$/', $text, $m)) {
            $text = $m[1];
            $id = $m[2];
            $this->slugs[$id] = True;
        }
        $inner = $this->inline(trim($text));
        if ($id === '' && in_array($level, $this->menuLevels, True)) {
            $id = $this->slug($inner, $level);
        }
        return "<h$level" . ($id !== '' ? ' id="' . $this->esc($id) . '"' : '') . ">$inner</h$level>";
    }

    private function parseList($lines, $i, $first) {
        $n = count($lines);
        $ordered = $first['ordered'];
        $items = array();
        $loose = False;
        $sawBlank = False;
        while ($i < $n) {
            $lm = $this->listMarker($lines[$i]);
            if ($lm === False || $lm['ordered'] !== $ordered || $lm['char'] !== $first['char'] || $lm['indent'] >= $first['width']) {
                break;
            }
            if ($sawBlank) {
                $loose = True;
            }
            $width = (trim($lm['text']) === '') ? $lm['indent'] + ($ordered ? strlen((string)$lm['start']) + 2 : 2) : $lm['width'];
            $item = array($lm['text']);
            $i++;
            $sawBlank = False;
            while ($i < $n) {
                $l = $lines[$i];
                if ($this->isBlank($l)) {
                    $sawBlank = True;
                    $item[] = '';
                    $i++;
                    continue;
                }
                if ($this->indent($l) >= $width) {
                    if ($sawBlank) {
                        # a blank line inside an item (between its own blocks) makes it loose
                        $loose = True;
                    }
                    $item[] = $this->outdent($l, $width);
                    $sawBlank = False;
                    $i++;
                    continue;
                }
                if (!$sawBlank && !$this->startsBlock($l) && $this->listMarker($l) === False) {
                    $item[] = $l; # lazy continuation of the paragraph
                    $i++;
                    continue;
                }
                break;
            }
            while (!empty($item) && trim(end($item)) === '') {
                array_pop($item);
            }
            $items[] = $item;
        }
        # The trailing blank before the next block does not make the list loose
        $tag = $ordered ? 'ol' : 'ul';
        $start = ($ordered && $first['start'] != 1) ? ' start="' . intval($first['start']) . '"' : '';
        $html = "<$tag$start>\n";
        foreach ($items as $item) {
            $task = '';
            if (!$ordered && isset($item[0]) && preg_match('/^\[([ xX])\]\s+(.*)$/', $item[0], $m)) {
                $task = '<input type="checkbox" disabled' . (strtolower($m[1]) === 'x' ? ' checked' : '') . '> ';
                $item[0] = $m[2];
            }
            $body = $this->blocks($item);
            if (!$loose) {
                # tight list: drop the <p> around plain paragraphs
                $body = preg_replace('#<p>(.*?)</p>#s', '$1', $body);
            }
            $html .= ($task !== '' ? '<li class="task-list-item">' : '<li>') . $task . $body . "</li>\n";
        }
        return array($html . "</$tag>", $i);
    }

    # ---------------------------------------------------------------- inline

    # A pattern that gives up on a long text (PCRE limits) leaves that text as it was: safe for
    # code spans, math, links and URLs (L-66) ...
    private function orUnchanged($text, $result) {
        return $result === null ? $text : $result;
    }

    # ... but not for raw HTML: escaping it instead would show a hidden block's tag and content
    private function orFail($result) {
        if ($result === null) {
            throw new \RuntimeException('markdown: the page is too complex to convert safely');
        }
        return $result;
    }

    private function inline($text) {
        # 1. Code spans
        $text = $this->orUnchanged($text, preg_replace_callback('/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/s', function ($m) {
            $code = $m[2];
            if (strlen($code) > 2 && $code[0] === ' ' && substr($code, -1) === ' ' && trim($code) !== '') {
                $code = substr($code, 1, -1);
            }
            return $this->hold('<code>' . $this->esc(str_replace("\n", ' ', $code)) . '</code>');
        }, $text));

        # 2. Math for MathJax: keep exactly as written (only HTML-escape it)
        $text = $this->orUnchanged($text, preg_replace_callback('/\$\$.+?\$\$|\\\\\(.+?\\\\\)|\\\\\[.+?\\\\\]/s', function ($m) {
            return $this->hold($this->escText($m[0]));
        }, $text));

        # 3. Autolinks <https://...> and <user@example.com>
        $text = $this->orUnchanged($text, preg_replace_callback('/<((?:https?|ftp):\/\/[^\s<>]+)>/i', function ($m) {
            return $this->hold('<a href="' . $this->esc($m[1]) . '">' . $this->esc($m[1]) . '</a>');
        }, $text));
        $text = $this->orUnchanged($text, preg_replace_callback('/<([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})>/', function ($m) {
            return $this->hold('<a href="mailto:' . $this->esc($m[1]) . '">' . $this->esc($m[1]) . '</a>');
        }, $text));

        # 4. Raw inline HTML (and Cal4 tags) pass through unchanged
        #    (a tag with quoted values first, so <!-- or > inside a value stays in the tag)
        $text = $this->orFail(preg_replace_callback('/<\/?[A-Za-z][A-Za-z0-9-]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>|<!--.*?-->|<\/?[A-Za-z][A-Za-z0-9-]*(?:\s+[^<>]*?)?\s*\/?>/s', function ($m) {
            return $this->hold($m[0]);
        }, $text));

        # 5. Backslash escapes
        $text = $this->orUnchanged($text, preg_replace_callback('/\\\\([!"#$%&\'()*+,.\/:;<=>?@\[\\\\\]^_`{|}~-])/', function ($m) {
            return $this->hold($this->esc($m[1]));
        }, $text));

        # 6. Images and links
        $text = $this->links($text);

        # 7. Bare URLs
        $text = $this->orUnchanged($text, preg_replace_callback('/(?<![\w\/="\'])(https?:\/\/[^\s<>\x1A]*[^\s<>\x1A.,:;!?\'")\]])/i', function ($m) {
            return $this->hold('<a href="' . $this->esc($m[1]) . '">' . $this->esc($m[1]) . '</a>');
        }, $text));

        # 8. Escape what is left, then emphasis
        $text = $this->escText($text);
        $text = $this->emphasis($text);

        # 9. Hard line breaks
        $text = str_replace("\x1B", "<br>", $text);
        return $text;
    }

    # Alt text and titles are plain text: hidden blocks (<inst>, <lock>, ...) are removed with
    # their content for everyone (an attribute can not be hidden later, L-56), other tags dropped.
    private function plainAttribute($text) {
        $text = $this->withoutHiddenBlocks($this->restore($text));
        return $this->esc(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    # Hidden blocks (<inst>, <lock>, ...) removed with their content, for text that ends up in an
    # attribute (alt, title, a heading's id), where they could not be hidden later.
    private function withoutHiddenBlocks($text) {
        # the stack-based block scanner, so nested blocks are removed whole (L-62)
        foreach (array('inst', 'lock', 'postmeeting', 'host', 'student') as $tag) {
            $text = PageRenderer::block($text, $tag, static function () { return ''; });
        }
        return $text;
    }

    private function links($text) {
        $pattern = '/(!?)\[((?:[^\[\]]|\[[^\[\]]*\])*)\]'
             . '(?:\(\s*(<[^>]*>|(?:[^()\s]|\([^()\s]*\))*)(?:\s+(?:"([^"]*)"|\'([^\']*)\'))?\s*\)'
             . '|\[([^\]]*)\])?/';
        return $this->orUnchanged($text, preg_replace_callback($pattern, function ($m) {
            $isImage = ($m[1] === '!');
            $label = $m[2];
            $url = null;
            $title = '';
            if (isset($m[3]) && ($m[3] !== '' || (isset($m[0]) && preg_match('/\]\(\s*\)$/', $m[0])))) {
                $url = $this->destination($m[3]);
                $title = isset($m[4]) && $m[4] !== '' ? $m[4] : (isset($m[5]) ? $m[5] : '');
            } else {
                $ref = (isset($m[6]) && $m[6] !== '') ? $m[6] : $label;
                $ref = strtolower(preg_replace('/\s+/', ' ', trim($ref)));
                if (isset($this->refs[$ref])) {
                    list($url, $title) = $this->refs[$ref];
                }
            }
            if ($url === null) {
                return $m[0]; # not a link after all
            }
            $titleAttr = ($title !== '') ? ' title="' . $this->plainAttribute($title) . '"' : '';
            if ($isImage) {
                $alt = $this->plainAttribute($label);
                return $this->hold('<img src="' . $this->esc($url) . '" alt="' . $alt . '"' . $titleAttr . '>');
            }
            $inner = $this->emphasis($this->escText($this->links($label)));
            return $this->hold('<a href="' . $this->esc($url) . '"' . $titleAttr . '>' . $inner . '</a>');
        }, $text));
    }

    # Emphasis spans are at most 1000 characters, so long runs of markers stay fast (L-37)
    private function emphasis($text) {
        $text = preg_replace('/~~(?=\S)(.{1,1000}?)(?<=\S)~~/s', '<del>$1</del>', $text) ?? $text;
        $text = preg_replace('/\*\*\*(?=\S)(.{1,1000}?)(?<=\S)\*\*\*/s', '<strong><em>$1</em></strong>', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9])___(?=\S)(.{1,1000}?)(?<=\S)___(?![A-Za-z0-9])/s', '<strong><em>$1</em></strong>', $text) ?? $text;
        $text = preg_replace('/\*\*(?=\S)(.{1,1000}?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9])__(?=\S)(.{1,1000}?)(?<=\S)__(?![A-Za-z0-9])/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![*\\\\])\*(?=[^\s*])(.{1,1000}?)(?<=[^\s*])\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z0-9_])_(?=[^\s_])(.{1,1000}?)(?<=[^\s_])_(?![A-Za-z0-9_])/s', '<em>$1</em>', $text) ?? $text;
        return $text;
    }

        /**
     * Convert Markdown to HTML.  $menuLevels lists the heading levels that get an
     * id (and therefore an entry in the navbar's section menu).
     *
     * @param list<int> $menuLevels
     */
    public static function convert(string $text, array $menuLevels = [2]): string
    {
        $markdown = new self();
        $markdown->menuLevels = array_map('intval', $menuLevels);
        return $markdown->text($text);
    }
}
