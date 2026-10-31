<?php
/**
 * Tests for the bundled translations.
 *
 * @package FailedLoginAttemptsCounter
 */

/**
 * Translation tests.
 */
class Test_FLAC_Translations extends WP_UnitTestCase {

	const DOMAIN = 'failed-login-attempts-counter';

	/**
	 * Returns the languages directory.
	 *
	 * @return string
	 */
	private function languages_dir() {
		return dirname( __DIR__ ) . '/languages/';
	}

	/**
	 * Loads a PO or POT file.
	 *
	 * @param string $file File name inside the languages directory.
	 * @return PO
	 */
	private function load_po( $file ) {
		require_once ABSPATH . WPINC . '/pomo/po.php';
		$po = new PO();
		$this->assertTrue( $po->import_from_file( $this->languages_dir() . $file ), "Could not read {$file}." );
		return $po;
	}

	/**
	 * Returns 'nl_NL' for the locale filter.
	 *
	 * @return string
	 */
	public function dutch_locale() {
		return 'nl_NL';
	}

	/**
	 * Makes WordPress use Dutch for the plugin text domain.
	 *
	 * switch_to_locale() refuses locales without a core language pack, which
	 * the test install does not have. Filtering the locale and unloading the
	 * domain as reloadable makes the next translation call load the plugin's
	 * nl_NL .mo file just in time, like WordPress does on a Dutch site.
	 */
	private function use_dutch() {
		add_filter( 'locale', array( $this, 'dutch_locale' ) );
		unload_textdomain( self::DOMAIN, true );
	}

	/**
	 * Restores English.
	 */
	private function use_english() {
		remove_filter( 'locale', array( $this, 'dutch_locale' ) );
		unload_textdomain( self::DOMAIN, true );
	}

	public function test_text_domain_is_loaded_on_init() {
		$this->assertSame( 10, has_action( 'init', array( 'FLAC_Plugin', 'load_textdomain' ) ) );
	}

	public function test_dutch_mo_file_exists() {
		$this->assertFileExists( $this->languages_dir() . self::DOMAIN . '-nl_NL.mo' );
	}

	public function test_dutch_po_translates_every_string_in_the_pot() {
		$pot = $this->load_po( self::DOMAIN . '.pot' );
		$nl  = $this->load_po( self::DOMAIN . '-nl_NL.po' );

		$this->assertNotEmpty( $pot->entries );

		foreach ( $pot->entries as $key => $entry ) {
			$this->assertArrayHasKey( $key, $nl->entries, "Missing Dutch translation for: {$entry->singular}" );
			$translations = $nl->entries[ $key ]->translations;
			$this->assertNotEmpty( $translations, "Empty Dutch translation for: {$entry->singular}" );
			foreach ( $translations as $translation ) {
				$this->assertNotSame( '', $translation, "Empty Dutch plural form for: {$entry->singular}" );
			}
			if ( $entry->is_plural ) {
				$this->assertCount( 2, $translations, "Dutch needs two plural forms for: {$entry->singular}" );
			}
		}

		foreach ( $nl->entries as $key => $entry ) {
			$this->assertArrayHasKey( $key, $pot->entries, "Dutch translation for a string that is no longer in the POT: {$entry->singular}" );
		}
	}

	public function test_dutch_translation_is_used_for_the_dutch_locale() {
		$this->use_dutch();

		try {
			$this->assertSame( 'Inlogpogingen', __( 'Login attempts', self::DOMAIN ) );
			$this->assertSame( 'Onderhoud', __( 'Maintenance', self::DOMAIN ) );
			$this->assertSame(
				'1 logregel is gewist.',
				sprintf( _n( '%s log entry was cleared.', '%s log entries were cleared.', 1, self::DOMAIN ), 1 )
			);
			$this->assertSame(
				'3 logregels zijn gewist.',
				sprintf( _n( '%s log entry was cleared.', '%s log entries were cleared.', 3, self::DOMAIN ), 3 )
			);
		} finally {
			$this->use_english();
		}

		$this->assertSame( 'Login attempts', __( 'Login attempts', self::DOMAIN ) );
	}

	public function test_dutch_translation_is_used_in_rendered_pages() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'toplevel_page_' . FLAC_Admin_Page::SLUG );
		$this->use_dutch();

		try {
			ob_start();
			FLAC_Plugin::instance()->admin_page->render();
			$html = ob_get_clean();
		} finally {
			$this->use_english();
		}

		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Inlogpogingen</h1>', $html );
		$this->assertStringContainsString( 'Nog geen inlogpogingen vastgelegd.', $html );
		$this->assertStringContainsString( 'Datum / tijd', $html );
	}
}
