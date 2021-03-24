<?php declare(strict_types=1);

/**
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <https://www.gnu.org/licenses/agpl-3.0.txt>.
 */

namespace Bitnix\Dotenv;

use RuntimeException,
    Bitnix\Parse\Lexer,
    Bitnix\Parse\ParseFailure,
    Bitnix\Parse\Lexer\Scanner,
    Bitnix\Parse\Lexer\State,
    Bitnix\Parse\Lexer\TokenSet,
    Bitnix\Parse\Lexer\TokenStream;

/**
 * ...
 */
final class Parser {

    private const T_WHITESPACE   = 'T_WHITESPACE';
    private const T_COMMENT      = 'T_COMMENT';
    private const T_EXPORT       = 'T_EXPORT';
    private const T_VAR_NAME     = 'T_VAR_NAME';
    private const T_VAR_VALUE    = 'T_VAR_VALUE';
    private const T_ASSIGN       = 'T_ASSIGN';
    private const T_SINGLE_QUOTE = 'T_SINGLE_QUOTE';
    private const T_RAW_TEXT     = 'T_RAW_TEXT';
    private const T_DOUBLE_QUOTE = 'T_DOUBLE_QUOTE';
    private const T_EOL          = 'T_EOL';
    private const T_EOS          = 'T_EOS';

    private const MAIN_MATCH = [
        self::T_VAR_NAME   => '(?i)[a-z][a-z0-9_\.]*'
    ];

    private const MAIN_SKIP = [
        self::T_WHITESPACE => '\s+',
        self::T_COMMENT    => '#[^\r\n]*',
        self::T_EXPORT     => '\bexport\b'
    ];

    private const VALUE_MATCH = [
        self::T_ASSIGN       => '=',
        self::T_SINGLE_QUOTE => "'",
        self::T_DOUBLE_QUOTE => '"',
        self::T_VAR_VALUE    => '[^\s]+',
        self::T_EOL          => '\r?\n'
    ];

    private const VALUE_SKIP = [
        self::T_COMMENT      => '[ \t]*#[^\r\n]*',
    ];

    private const SINGLE_QUOTED_STRING = [
        self::T_RAW_TEXT     => "([^'\\\\]|\\\\(')?)+",
        self::T_SINGLE_QUOTE => "'"
    ];

    private const DOUBLE_QUOTED_STRING = [
        self::T_RAW_TEXT     => '([^"\\\\]|\\\\(")?)+',
        self::T_DOUBLE_QUOTE => '"'
    ];

    private const BOOLEANS = [
        'true'  => true,
        'on'    => true,
        'yes'   => true,
        'false' => false,
        'off'   => false,
        'no'    => false
    ];

    private const SPECIAL_CHARS_SEARCH  = [
        '\"', '\r', '\n', '\t'
    ];

    private const SPECIAL_CHARS_REPLACE = [
        '"', "\r", "\n", "\t"
    ];

    private const NULL  = 'null';
    private const EMPTY = '';

    private const UNFOLD      = '~\\\\\r?\n\s+~';
    private const INTERPOLATE = '~\$\{(([^:]*)\:\-([^}]*)|([^}]*))\}~';

    /**
     * @var Lexer
     */
    private ?Lexer $lexer = null;

    /**
     * @var State
     */
    private State $main;

    /**
     * ...
     */
    public function __construct() {
        $pop = fn($stack) => $stack->pop();

        $sqstr = new TokenSet(self::SINGLE_QUOTED_STRING, [], [
            self::T_SINGLE_QUOTE => $pop
        ]);

        $dqstr = new TokenSet(self::DOUBLE_QUOTED_STRING, [], [
            self::T_DOUBLE_QUOTE => $pop
        ]);

        $value = new TokenSet(self::VALUE_MATCH, self::VALUE_SKIP, [
            self::T_EOL => $pop,
            self::T_SINGLE_QUOTE => fn($stack) => $stack->push($sqstr),
            self::T_DOUBLE_QUOTE => fn($stack) => $stack->push($dqstr)
        ]);

        $this->main = new TokenSet(self::MAIN_MATCH, self::MAIN_SKIP, [
            self::T_VAR_NAME => fn($stack) => $stack->push($value)
        ]);

    }

    /**
     * @param string $content
     * @param array $context
     * @return array
     * @throws ParseFailure
     * @throws RuntimeException
     */
    public function parse(string $content, array $context = []) : array {
        $env = [];
        try {
            $this->lexer = new Scanner(TokenStream::fromString($this->main, $content));
            while ($this->lexer->valid()) {
                $value = null;

                $name = $this->lexer->demand(self::T_VAR_NAME)->lexeme();
                $this->lexer->demand(self::T_ASSIGN);

                if ($token = $this->lexer->consume(self::T_VAR_VALUE)) {
                    $value = $this->cast($token->lexeme());
                    if (\is_string($value)) {
                        $value = $this->interpolate($value, $env, $context);
                    }
                } else if ($this->lexer->consume(self::T_SINGLE_QUOTE)) {
                    $value = '';
                    while ($token = $this->lexer->consume(self::T_RAW_TEXT)) {
                        $value .= $token->lexeme();
                    }
                    $this->lexer->demand(self::T_SINGLE_QUOTE);
                    $value = \str_replace("\'", "'", $value);
                } else if ($this->lexer->consume(self::T_DOUBLE_QUOTE)) {
                    $value = '';
                    while ($token = $this->lexer->consume(self::T_RAW_TEXT)) {
                        $value .= $token->lexeme();
                    }
                    $this->lexer->demand(self::T_DOUBLE_QUOTE);
                    $value = str_replace(
                        self::SPECIAL_CHARS_SEARCH,
                        self::SPECIAL_CHARS_REPLACE,
                        $this->interpolate($this->unfold($value), $env, $context)
                    );
                }

                if (!$this->lexer->match(self::T_EOS)) {
                    $this->lexer->demand(self::T_EOL);
                }

                $env[$name] = $value;
            }
        } finally {
            $this->lexer = null;
        }
        return $env;
    }

    /**
     * @param string $value
     * @return string
     */
    private function unfold(string $value) : string {
        return \preg_replace(self::UNFOLD, self::EMPTY, $value);
    }

    /**
     * @param string $value
     * @param array $env
     * @param array $context
     * @return string
     */
    private function interpolate(string $value, array $env, array $context) : string {
        return \preg_replace_callback(self::INTERPOLATE, function($m) use ($env, $context) {
            $value = $env[$m[1]] ?? $context[$m[1]] ?? null;
            return \is_string($value) || \is_numeric($value) ? (string) $value : $m[3];
        }, $value);
    }

    /**
     * @param string $value
     * @return mixed
     */
    private function cast(string $value) : mixed {
        $test = \strtolower($value);

        if (self::NULL === $test) {
            return null;
        } else if (isset(self::BOOLEANS[$test])) {
            return self::BOOLEANS[$test];
        } else if (\is_numeric($test)) {
            $number = (int) $test;
            if ($number == $test) {
                return $number;
            }

            $number = (float) $test;
            if ($number == $test) {
                return $number;
            }
        } else if (\defined($value)) {
            return \constant($value);
        }

        return $value;
    }

    /**
     * @return string
     */
    public function __toString() : string {
        return self::CLASS;
    }
}
