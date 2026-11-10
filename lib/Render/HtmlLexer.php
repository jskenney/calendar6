<?php

declare(strict_types=1);

namespace Cal6\Render;

/**
 * A small, linear HTML lexer that follows the browser's rules where they matter for
 * hiding content (L-36, H-5, M-27): it reads attributes as a browser does (a quote starts a
 * value only after "=", so "<!--", "<script>" or "</inst>" inside a quoted value are not
 * markup, and a stray quote elsewhere is just a character), ends abrupt comments ("<!-->",
 * "<!--->") where a browser does, treats "<!...>" and "<?...>" as a bogus comment up to
 * the first ">" (a CDATA section, which a browser would end there and show the rest of, is
 * one comment up to "]]>", so it is removed whole), and only treats <script> and <style> (the whole
 * tag name) as raw text up to their closing tag.
 *
 * Tokens: ['tag', start, end, lower-case name, closing], ['comment', start, end],
 * ['raw', start, end] (the body of a script or style element).
 */
final class HtmlLexer
{
    /**
     * The end of an opening tag, reading attributes as a browser does (H-5): a quote starts a
     * quoted value only right after "=" (and spaces); anywhere else it is part of an
     * attribute name or an unquoted value, and the tag ends at the first ">" outside a quoted
     * value.
     */
    /**
     * Where a bogus comment (<!DOCTYPE ...>, <![CDATA[ ...>, <? ...>, </1 ...>) ends: a browser
     * ends it at the first ">", even one inside a tag written in it (<![CDATA[ <inst>S</inst>
     * shows S); the lexer ends it before an opening tag instead, so that tag still counts, and
     * lets it swallow a closing tag as the browser does: hidden under both readings (L-84).
     */
    private static function bogusEnd(string $html, int $from): int
    {
        $gt = strpos($html, '>', $from);
        $lt = strpos($html, '<', $from);
        // an opening tag inside still counts (fail closed); a closing tag the browser swallows
        // does not, as in the browser
        if ($lt !== false && ($gt === false || $lt < $gt) && ctype_alpha($html[$lt + 1] ?? '')) {
            return $lt;
        }
        return $gt === false ? strlen($html) : $gt + 1;
    }

    /** Where a comment whose text starts at $from ends: after "-->" or "--!>", as in a browser (M-42). */
    public static function commentEnd(string $html, int $from): int
    {
        $a = strpos($html, '-->', $from);
        $b = strpos($html, '--!>', $from);
        if ($a === false && $b === false) {
            return strlen($html);
        }
        if ($b === false || ($a !== false && $a < $b)) {
            return $a + 3;
        }
        return $b + 4;
    }

    /** The end of the tag that starts at $lt (its name included), read with the browser's quote rules. */
    public static function tagEnd(string $html, int $lt): int
    {
        $nameStart = $lt + (($html[$lt + 1] ?? '') === '/' ? 2 : 1);
        return self::openingTagEnd($html, $nameStart + strcspn($html, " \t\n\r\f/>", $nameStart));
    }

    private static function openingTagEnd(string $html, int $i): int
    {
        $length = strlen($html);
        while ($i < $length) {
            $i += strspn($html, " \t\n\r\f/", $i);                    // before an attribute name
            if ($i >= $length || $html[$i] === '>') {
                break;
            }
            $i += max(1, strcspn($html, " \t\n\r\f/>=", $i));         // the name (quotes included)
            $i += strspn($html, " \t\n\r\f", $i);
            if (($html[$i] ?? '') !== '=') {
                continue;                                              // no value
            }
            $i++;
            $i += strspn($html, " \t\n\r\f", $i);
            $quote = $html[$i] ?? '';
            if ($quote === '"' || $quote === "'") {
                $close = strpos($html, $quote, $i + 1);
                $i = $close === false ? $length : $close + 1;           // unterminated: to the end, as a browser
            } else {
                $i += strcspn($html, " \t\n\r\f>", $i);               // an unquoted value
            }
        }
        return $i < $length ? $i + 1 : $length;
    }

