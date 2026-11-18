<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShopHeaven\Build\PackageIndex;

/**
 * Reads the API of PHP source with PHP's tokenizer (no autoloading, no external parser): every class, interface, trait
 * and enum with its parents, constants and methods (visibility, static, signature, docblock summary, deprecation), and
 * every function. Method bodies are skipped, except for a deprecation call at their top level. Shared by the package
 * index and the PrestaShop index.
 *
 * @phpstan-type Member array{name: string, visibility: string, static: bool, abstract: bool, final: bool, signature: string, summary: string, deprecated: ?string, line: int}
 * @phpstan-type Constant array{name: string, visibility: string, summary: string, deprecated: ?string, line: int}
 * @phpstan-type ClassInfo array{name: string, kind: string, line: int, final: bool, abstract: bool, summary: string, deprecated: ?string, extends: list<string>, implements: list<string>, traits: list<string>, constants: list<Constant>, methods: list<Member>}
 * @phpstan-type FunctionInfo array{name: string, signature: string, summary: string, deprecated: ?string, line: int}
 */
final class PhpApiScanner
{
    /** @var list<array{0: int, 1: string, 2: int}|string> */
    private array $tokens = [];

    private int $count = 0;

    private string $namespace = '';

    /** @var array<string, string> lowercase alias => fully qualified name */
    private array $uses = [];

    /**
     * The tokens of PHP source; falls back to tokenizing without TOKEN_PARSE when the code does not parse.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    public static function tokens(string $code): array
    {
        try {
            return token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError) {
            return token_get_all($code);
        }
    }

    /**
     * Scans PHP source.
     *
     * @return array{classes: list<ClassInfo>, functions: list<FunctionInfo>}
     */
    public function scan(string $code): array
    {
        return $this->scanTokens(self::tokens($code));
    }

