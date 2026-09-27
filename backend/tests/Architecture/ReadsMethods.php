<?php

declare(strict_types=1);

namespace Tests\Architecture;

/**
 * Reading PHP the way an architecture rule must read it.
 *
 * Every rule in this directory that examines *what a method does* needs two
 * things: the file's code without its prose, and the file's methods. Both were
 * being written per rule, and both have a wrong version that looks right.
 *
 * ## Comments are removed first
 *
 * `TC-037` ‡ rule 11 was corrected for this reason and the correction belongs
 * everywhere: a rule that reads a file's text flags the docblock explaining the
 * prohibition, and the obvious way to make it pass is to delete the explanation.
 * So a detector reads {@see codeOf()}, never the file.
 *
 * ## A method's body is found by brace depth, not by indentation
 *
 * The obvious pattern — match a signature, then take everything up to the next
 * closing brace at four spaces — **silently loses methods**. A promoted
 * constructor ends `) {}` on one line, so the pattern runs straight past it and
 * consumes the method that follows. That method is then attributed to
 * `__construct`, discarded with it, and never examined. The rule still passes, and
 * reports on a file in which the operations it exists to check are invisible.
 *
 * That is not hypothetical: it is what `API-002` ‡ did. Every controller in the
 * platform declares a promoted constructor, so five of the eleven operations were
 * never read — including all three whose single operation is their first. `CC-053`
 * records it. `TC-024` ‡ says a false result is fixed rather than worked around,
 * and this trait is the fix, shared so that the next rule inherits it.
 */
trait ReadsMethods
{
    /**
     * A file's PHP with every comment removed.
     */
    private static function codeOf(string $contents): string
    {
        if (! str_contains($contents, '<?php')) {
            $contents = '<?php '.$contents;
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * Every method of a file, keyed by name.
     *
     * @return array<string, string> name => body
     */
    private static function methodsIn(string $code): array
    {
        $bodies = [];

        foreach (self::declaredMethods($code) as $name => $method) {
            $bodies[$name] = $method['body'];
        }

        return $bodies;
    }

    /**
     * Every **public** method of a file except its constructor.
     *
     * What a rule about operations wants: a private helper is not an operation, and
     * a constructor is not one either.
     *
     * @return array<string, string> name => body
     */
    private static function publicMethodsIn(string $code): array
    {
        $bodies = [];

        foreach (self::declaredMethods($code) as $name => $method) {
            if ($method['visibility'] !== 'public' || $name === '__construct') {
                continue;
            }

            $bodies[$name] = $method['body'];
        }

        return $bodies;
    }

    /**
     * Every named method, with the visibility it was declared at.
     *
     * A method with no body — an interface or abstract declaration — is absent
     * rather than present and empty, because there is nothing in it to examine.
     * A closure is part of whatever method encloses it and is never its own entry.
     *
     * @return array<string, array{visibility: string, body: string}>
     */
    private static function declaredMethods(string $code): array
    {
        /** @var array<int, array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = token_get_all($code);
        $total = count($tokens);
        $methods = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = self::nameAfter($tokens, $i, $total);

            if ($name === null) {
                continue;
            }

            $body = self::bodyFrom($tokens, $i, $total);

            if ($body === null) {
                continue;
            }

            $methods[$name] = [
                'visibility' => self::visibilityBefore($tokens, $i),
                'body' => $body,
            ];
        }

        return $methods;
    }

    /**
     * The method's name, or `null` for a closure.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nameAfter(array $tokens, int $from, int $total): ?string
    {
        for ($j = $from + 1; $j < $total; $j++) {
            if ($tokens[$j] === '(') {
                return null;
            }

            $token = $tokens[$j];

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
        }

        return null;
    }

    /**
     * How the method was declared. PHP's default is public, so an unqualified
     * method is one.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function visibilityBefore(array $tokens, int $from): string
    {
        for ($k = $from - 1; $k >= 0; $k--) {
            $token = $tokens[$k];

            if (! is_array($token)) {
                // A brace, a semicolon or a comma: the previous declaration ended
                // and no modifier belongs to this one.
                break;
            }

            if (in_array($token[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED], true)) {
                return strtolower($token[1]);
            }

            if (! in_array($token[0], [T_WHITESPACE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                break;
            }
        }

        return 'public';
    }

    /**
     * The method's body, by brace depth.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function bodyFrom(array $tokens, int $from, int $total): ?string
    {
        $depth = 0;
        $body = '';

        for ($j = $from; $j < $total; $j++) {
            $token = $tokens[$j];
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && $text === ';') {
                return null;
            }

            if ($text === '{') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    return $body;
                }
            }

            if ($depth > 0) {
                $body .= $text;
            }
        }

        return null;
    }
}
