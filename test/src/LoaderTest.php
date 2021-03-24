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

use PHPUnit\Framework\TestCase;

/**
 * @version 0.1.0
 */
class LoaderTest extends TestCase {

    private ?Loader $loader = null;

    public function setUp() : void {
        $this->loader = new Loader(__DIR__ . '/_env');
    }

    public function testSyntaxError() {
        $this->expectException(SyntaxError::CLASS);
        $this->loader->require('.env.error.01');
    }

    public function testIncludeMissingFile() {
        $this->assertNull($this->loader->include('.not_env'));
    }

    public function testRequireMissingFile() {
        $this->expectException(FileNotFound::CLASS);
        $this->loader->require('.not_env');
    }

    public function testUnreadableFile() {
        $this->expectException(UnreadableFile::CLASS);

        $file = __DIR__ . '/_env/.env';
        \chmod($file, 0000);
        try {
            $this->loader->require('.env');
        } finally {
            \chmod($file, 0755);
        }
    }

    public function testNonEmptyEnvFile() {
        $env = $this->loader->require('.env');
        $this->assertTrue(!empty($env));

        $env = $this->loader->include('.env');
        $this->assertTrue(!empty($env));
    }

    public function testEmptyEnvFile() {
        $this->assertEquals([], $this->loader->require('.env.empty'));
        $this->assertEquals([], $this->loader->include('.env.empty'));
    }

    public function testToString() {
        $this->assertIsString((string) $this->loader);
    }
}
