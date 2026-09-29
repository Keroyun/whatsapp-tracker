<?php
class AWM_Emergency_Test extends WP_UnitTestCase {
	public function test_telephone_identity_preserves_prefix_and_extension() {
		$this->assertSame( '+12025550101;ext=123', awm_emergency_normalize_telephone( 'tel:+1 (202) 555-0101;ext=123' ) );
		$this->assertSame( '2025550101', awm_emergency_normalize_telephone( '202-555 0101' ) );
		$this->assertSame( '12025550101', awm_emergency_normalize_telephone( '12025550101' ) );
		$this->assertSame( '+12025550101;ext=0', awm_emergency_normalize_telephone( 'tel:+12025550101;ext=0' ) );
	}

	public function test_short_codes_and_malformed_telephone_targets_are_rejected() {
		foreach ( array( 'tel:999', 'tel:112', 'tel:911', 'tel:123456', 'tel:+12025550101;ext=abc', 'tel:+12025550101?text=bad', 'tel:++12025550101', 'tel:1234567890123456', 'tel:+12025550101;phone-context=example.org' ) as $value ) {
			$this->assertSame( '', awm_emergency_normalize_telephone( $value ) );
		}
	}

	public function test_exact_telephone_link_and_destination_share_identity() {
		$this->assertSame( 'tel:+12025550101;ext=12', awm_emergency_normalize_exact_link( 'tel:+1 (202) 555-0101;ext=12' ) );
		$this->assertSame( 'tel:+12025550101', awm_emergency_normalize_destination( 'tel:+1 (202) 555-0101' ) );
	}

	public function test_short_emergency_numbers_can_be_alternative_actions_only() {
		$this->assertSame( 'tel:999', awm_emergency_sanitize_action_url( 'tel:999' ) );
		$this->assertSame( '', awm_emergency_normalize_destination( 'tel:999' ) );
	}

	public function test_new_defaults_are_whatsapp_only_with_all_matching() {
		$rule = awm_emergency_rule_defaults();
		$this->assertSame( 'whatsapp', $rule['channel'] );
		$this->assertSame( 'all', $rule['match_mode'] );
	}

	public function test_telephone_rule_accepts_bare_number_without_guessing_country() {
		$rule = awm_emergency_sanitize_rule( array( 'channel' => 'telephone', 'destinations' => "202-5550101\ntel:+12025550101;ext=12" ) );
		$this->assertSame( array( 'tel:2025550101', 'tel:+12025550101;ext=12' ), $rule['destinations'] );
	}

	public function test_old_active_rule_keeps_whatsapp_channel_and_any_matching() {
		$previous = get_option( 'awm_emergency_rules', array() );
		try {
			update_option( 'awm_emergency_rules', array( array( 'id' => 'legacy', 'enabled' => '1', 'match_mode' => 'any', 'page_paths' => array( '*' ) ) ) );
			$active = awm_get_active_emergency_rules();
			$this->assertSame( 'whatsapp', $active[0]['channel'] );
			$this->assertSame( 'any', $active[0]['matchMode'] );
			$this->assertSame( '', $active[0]['language'] );
		} finally {
			update_option( 'awm_emergency_rules', $previous );
		}
	}

	public function test_legacy_tawk_action_is_migrated_to_generic_live_chat() {
		$rule = awm_emergency_sanitize_rule( array( 'primary_action' => 'tawk' ) );
		$this->assertSame( 'live_chat', $rule['primary_action'] );
	}

	public function test_internal_rule_name_is_not_exposed_in_public_config() {
		$previous = get_option( 'awm_emergency_rules', array() );
		try {
			update_option( 'awm_emergency_rules', array( array(
				'id' => 'private-rule',
				'name' => 'Internal Client Outage Name',
				'analytics_label' => 'service_outage',
				'enabled' => '1',
				'channel' => 'telephone',
				'destinations' => array( 'tel:+12025550101' ),
			) ) );
			$active = awm_get_active_emergency_rules();
			$this->assertArrayNotHasKey( 'name', $active[0] );
			$this->assertSame( 'service_outage', $active[0]['analyticsLabel'] );
		} finally {
			update_option( 'awm_emergency_rules', $previous );
		}
	}

	public function test_popup_visibility_defaults_on_and_channels_are_independent() {
		$sentinel = '__awm_missing__';
		$previous_whatsapp = get_option( 'awm_whatsapp_popup_enabled', $sentinel );
		$previous_telephone = get_option( 'awm_telephone_popup_enabled', $sentinel );
		try {
			update_option( 'awm_whatsapp_popup_enabled', '0' );
			update_option( 'awm_telephone_popup_enabled', '1' );
			$this->assertSame( array( 'whatsapp' => false, 'telephone' => true ), awm_get_popup_visibility() );
		} finally {
			$sentinel === $previous_whatsapp ? delete_option( 'awm_whatsapp_popup_enabled' ) : update_option( 'awm_whatsapp_popup_enabled', $previous_whatsapp );
			$sentinel === $previous_telephone ? delete_option( 'awm_telephone_popup_enabled' ) : update_option( 'awm_telephone_popup_enabled', $previous_telephone );
		}
	}
}
