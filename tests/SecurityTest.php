<?php
class AWM_Security_Test extends WP_UnitTestCase {
	public function test_same_origin_accepts_site_url() {
		$this->assertTrue( awm_url_is_same_origin_as_site( home_url( '/' ) ) );
	}

	public function test_same_origin_rejects_external_host() {
		$this->assertFalse( awm_url_is_same_origin_as_site( 'https://example.invalid/contact/' ) );
	}

	public function test_lock_is_exclusive_and_releasable() {
		$name = 'test_' . wp_generate_password( 8, false );
		$first = awm_acquire_lock( $name, 10 );
		$this->assertIsString( $first );
		$second = awm_acquire_lock( $name, 10 );
		$this->assertWPError( $second );
		awm_release_lock( $name, $first );
		$third = awm_acquire_lock( $name, 10 );
		$this->assertIsString( $third );
		awm_release_lock( $name, $third );
	}
}
