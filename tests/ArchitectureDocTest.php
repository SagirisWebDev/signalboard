<?php
/**
 * Structural guards for the docs/ARCHITECTURE.md deliverable.
 *
 * RED-phase spec for Slice 9 (Issue #10) portfolio packaging. Asserts the
 * architecture README exists and carries its required structural elements:
 * a mermaid data-flow diagram, references to both APIs (REST + WPGraphQL) and
 * the headless demo, an engineering-decision rationale heading, and the
 * deferred manual follow-up placeholders (demo video + case study).
 *
 * Assertions are structural (existence, fences, regex markers) — never on
 * specific prose wording, so the doc can be authored naturally.
 *
 * @package Sagiris\Signalboard
 */

declare( strict_types=1 );

namespace Sagiris\Signalboard\Tests;

use WP_UnitTestCase;

/**
 * @covers ::docs/ARCHITECTURE.md
 */
final class ArchitectureDocTest extends WP_UnitTestCase {

	/**
	 * Absolute path to the architecture doc under test.
	 */
	private function doc_path(): string {
		return dirname( __DIR__ ) . '/docs/ARCHITECTURE.md';
	}

	/**
	 * Raw contents of the architecture doc (empty string if absent).
	 */
	private function doc(): string {
		$path = $this->doc_path();

		return is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_architecture_doc_exists(): void {
		$this->assertFileExists(
			$this->doc_path(),
			'docs/ARCHITECTURE.md must exist.'
		);
	}

	public function test_contains_mermaid_data_flow_diagram(): void {
		$this->assertStringContainsString(
			'```mermaid',
			$this->doc(),
			'ARCHITECTURE.md must contain a fenced mermaid data-flow diagram.'
		);
	}

	public function test_references_both_apis(): void {
		$doc = $this->doc();

		$this->assertStringContainsString(
			'REST',
			$doc,
			'ARCHITECTURE.md must reference the REST API.'
		);
		$this->assertMatchesRegularExpression(
			'/WPGraphQL|GraphQL/',
			$doc,
			'ARCHITECTURE.md must reference the (WP)GraphQL API.'
		);
	}

	public function test_references_headless_demo(): void {
		$this->assertMatchesRegularExpression(
			'/headless|Next\.js/i',
			$this->doc(),
			'ARCHITECTURE.md must reference the headless / Next.js demo.'
		);
	}

	public function test_contains_engineering_decision_rationale_heading(): void {
		$this->assertMatchesRegularExpression(
			'/##+\s+.*(Decision|Why|Rationale|Trade)/i',
			$this->doc(),
			'ARCHITECTURE.md must contain an engineering-decision rationale heading.'
		);
	}

	public function test_contains_manual_follow_up_placeholders(): void {
		$doc = $this->doc();

		$this->assertMatchesRegularExpression(
			'/demo video/i',
			$doc,
			'ARCHITECTURE.md must note the deferred demo video follow-up.'
		);
		$this->assertMatchesRegularExpression(
			'/case study/i',
			$doc,
			'ARCHITECTURE.md must note the deferred case study follow-up.'
		);
	}
}