    /**
     * Where the body of a <script> or <style> that starts at $start ends (the "<" of its closing
     * tag), or false.  A script follows the browser's escaped states (L-52): after "<!--", a
     * "<script" makes the next "</script>" part of the script ("double escaped") until "-->".
     */
    private static function rawEnd(string $html, int $start, string $name): int|false
    {
        // HTML whitespace only (not a vertical tab), L-59
        $pattern = $name === 'script' ? '/<\/script(?=[\t\n\f\r \/>]|$)|<!--|<script(?=[\t\n\f\r \/>])|-->/i' : '/<\/' . $name . '(?=[\t\n\f\r \/>]|$)/i';
        $state = 'data';
        $pos = $start;
        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            [$found, $at] = $m[0];
            $pos = $at + strlen($found);
            $token = strtolower($found);
            if ($token[1] === '/') {                     // </script or </style
                if ($state !== 'double') {
                    return $at;
                }
                $state = 'escaped';
            } elseif ($token === '<!--') {
                // "<!-->" and "<!--->" end in "-->": back to plain script data, as in a
                // browser (L-59); otherwise "<!--" starts the escaped state
                $abrupt = ($html[$pos] ?? '') === '>' || substr($html, $pos, 2) === '->';
                if ($abrupt) {
                    $state = 'data';
                    $pos += ($html[$pos] === '>') ? 1 : 2;
                } elseif ($state === 'data') {
                    $state = 'escaped';
                }
            } elseif ($token === '-->') {
                $state = 'data';
            } elseif ($state === 'escaped') {            // <script inside an escaped script
                $state = 'double';
            }
        }
        return false;
    }

    /**
     * The attributes of an opening tag's text, read as openingTagEnd() reads them:
     * list of [lower-case name, value start, value end, quoted, name start, end], positions in
     * $tag (a value start of -1: no value; end is after the closing quote).
     *
     * @return list<array{0: string, 1: int, 2: int, 3: bool, 4: int, 5: int}>
     */
    public static function attributes(string $tag): array
    {
        $length = strlen($tag);
        $i = 1 + strcspn($tag, " \t\n\r\f/>", 1);
        $attributes = [];
        while ($i < $length) {
            $i += strspn($tag, " \t\n\r\f/", $i);
            if ($i >= $length || $tag[$i] === '>') {
                break;
            }
            $nameStart = $i;
            $nameLength = max(1, strcspn($tag, " \t\n\r\f/>=", $i));
            $name = strtolower(substr($tag, $i, $nameLength));
            $i += $nameLength;
            $afterName = $i;
            $i += strspn($tag, " \t\n\r\f", $i);
            if (($tag[$i] ?? '') !== '=') {
                $attributes[] = [$name, -1, -1, false, $nameStart, $afterName];
                continue;
            }
            $i++;
            $i += strspn($tag, " \t\n\r\f", $i);
            $quote = $tag[$i] ?? '';
            if ($quote === '"' || $quote === "'") {
                $close = strpos($tag, $quote, $i + 1);
                $end = $close === false ? $length : $close;
                $attributes[] = [$name, $i + 1, $end, true, $nameStart, min($length, $end + 1)];
                $i = $end + 1;
            } else {
                $end = $i + strcspn($tag, " \t\n\r\f>", $i);
                $attributes[] = [$name, $i, $end, false, $nameStart, $end];
                $i = $end;
            }
        }
        return $attributes;
    }

    /**
     * Elements whose content a browser reads as text up to their closing tag, even one inside
     * what looks like a quoted attribute (RCDATA and RAWTEXT, M-37).
     */
    private const BOUNDED = ['textarea', 'title', 'noscript', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'];

    /** Elements inside <svg>/<math> whose content a browser reads as HTML. */
    private const INTEGRATION = ['foreignobject', 'desc', 'title', 'mi', 'mo', 'mn', 'ms', 'mtext', 'annotation-xml'];

    /** Tags that end <svg>/<math> foreign content in a browser. */
    private const BREAKOUT = ['b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre',
        'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var'];

    /**
     * Tokens: ['tag', start, end, name, closing], ['comment', start, end], ['raw', start, end]
     * (a script or style body) and ['bounded', start, end, name] (the body of an element in
     * BOUNDED: it ends where a browser ends it, and its content is also lexed as markup, so a
     * block tag inside is still hidden: fail closed under both readings, M-37).
     *
     * @return list<array{0: string, 1: int, 2: int, 3?: string, 4?: bool}>
     */
    public static function scan(string $html, bool $bounded = true): array
    {
        return self::scanRange($html, !$bounded, false);
    }

    /**
     * $plain: no bounded elements (the reading of a <title> inside <svg>, where it is markup);
     * $body: the content of a bounded element (no script or style raw text either).
     *
     * @return list<array{0: string, 1: int, 2: int, 3?: string, 4?: bool}>
     */
    private static function scanRange(string $html, bool $plain, bool $body): array
    {
        $tokens = [];
        $noClose = [];
        $foreign = 0;
        $integration = 0;
        $length = strlen($html);
        $pos = 0;
        while (($lt = strpos($html, '<', $pos)) !== false) {
            $next = $html[$lt + 1] ?? '';
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                if (($html[$lt + 4] ?? '') === '>') {
                    $end = $lt + 5;                      // <!-->
                } elseif (substr_compare($html, '->', $lt + 4, 2) === 0) {
                    $end = $lt + 6;                      // <!--->
                } else {
                    $end = self::commentEnd($html, $lt + 4);
                }
                $tokens[] = ['comment', $lt, $end];
                $pos = $end;
                continue;
            }
            if ($foreign > 0 && substr_compare($html, '<![CDATA[', $lt, 9, true) === 0) {
                // a real CDATA section only inside <svg> or <math>; elsewhere a browser reads it
                // as a bogus comment up to the first ">" (below), and so does the lexer (L-84)
                $close = strpos($html, ']]>', $lt + 9);
                $end = $close === false ? $length : $close + 3;
                $tokens[] = ['comment', $lt, $end];
                $pos = $end;
                continue;
            }
            if ($next === '!' || $next === '?') {         // doctype, processing instruction, CDATA
                $end = self::bogusEnd($html, $lt + 2);
                $tokens[] = ['comment', $lt, $end];
                $pos = $end;
                continue;
            }
            $closing = $next === '/';
            $nameStart = $lt + ($closing ? 2 : 1);
            if (!ctype_alpha($html[$nameStart] ?? '')) {
                if ($closing && $nameStart < $length) {
                    // "</" and a non-letter: a bogus comment up to ">", as in a browser (L-68)
                    $end = self::bogusEnd($html, $nameStart);
                    $tokens[] = ['comment', $lt, $end];
                    $pos = $end;
                    continue;
                }
                $pos = $lt + 1;                          // text
                continue;
            }
            // a tag name runs to whitespace, "/" or ">", as in a browser (L-59)
            $nameEnd = $nameStart + strcspn($html, " \t\n\r\f/>", $nameStart);
            $name = strtolower(substr($html, $nameStart, $nameEnd - $nameStart));
            // an end tag's attributes are read like a start tag's, quotes and all (L-68)
            $end = self::openingTagEnd($html, $nameEnd);
            $tokens[] = ['tag', $lt, $end, $name, $closing];
            $pos = $end;
            // inside <svg> or <math> a browser reads <script> and <style> as markup (they end at
            // </svg> or a tag such as <p>); read them as bounded, both ways (L-77)
            // (inside an integration point such as <foreignObject> or <mi> content is HTML again:
            // breakout tags do not end the foreign content there, and script/style are raw, L-88)
            $selfClosing = str_ends_with(substr($html, $lt, $end - $lt), '/>');
            $inForeign = $foreign > 0 && $integration === 0; // where this tag itself stands
            if ($name === 'svg' || $name === 'math') {
                if ($integration === 0) {
                    $foreign = $closing ? max(0, $foreign - 1) : $foreign + ($selfClosing ? 0 : 1);
                }
            } elseif ($foreign > 0 && in_array($name, self::INTEGRATION, true) && !$selfClosing) {
                $integration = $closing ? max(0, $integration - 1) : $integration + 1;
            } elseif (!$closing && $foreign > 0 && $integration === 0 && in_array($name, self::BREAKOUT, true)) {
                $foreign = 0;
            }
            $asBounded = !$closing && !$plain && (in_array($name, self::BOUNDED, true) || ($inForeign && ($name === 'script' || $name === 'style')));
            if ($asBounded) {
                $close = $name === 'plaintext' || ($noClose[$name] ?? PHP_INT_MAX) <= $end ? false : self::rawEnd($html, $end, $name);
                if ($close === false) {
                    $noClose[$name] = min($noClose[$name] ?? PHP_INT_MAX, $end);
                    $close = $length;
                }
                $tokens[] = ['bounded', $end, $close, $name, $inForeign];
                foreach (self::scanRange(substr($html, $end, $close - $end), true, true) as $inner) {
                    $inner[1] += $end;
                    $inner[2] += $end;
                    $tokens[] = $inner;
                }
                $pos = $close;
                continue;
            }
            if (!$closing && !$body && ($name === 'script' || $name === 'style')) {
                $close = ($noClose[$name] ?? PHP_INT_MAX) <= $end ? false : self::rawEnd($html, $end, $name);
                if ($close !== false) {
                    $tokens[] = ['raw', $end, $close];
                    $pos = $close;
                } else {
                    // never closed (a typo such as </stlye>): the rest is read as ordinary markup,
                    // so hidden blocks after it are still found and removed (fail closed, M-27);
                    // no later element of this name is closed either, so it is not searched
                    // for again (linear, L-55)
                    $noClose[$name] = min($noClose[$name] ?? PHP_INT_MAX, $end);
                }
            }
        }
        return $tokens;
    }
}
