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

use Bitnix\Parse\ParseFailure;

/**
 * ...
 */
final class Loader {

    /**
     * @var string
     */
    private string $root;

    /**
     * @var Parser
     */
    private ?Parser $parser = null;

    /**
     * @param string $root
     */
    public function __construct(string $root) {
        $this->root = $root;
    }

    /**
     * @param string $file
     * @param array $env
     * @return array
     * @throws LoadFailure
     */
    public function require(string $file, array $env = []) : array {
        $parsed = $this->include($file, $env);
        if (null === $parsed) {
            throw new FileNotFound(\sprintf(
                'Failed to find dotenv file: %s', $file
            ));
        }
        return $parsed;
    }

    /**
     * @param string $file
     * @param array $env
     * @return null|array
     * @throws LoadFailure
     */
    public function include(string $file, array $env = []) : ?array {
        $file = $this->root . \DIRECTORY_SEPARATOR . $file;

        if (!\is_file($file)) {
            return null;
        }

        if (!\is_readable($file)) {
            throw new UnreadableFile(\sprintf(
                'Failed to read dotenv file: %s', $file
            ));
        }

        $content = \trim(\file_get_contents($file));

        if ('' === $content) {
            return [];
        }

        $this->parser ??= new Parser();

        try {
            $parsed = $this->parser->parse($content, $env);
        } catch (ParseFailure $x) {
            throw new SyntaxError(\sprintf(
                'Failed to parse dotenv file "%s"%s%s',
                    $file,
                    \PHP_EOL,
                    (string) $x
            ));
        }

        return $parsed;
    }

    /**
     * @return string
     */
    public function __toString() : string {
        return self::CLASS;
    }
}
