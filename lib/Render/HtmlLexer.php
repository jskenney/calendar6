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

    /** The end of a closing tag: the first ">". */
    private static function closingTagEnd(string $html, int $i): int
    {
        $close = strpos($html, '>', $i);
        return $close === false ? strlen($html) : $close + 1;
    }

    /** @return list<array{0: string, 1: int, 2: int, 3?: string, 4?: bool}> */
    public static function scan(string $html): array
    {
        $tokens = [];
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
                    $close = strpos($html, '-->', $lt + 4);
                    $end = $close === false ? $length : $close + 3;
                }
                $tokens[] = ['comment', $lt, $end];
                $pos = $end;
                continue;
            }
            if (substr_compare($html, '<![CDATA[', $lt, 9, true) === 0) {
                // a browser ends this at the first ">" and shows the rest; removing the whole
                // section up to "]]>" fails closed instead (L-36)
                $close = strpos($html, ']]>', $lt + 9);
                $end = $close === false ? $length : $close + 3;
                $tokens[] = ['comment', $lt, $end];
                $pos = $end;
                continue;
            }
            if ($next === '!' || $next === '?') {         // doctype, processing instruction
                $close = strpos($html, '>', $lt + 2);
                $end = $close === false ? $length : $close + 1;
                $tokens[] = ['comment', $lt, $end];
                $pos = $end;
                continue;
            }
            $closing = $next === '/';
            $nameStart = $lt + ($closing ? 2 : 1);
            if (!ctype_alpha($html[$nameStart] ?? '')) {
                $pos = $lt + 1;                          // text
                continue;
            }
            $nameEnd = $nameStart + strspn($html, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_:.', $nameStart);
            $name = strtolower(substr($html, $nameStart, $nameEnd - $nameStart));
            $end = $closing ? self::closingTagEnd($html, $nameEnd) : self::openingTagEnd($html, $nameEnd);
            $tokens[] = ['tag', $lt, $end, $name, $closing];
            $pos = $end;
            if (!$closing && ($name === 'script' || $name === 'style')) {
                $close = $end;
                while (($close = stripos($html, '</' . $name, $close)) !== false) {
                    $after = $html[$close + strlen($name) + 2] ?? '';
                    if ($after === '' || $after === '>' || $after === '/' || ctype_space($after)) {
                        break;
                    }
                    $close += 2;
                }
                if ($close !== false) {
                    $tokens[] = ['raw', $end, $close];
                    $pos = $close;
                }
                // never closed (a typo such as </stlye>): the rest is read as ordinary markup,
                // so hidden blocks after it are still found and removed (fail closed, M-27)
            }
        }
        return $tokens;
    }
}
