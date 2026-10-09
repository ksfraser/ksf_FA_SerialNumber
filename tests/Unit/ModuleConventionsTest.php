<?php
/**
 * @BABOK Related: FR-SN-001-001
 */
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Module convention guards.
 *
 * These assert the things that are cheap to get wrong and expensive to discover
 * in production, because each has already bitten a sibling module:
 *
 * - sql/install.sql must use a literal 0_ prefix. FA's db_import() only
 *   substitutes "0_"; an earlier ksf_Inventory used {{MDB}} and would have
 *   created literal tables named "{{MDB}}inventory_serial_numbers".
 * - Namespace must be ksfraser\FrontAccounting\SerialNumber\ (lowercase vendor
 *   segment) and must match composer PSR-4.
 * - No PHP 8-only syntax, because the FA floor is 7.3 and the container runs 7.4.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Tests\Unit
 * @since 1.0.0
 */
class ModuleConventionsTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $out = array();

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /**
     * Strip comments so prose about a convention is not a false positive.
     *
     * Note: every non-comment array token must still be emitted. A stray
     * `continue` drops T_STRING tokens, so function names never reach the regex
     * and the guard passes everything silently -- that bug made two guards in
     * ksf_FA_Square vacuous.
     *
     * @param string $path
     * @return string
     */
    private function codeWithoutComments(string $path): string
    {
        $out = '';

        foreach (token_get_all((string)file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    public function testInstallSqlUsesLiteralZeroPrefix(): void
    {
        // Strip '--' line comments before checking. The file's header comment
        // explains WHY literal 0_ is required and therefore necessarily names the
        // tokens it replaces -- checking the raw text trips the guard on its own
        // documentation. Only executable SQL matters.
        $raw = (string)file_get_contents($this->root . '/sql/install.sql');

        $lines = preg_split('/\R/', $raw);
        $sql = '';

        foreach ($lines as $line) {
            if (strpos(ltrim($line), '--') === 0) {
                continue;
            }
            $sql .= $line . "\n";
        }

        $this->assertStringNotContainsString(
            '{TB_PREF}',
            $sql,
            'FA substitutes only a literal 0_, not {TB_PREF}'
        );
        $this->assertStringNotContainsString('@TB_PREF@', $sql, 'FA substitutes only a literal 0_');
        $this->assertStringNotContainsString('{{MDB}}', $sql, 'FA substitutes only a literal 0_');

        preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $m);

        $this->assertNotEmpty($m[1], 'no CREATE TABLE found -- did the format change?');

        foreach ($m[1] as $table) {
            $this->assertStringStartsWith(
                '0_ksf_',
                $table,
                'table ' . $table . ' must use the literal 0_ksf_ prefix'
            );
        }
    }

    public function testEverySourceFileUsesTheCanonicalNamespace(): void
    {
        $expected = 'ksfraser\FrontAccounting\SerialNumber';
        $offenders = array();

        foreach ($this->sourceFiles() as $path) {
            if (!preg_match('/^\s*namespace\s+([^;]+);/m', file_get_contents($path), $m)) {
                continue;
            }

            $ns = trim($m[1]);

            if (strpos($ns, $expected) !== 0) {
                $offenders[] = str_replace($this->root . '/', '', $path) . ' -> ' . $ns;
            }

            if (strpos($ns, 'Ksfraser') === 0) {
                $offenders[] = str_replace($this->root . '/', '', $path)
                    . ' -> capital-K vendor segment';
            }
        }

        $this->assertSame([], $offenders, 'non-canonical namespaces: ' . implode(', ', $offenders));
    }

    public function testComposerPsr4MatchesTheNamespace(): void
    {
        $composer = json_decode((string)file_get_contents($this->root . '/composer.json'), true);

        $this->assertArrayHasKey('psr-4', $composer['autoload']);

        $prefixes = array_keys($composer['autoload']['psr-4']);

        $this->assertContains(
            'ksfraser\\FrontAccounting\\SerialNumber\\',
            $prefixes,
            'PSR-4 root must match the namespace root'
        );
    }

    public function testComposerPinsTheContainerPhpVersion(): void
    {
        // The host runs 8.1 and the container 7.4. Without the pin, composer
        // resolves for the wrong PHP and vendor/ breaks site-wide.
        $composer = json_decode((string)file_get_contents($this->root . '/composer.json'), true);

        $this->assertArrayHasKey('platform', $composer['config'] ?? array());
        $this->assertSame('7.4.33', $composer['config']['platform']['php'] ?? null);
    }

    public function testNoPhp8OnlySyntax(): void
    {
        // Typed properties are 7.4; the documented floor is 7.3 and other modules
        // still target it, so this module stays clean.
        $offenders = array();

        foreach ($this->sourceFiles() as $path) {
            $code = $this->codeWithoutComments($path);
            $relative = str_replace($this->root . '/', '', $path);

            if (preg_match('/\bmatch\s*\(/', $code)) {
                $offenders[] = $relative . ' (match expression, PHP 8.0)';
            }

            if (preg_match('/\?->/', $code)) {
                $offenders[] = $relative . ' (nullsafe operator, PHP 8.0)';
            }

            if (preg_match('/\bfn\s*\(/', $code)) {
                $offenders[] = $relative . ' (arrow function, PHP 7.4)';
            }

            if (preg_match('/public\s+(readonly\s+)?[A-Za-z\\?]+\s+\$/', $code)) {
                $offenders[] = $relative . ' (typed property, PHP 7.4)';
            }
        }

        $this->assertSame([], $offenders, 'PHP 7.3 incompatible syntax: ' . implode(', ', $offenders));
    }

    public function testSecuritySectionIsDefinedWithAFreeNumber(): void
    {
        $hooks = (string)file_get_contents($this->root . '/hooks.php');

        $this->assertStringContainsString(
            "define('SS_ksf_FA_SerialNumber'",
            $hooks,
            'security section must be defined'
        );

        // 156 was verified free at creation time; keep it pinned so a future
        // collision is caught here rather than by two modules fighting over one
        // security section.
        $this->assertStringContainsString('156 << 8', $hooks);
    }

    public function testHooksClassAndModuleNameAgree(): void
    {
        $hooks = (string)file_get_contents($this->root . '/hooks.php');

        $this->assertStringContainsString('class hooks_ksf_FA_SerialNumber extends hooks', $hooks);
        $this->assertStringContainsString("\$module_name = 'ksf_FA_SerialNumber'", $hooks);
    }
}