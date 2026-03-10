<?php

/**
 * @group registration
 */
class RegistrationLocaleTest extends WP_UnitTestCase
{
    public function testRegistrationSavesMasterLocale(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        do_action('user_register', $user_id);

        $this->assertSame('fr_FR', get_user_meta($user_id, '_registration_locale', true));
    }

    public function testRegistrationSavesShadowLocale(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        do_action('user_register', $user_id);

        $this->assertSame('en_IE', get_user_meta($user_id, '_registration_locale', true));
    }

    public function testRegistrationSavesEsLocale(): void
    {
        $_SERVER['HTTP_HOST'] = 'es.test';

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        do_action('user_register', $user_id);

        $this->assertSame('es_ES', get_user_meta($user_id, '_registration_locale', true));
    }
}
