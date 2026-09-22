<?php
class AWM_WhatsApp_Test extends WP_UnitTestCase {
	public function test_phone_normalization_enforces_e164_length_bounds() {
		$this->assertSame( '12025550100', awm_normalize_phone( '+1 202-555-0100' ) );
		$this->assertSame( '', awm_normalize_phone( '123456' ) );
		$this->assertSame( '', awm_normalize_phone( '1234567890123456' ) );
	}

	public function test_parser_accepts_known_whatsapp_url() {
		$parsed = awm_parse_whatsapp_url( 'https://wa.me/12025550100?text=Example' );
		$this->assertIsArray( $parsed );
		$this->assertTrue( $parsed['is_valid'] );
		$this->assertSame( '12025550100', $parsed['destination'] );
	}

	public function test_parser_rejects_non_whatsapp_host() {
		$this->assertFalse( awm_parse_whatsapp_url( 'https://example.com/12025550100' ) );
	}

	public function test_route_fallback_rejects_external_origin() {
		$route = awm_sanitize_managed_route( array(
			'slug' => 'test-route',
			'primary_number' => '12025550100',
			'fallback_url' => 'https://example.invalid/phish',
		) );
		$this->assertSame( home_url( '/' ), $route['fallback_url'] );
	}
}
