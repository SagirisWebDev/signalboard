<?php
/**
 * Structural + sync guards for the wp.org readme.txt.
 *
 * This is the RED-phase spec for Slice 9 (Issue #10) portfolio packaging.
 * It asserts wp.org readme.txt compliance and, crucially, that the file's
 * headers stay in sync with the canonical plugin headers in signalboard.php
 * so packaging metadata cannot drift.
 *
 * Assertions are deliberately structural (headers, section markers, regex,
 * value equality against the plugin header) — never on specific prose wording.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use WP_UnitTestCase;

/**
 * @covers ::readme.txt
 */
final class ReadmeTxtTest extends WP_UnitTestCase {

	/**
	 * Absolute path to the readme.txt under test.
	 */
	private function readme_path(): string {
		return dirname( __DIR__ ) . '/readme.txt';
	}

	/**
	 * Raw contents of readme.txt (empty string if absent).
	 */
	private function readme(): string {
		$path = $this->readme_path();

		return is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * The header block — everything before the first "== Section ==" heading.
	 */
	private function header_block(): string {
		$readme = $this->readme();
		$parts  = preg_split( '/^==\s+/m', $readme, 2 );

		return is_array( $parts ) ? $parts[0] : $readme;
	}

	/**
	 * Parse a single `Header: value` line out of the readme header block.
	 */
	private function readme_header( string $name ): ?string {
		$pattern = '/^' . preg_quote( $name, '/' ) . ':\s*(.+?)\s*$/m';

		if ( preg_match( $pattern, $this->header_block(), $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Parse a value out of the canonical plugin header in signalboard.php.
	 */
	private function plugin_header( string $name ): ?string {
		$plugin  = (string) file_get_contents( dirname( __DIR__ ) . '/signalboard.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pattern = '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(.+?)\s*$/m';

		if ( preg_match( $pattern, $plugin, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * The canonical plugin version (from the Version: header).
	 */
	private function plugin_version(): string {
		return (string) $this->plugin_header( 'Version' );
	}

	public function test_readme_txt_exists_at_plugin_root(): void {
		$this->assertFileExists(
			$this->readme_path(),
			'wp.org readme.txt must exist at the plugin root.'
		);
	}

	/**
	 * @dataProvider provide_required_headers
	 */
	public function test_header_block_contains_required_header( string $header ): void {
		$this->assertNotNull(
			$this->readme_header( $header ),
			sprintf( 'readme.txt header block must contain a "%s:" line.', $header )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_required_headers(): array {
		return array(
			'Contributors'      => array( 'Contributors' ),
			'Tags'              => array( 'Tags' ),
			'Requires at least' => array( 'Requires at least' ),
			'Tested up to'      => array( 'Tested up to' ),
			'Requires PHP'      => array( 'Requires PHP' ),
			'Stable tag'        => array( 'Stable tag' ),
			'License'           => array( 'License' ),
			'License URI'       => array( 'License URI' ),
		);
	}

	public function test_stable_tag_equals_plugin_version(): void {
		$this->assertSame(
			$this->plugin_version(),
			$this->readme_header( 'Stable tag' ),
			'readme.txt "Stable tag" must exactly equal the plugin Version header (drift guard).'
		);
	}

	public function test_requires_at_least_matches_plugin_header(): void {
		$this->assertSame(
			$this->plugin_header( 'Requires at least' ),
			$this->readme_header( 'Requires at least' ),
			'readme.txt "Requires at least" must match the plugin header.'
		);
	}

	public function test_requires_php_matches_plugin_header(): void {
		$this->assertSame(
			$this->plugin_header( 'Requires PHP' ),
			$this->readme_header( 'Requires PHP' ),
			'readme.txt "Requires PHP" must match the plugin header.'
		);
	}

	public function test_tested_up_to_is_present_and_well_formed(): void {
		$tested = $this->readme_header( 'Tested up to' );

		$this->assertNotNull( $tested, 'readme.txt must declare "Tested up to".' );
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+/',
			(string) $tested,
			'"Tested up to" must look like a WordPress version (e.g. 6.5).'
		);
	}

	public function test_tags_within_wporg_limit(): void {
		$tags_line = $this->readme_header( 'Tags' );

		$this->assertNotNull( $tags_line, 'readme.txt must declare "Tags".' );

		$tags = array_filter( array_map( 'trim', explode( ',', (string) $tags_line ) ) );

		$this->assertGreaterThanOrEqual(
			1,
			count( $tags ),
			'readme.txt must declare at least one tag.'
		);
		$this->assertLessThanOrEqual(
			5,
			count( $tags ),
			'wp.org allows a maximum of 5 tags.'
		);
	}

	public function test_short_description_within_limit(): void {
		$readme = $this->readme();

		// Short description: the first non-empty line after the header block's
		// trailing blank line, before the first "== Section ==" heading.
		$block = $this->header_block();
		$lines = preg_split( '/\R/', $block );
		$lines = ( false === $lines ) ? array() : $lines;

		$short                   = '';
		$seen_blank_after_header = false;
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Header lines contain a "Key: value" colon; skip the header region.
			if ( ! $seen_blank_after_header ) {
				if ( '' === $trimmed && preg_match( '/^[\w ]+:/m', $block ) ) {
					$seen_blank_after_header = true;
				}
				// Skip the plugin-name "=== Signalboard ===" title line too.
				continue;
			}

			if ( '' !== $trimmed ) {
				$short = $trimmed;
				break;
			}
		}

		$this->assertNotSame( '', $short, 'readme.txt must have a short description line.' );
		$this->assertLessThanOrEqual(
			150,
			strlen( $short ),
			'wp.org short description must be at most 150 characters.'
		);
	}

	/**
	 * @dataProvider provide_required_sections
	 */
	public function test_required_section_present( string $section ): void {
		$this->assertStringContainsString(
			$section,
			$this->readme(),
			sprintf( 'readme.txt must contain the "%s" section heading.', $section )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_required_sections(): array {
		return array(
			'Description'                => array( '== Description ==' ),
			'Installation'               => array( '== Installation ==' ),
			'Frequently Asked Questions' => array( '== Frequently Asked Questions ==' ),
			'Screenshots'                => array( '== Screenshots ==' ),
			'Changelog'                  => array( '== Changelog ==' ),
		);
	}

	public function test_changelog_mentions_current_version(): void {
		$readme = $this->readme();

		$changelog = '';
		if ( preg_match( '/==\s*Changelog\s*==(.*?)(?:^==\s|\z)/ms', $readme, $matches ) ) {
			$changelog = $matches[1];
		}

		$this->assertStringContainsString(
			$this->plugin_version(),
			$changelog,
			'The Changelog section must mention the current plugin version.'
		);
	}
}
