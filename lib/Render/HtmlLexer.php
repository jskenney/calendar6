<?php

declare(strict_types=1);

namespace Cal6\Render;

/**
 * A small, linear HTML lexer that follows the browser's rules where they matter for
 * hiding content (L-36): it skips quoted attribute values (so "<!--", "<script>" or
 * "</inst>" inside an attribute are not markup), ends abrupt comments ("<!-->",
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
            $i = $nameEnd;
            while ($i < $length) {
                $skip = strcspn($html, $closing ? '>' : '>"\'', $i);
                $i += $skip;
                if ($i >= $length || $html[$i] === '>') {
                    break;
                }
                $quote = strpos($html, $html[$i], $i + 1);  // a quoted value: "<" and ">" inside are text
                $i = $quote === false ? $length : $quote + 1;
            }
            $end = $i < $length ? $i + 1 : $length;
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
                $rawEnd = $close === false ? $length : $close;
                $tokens[] = ['raw', $end, $rawEnd];
                $pos = $rawEnd;
            }
        }
        return $tokens;
    }
}