    /**
     * Scans the tokens of one PHP file (from tokens()).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{classes: list<ClassInfo>, functions: list<FunctionInfo>}
     */
    public function scanTokens(array $tokens): array
    {
        $this->tokens = $tokens;
        $this->count = count($tokens);
        $this->namespace = '';
        $this->uses = [];
        $classes = [];
        $functions = [];
        $doc = null;
        $modifiers = [];
        $fileDeprecated = false;

        for ($i = 0; $i < $this->count; ++$i) {
            $t = $this->tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            if ($id === T_DOC_COMMENT) {
                $doc = $t[1];
                continue;
            }
            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }
            if ($id === T_ATTRIBUTE) {
                $i = $this->skipBalanced($i, '[', ']');
                continue;
            }
            if ($id === T_NAMESPACE) {
                $next = $this->nextSignificant($i);
                [$this->namespace, $i] = $next !== null && $this->isNameToken($this->tokens[$next]) ? $this->readName($next) : ['', $i];
                $this->uses = [];
                $doc = null;
                continue;
            }
            if ($id === T_USE) {
                $i = $this->readImports($i);
                $doc = null;
                continue;
            }
            if ($id === T_ABSTRACT || $id === T_FINAL || $id === T_READONLY) {
                $modifiers[] = $id;
                continue;
            }
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $prev = $this->previousSignificant($i);
                $prevId = $prev === null ? null : (is_array($this->tokens[$prev]) ? $this->tokens[$prev][0] : null);
                if ($prevId === T_DOUBLE_COLON) {
                    continue; // Foo::class
                }
                $next = $this->nextSignificant($i);
                if ($prevId === T_NEW || $next === null || !is_array($this->tokens[$next]) || $this->tokens[$next][0] !== T_STRING) {
                    $i = $this->skipToBlockEnd($i); // anonymous class
                    $doc = null;
                    $modifiers = [];
                    continue;
                }
                [$class, $i] = $this->readClass($i, (string) $id, $doc, $modifiers);
                $classes[] = $class;
                $doc = null;
                $modifiers = [];
                continue;
            }
            if ($id === T_FUNCTION) {
                $next = $this->nextSignificant($i);
                if ($next !== null && $this->isAmpersand($next)) {
                    $next = $this->nextSignificant($next);
                }
                if ($next !== null && is_array($this->tokens[$next]) && $this->tokens[$next][0] === T_STRING) {
                    [$signature, $end, $body] = $this->readSignature($next);
                    $functions[] = [
                        'name' => $this->qualify($this->tokens[$next][1]),
                        'signature' => $signature,
                        'summary' => Docblock::summary((string) $doc),
                        'deprecated' => Docblock::deprecation((string) $doc) ?? ($body !== null && $this->bodyDeprecates($body[0], $body[1]) ? 'trigger_deprecation' : null),
                        'line' => $this->tokens[$next][2],
                    ];
                    $i = $end;
                }
                $doc = null;
                continue;
            }
            if ($id === T_STRING && $this->isDeprecationCall($i)) {
                $fileDeprecated = true;
            }
            if ($t === ';' || $t === '{' || $t === '}') {
                $doc = null;
                $modifiers = [];
            }
        }
        if ($fileDeprecated) {
            foreach ($classes as $n => $class) {
                $classes[$n]['deprecated'] ??= 'trigger_error at the top of the file';
            }
        }

        return ['classes' => $classes, 'functions' => $functions];
    }

    /**
     * Reads a class-like declaration starting at its keyword; returns the class and the index of its closing brace.
     *
     * @param list<int> $modifiers
     *
     * @return array{0: ClassInfo, 1: int}
     */
    private function readClass(int $i, string $keyword, ?string $doc, array $modifiers): array
    {
        $kinds = [(string) T_CLASS => 'class', (string) T_INTERFACE => 'interface', (string) T_TRAIT => 'trait', (string) T_ENUM => 'enum'];
        $nameIndex = (int) $this->nextSignificant($i);
        $nameToken = $this->tokens[$nameIndex];
        $class = [
            'name' => $this->qualify(is_array($nameToken) ? $nameToken[1] : ''),
            'kind' => $kinds[$keyword],
            'line' => is_array($nameToken) ? $nameToken[2] : 0,
            'final' => in_array(T_FINAL, $modifiers, true),
            'abstract' => in_array(T_ABSTRACT, $modifiers, true),
            'summary' => Docblock::summary((string) $doc),
            'deprecated' => Docblock::deprecation((string) $doc),
            'extends' => [],
            'implements' => [],
            'traits' => [],
            'constants' => [],
            'methods' => [],
        ];
        $list = null;
        for ($i = $nameIndex + 1; $i < $this->count && $this->tokens[$i] !== '{'; ++$i) {
            $t = $this->tokens[$i];
            if (is_array($t) && $t[0] === T_EXTENDS) {
                $list = 'extends';
            } elseif (is_array($t) && $t[0] === T_IMPLEMENTS) {
                $list = 'implements';
            } elseif ($list !== null && $this->isNameToken($t)) {
                [$name, $i] = $this->readName($i);
                $class[$list][] = $this->resolve($name);
            }
        }
        $doc = null;
        $memberModifiers = [];
        for (++$i; $i < $this->count; ++$i) {
            $t = $this->tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            if ($t === '}') {
                break;
            }
            if ($id === T_DOC_COMMENT) {
                $doc = $t[1];
            } elseif ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            } elseif ($id === T_ATTRIBUTE) {
                $i = $this->skipBalanced($i, '[', ']');
            } elseif (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR], true)) {
                $memberModifiers[] = $id;
            } elseif ($id === T_USE) {
                $i = $this->readTraitUse($i, $class['traits']);
                $doc = null;
                $memberModifiers = [];
            } elseif ($id === T_CONST || $id === T_CASE) {
                $i = $this->readConstants($i, $class['constants'], $doc, $memberModifiers);
                $doc = null;
                $memberModifiers = [];
            } elseif ($id === T_FUNCTION) {
                $i = $this->readMethod($i, $class['methods'], $doc, $memberModifiers, $class['kind'] === 'interface');
                $doc = null;
                $memberModifiers = [];
            } elseif ($t === ';' || $t === '{') {
                $i = $t === '{' ? $this->skipBalanced($i, '{', '}') : $i;
                $doc = null;
                $memberModifiers = [];
            }
        }

        return [$class, $i];
    }

    /**
     * Reads one method; returns the index of its last token.
     *
     * @param list<Member> $methods
     * @param list<int> $modifiers
     */
    private function readMethod(int $i, array &$methods, ?string $doc, array $modifiers, bool $inInterface): int
    {
        $next = $this->nextSignificant($i);
        if ($next !== null && $this->isAmpersand($next)) {
            $next = $this->nextSignificant($next);
        }
        if ($next === null || !is_array($this->tokens[$next])) {
            return $i;
        }
        [$signature, $end, $body] = $this->readSignature($next);
        $visibility = in_array(T_PRIVATE, $modifiers, true) ? 'private' : (in_array(T_PROTECTED, $modifiers, true) ? 'protected' : 'public');
        $methods[] = [
            'name' => $this->tokens[$next][1],
            'visibility' => $visibility,
            'static' => in_array(T_STATIC, $modifiers, true),
            'abstract' => $inInterface || in_array(T_ABSTRACT, $modifiers, true),
            'final' => in_array(T_FINAL, $modifiers, true),
            'signature' => $signature,
            'summary' => Docblock::summary((string) $doc),
            'deprecated' => Docblock::deprecation((string) $doc) ?? ($body !== null && $this->bodyDeprecates($body[0], $body[1]) ? 'trigger_deprecation' : null),
            'line' => $this->tokens[$next][2],
        ];

        return $end;
    }

    /**
     * Reads the signature that starts after a function name: "(params): type". Returns the signature, the index of the
     * last token (the closing brace of the body or the ";"), and the body's brace indexes (null without a body).
     *
     * @return array{0: string, 1: int, 2: array{0: int, 1: int}|null}
     */
    private function readSignature(int $nameIndex): array
    {
        $open = $nameIndex + 1;
        while ($open < $this->count && $this->tokens[$open] !== '(') {
            ++$open;
        }
        $close = $this->skipBalanced($open, '(', ')');
        $params = rtrim($this->text($open + 1, $close - 1), ', ');
        $return = '';
        $i = $close + 1;
        for (; $i < $this->count && $this->tokens[$i] !== '{' && $this->tokens[$i] !== ';'; ++$i) {
            $return .= is_array($this->tokens[$i]) ? $this->tokens[$i][1] : $this->tokens[$i];
        }
        $return = trim((string) preg_replace('/\s+/', ' ', $return), " \n\r\t\v\0");
        $signature = '(' . $params . ')' . ($return !== '' ? ' ' . ltrim($return, " \n\r\t\v\0") : '');
        $signature = (string) preg_replace('/\s+:/', ':', $signature);
        if ($i >= $this->count || $this->tokens[$i] === ';') {
            return [$signature, min($i, $this->count - 1), null];
        }
        $end = $this->skipBalanced($i, '{', '}');

        return [$signature, $end, [$i, $end]];
    }

    /**
     * The normalised source between two token indexes (inclusive): comments and attributes left out, whitespace
     * collapsed, no space inside the brackets.
     */
    private function text(int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; ++$i) {
            $t = $this->tokens[$i];
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $i = $this->skipBalanced($i, '[', ']');
                continue;
            }
            $out .= is_array($t) ? ($t[0] === T_WHITESPACE ? ' ' : $t[1]) : $t;
        }
        $out = trim((string) preg_replace('/\s+/', ' ', $out), " \n\r\t\v\0");

        return (string) preg_replace(['/\(\s+/', '/\s+\)/', '/\[\s+/', '/\s+\]/', '/\s+,/'], ['(', ')', '[', ']', ','], $out);
    }

    /**
     * True when a body (its brace indexes) calls trigger_deprecation() or trigger_error(..., E_USER_DEPRECATED) at its
     * top level (a call inside an if only deprecates one way of calling it).
     */
    private function bodyDeprecates(int $open, int $close): bool
    {
        $depth = 0;
        for ($i = $open; $i <= $close; ++$i) {
            $t = $this->tokens[$i];
            if ($t === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
                ++$depth;
            } elseif ($t === '}') {
                --$depth;
            } elseif ($depth === 1 && is_array($t) && $t[0] === T_STRING && $this->isDeprecationCall($i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the T_STRING at $i is trigger_deprecation( or a trigger_error( whose statement holds E_USER_DEPRECATED.
     */
    private function isDeprecationCall(int $i): bool
    {
        $name = strtolower((string) $this->tokens[$i][1]);
        if ($name !== 'trigger_deprecation' && $name !== 'trigger_error') {
            return false;
        }
        $next = $this->nextSignificant($i);
        if ($next === null || $this->tokens[$next] !== '(') {
            return false;
        }
        if ($name === 'trigger_deprecation') {
            return true;
        }
        $close = $this->skipBalanced($next, '(', ')');
        for ($j = $next; $j <= $close; ++$j) {
            if (is_array($this->tokens[$j]) && $this->tokens[$j][1] === 'E_USER_DEPRECATED') {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads "const A = 1, B = 2;" or an enum "case A = 'a';"; returns the index of the ";".
     *
     * @param list<Constant> $constants
     * @param list<int> $modifiers
     */
    private function readConstants(int $i, array &$constants, ?string $doc, array $modifiers): int
    {
        $visibility = in_array(T_PRIVATE, $modifiers, true) ? 'private' : (in_array(T_PROTECTED, $modifiers, true) ? 'protected' : 'public');
        $depth = 0;
        $expectName = true;
        for (++$i; $i < $this->count; ++$i) {
            $t = $this->tokens[$i];
            if ($t === '(' || $t === '[' || $t === '{') {
                ++$depth;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                --$depth;
            } elseif ($depth === 0 && $t === ';') {
                break;
            } elseif ($depth === 0 && $t === ',') {
                $expectName = true;
            } elseif ($expectName && is_array($t) && $t[0] === T_STRING) {
                $next = $this->nextSignificant($i);
                if ($next === null || $this->tokens[$next] === '=' || $this->tokens[$next] === ';') {
                    $constants[] = ['name' => $t[1], 'visibility' => $visibility, 'summary' => Docblock::summary((string) $doc), 'deprecated' => Docblock::deprecation((string) $doc), 'line' => $t[2]];
                    $expectName = false;
                }
            }
        }

        return $i;
    }

    /**
     * Reads "use A, B { ... }" inside a class; returns the index of its ";" or closing brace.
     *
     * @param list<string> $traits
     */
    private function readTraitUse(int $i, array &$traits): int
    {
        for (++$i; $i < $this->count; ++$i) {
            $t = $this->tokens[$i];
            if ($t === ';') {
                return $i;
            }
            if ($t === '{') {
                return $this->skipBalanced($i, '{', '}');
            }
            if ($this->isNameToken($t)) {
                [$name, $i] = $this->readName($i);
                $traits[] = $this->resolve($name);
            }
        }

        return $i;
    }

    /**
     * Reads a top-level "use" statement into the import map (classes only); returns the index of its ";".
     */
    private function readImports(int $i): int
    {
        $prefix = '';
        $current = '';
        $alias = null;
        $skip = false;
        $add = function () use (&$current, &$alias, &$prefix, &$skip): void {
            if ($current !== '' && !$skip) {
                $full = ltrim($prefix . $current, '\\');
                $this->uses[strtolower($alias ?? substr($full, (int) strrpos('\\' . $full, '\\')))] = $full;
            }
            $current = '';
            $alias = null;
        };
        for (++$i; $i < $this->count; ++$i) {
            $t = $this->tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            if ($t === ';') {
                $add();

                return $i;
            }
            if ($id === T_FUNCTION || $id === T_CONST) {
                $skip = true;
            } elseif ($t === '{') {
                $prefix = rtrim($current, '\\') . '\\';
                $current = '';
            } elseif ($t === ',' || $t === '}') {
                $add();
            } elseif ($id === T_AS) {
                $next = (int) $this->nextSignificant($i);
                $alias = is_array($this->tokens[$next]) ? $this->tokens[$next][1] : null;
                $i = $next;
            } elseif ($id === T_NS_SEPARATOR) {
                $current .= '\\';
            } elseif ($this->isNameToken($t)) {
                $current .= $t[1];
            }
        }

        return $i;
    }

    /**
     * Reads a (possibly qualified, pre-PHP-8 style) name starting at $i; returns it and the index of its last token.
     *
     * @return array{0: string, 1: int}
     */
    private function readName(int $i): array
    {
        $name = '';
        for (; $i < $this->count; ++$i) {
            $t = $this->tokens[$i];
            if (is_array($t) && ($t[0] === T_NS_SEPARATOR || $this->isNameToken($t))) {
                $name .= $t[1];
            } else {
                break;
            }
        }

        return [$name, $i - 1];
    }

    /**
     * True for a token that is (part of) a class name.
     *
     * @param array{0: int, 1: string, 2: int}|string $t
     *
     * @phpstan-assert-if-true array{0: int, 1: string, 2: int} $t
     */
    private function isNameToken(array|string $t): bool
    {
        return is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    /**
     * A name declared in the current namespace, fully qualified.
     */
    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    /**
     * Resolves a class name as written against the imports and the namespace.
     */
    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $this->qualify(substr($name, 10));
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($this->uses[$first])) {
            $rest = substr($name, strlen($first));

            return $this->uses[$first] . $rest;
        }

        return $this->qualify($name);
    }

    /**
     * The index of the token that closes the bracket opened at $i (or the last token when it never closes).
     */
    private function skipBalanced(int $i, string $open, string $close): int
    {
        $depth = 0;
        for (; $i < $this->count; ++$i) {
            $t = $this->tokens[$i];
            $text = is_array($t) ? $t[1] : $t;
            if ($text === $open || ($open === '[' && $text === '#[') || ($open === '{' && $text === '${')) {
                ++$depth;
            } elseif ($text === $close && --$depth === 0) {
                return $i;
            }
        }

        return $this->count - 1;
    }

    /**
     * Skips from $i to the end of the next block (an anonymous class); returns the index of its closing brace.
     */
    private function skipToBlockEnd(int $i): int
    {
        while ($i < $this->count && $this->tokens[$i] !== '{') {
            if ($this->tokens[$i] === '(') {
                $i = $this->skipBalanced($i, '(', ')');
            }
            ++$i;
        }

        return $this->skipBalanced($i, '{', '}');
    }

    /**
     * The index of the next token that is not whitespace or a comment, or null.
     */
    private function nextSignificant(int $i): ?int
    {
        ++$i;
        while ($i < $this->count && $this->isInsignificant($i)) {
            ++$i;
        }

        return $i < $this->count ? $i : null;
    }

    /**
     * The index of the previous token that is not whitespace or a comment, or null.
     */
    private function previousSignificant(int $i): ?int
    {
        --$i;
        while ($i >= 0 && $this->isInsignificant($i)) {
            --$i;
        }

        return $i >= 0 ? $i : null;
    }

    /**
     * True when the token at $i is "&" (a by-reference function).
     */
    private function isAmpersand(int $i): bool
    {
        $t = $this->tokens[$i];

        return (is_array($t) ? $t[1] : $t) === '&';
    }

    /**
     * True for whitespace and comments.
     */
    private function isInsignificant(int $i): bool
    {
        $t = $this->tokens[$i];

        return is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
