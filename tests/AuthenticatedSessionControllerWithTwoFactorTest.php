<?php

namespace Laravel\Fortify\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Fortify\Tests\Models\UserWithTwoFactor;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use PragmaRX\Google2FA\Google2FA;

#[WithMigration]
#[DefineEnvironment('withTwoFactorAuthentication')]
#[WithConfig('auth.providers.users.model', UserWithTwoFactor::class)]
class AuthenticatedSessionControllerWithTwoFactorTest extends OrchestraTestCase
{
    use RefreshDatabase;

    public function test_user_is_redirected_to_challenge_when_using_two_factor_authentication()
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/two-factor-challenge');

        Event::assertDispatched(TwoFactorAuthenticationChallenged::class);
    }

    #[DefineEnvironment('withConfirmedTwoFactorAuthentication')]
    public function test_user_is_not_redirected_to_challenge_when_using_two_factor_authentication_that_has_not_been_confirmed_and_confirmation_is_enabled()
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/home');
    }

    #[DefineEnvironment('withConfirmedTwoFactorAuthentication')]
    public function test_user_is_redirected_to_challenge_when_using_two_factor_authentication_that_has_been_confirmed_and_confirmation_is_enabled()
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/two-factor-challenge');
    }

    #[DefineEnvironment('withoutTwoFactorAuthentication')]
    public function test_user_can_authenticate_when_two_factor_challenge_is_disabled()
    {
        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/home');
    }

    public function test_two_factor_challenge_can_be_passed_via_code()
    {
        $tfaEngine = app(Google2FA::class);
        $userSecret = $tfaEngine->generateSecretKey();
        $validOtp = $tfaEngine->getCurrentOtp($userSecret);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt($userSecret),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'code' => $validOtp,
        ]);

        $response->assertRedirect('/home')
            ->assertSessionMissing('login.id');
    }

    public function test_two_factor_authentication_preserves_remember_me_selection(): void
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
            'remember' => false,
        ]);

        $response->assertRedirect('/two-factor-challenge')
            ->assertSessionHas('login.remember', false);
    }

    public function test_two_factor_challenge_fails_for_old_otp_and_zero_window()
    {
        // Setting window to 0 should mean any old OTP is instantly invalid
        Features::twoFactorAuthentication(['window' => 0]);

        $tfaEngine = app(Google2FA::class);
        $userSecret = $tfaEngine->generateSecretKey();
        $currentTs = $tfaEngine->getTimestamp();
        $previousOtp = $tfaEngine->oathTotp($userSecret, $currentTs - 1);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt($userSecret),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'code' => $previousOtp,
        ]);

        $response->assertRedirect('/two-factor-challenge')
                 ->assertSessionHas('login.id')
                 ->assertSessionHasErrors(['code']);
    }

    public function test_two_factor_challenge_can_be_passed_via_recovery_code()
    {
        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['invalid-code', 'valid-code'])),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'recovery_code' => 'valid-code',
        ]);

        $response->assertRedirect('/home')
            ->assertSessionMissing('login.id');
        $this->assertNotNull(Auth::getUser());
        $this->assertNotContains('valid-code', json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true));
    }

    public function test_two_factor_challenge_can_fail_via_recovery_code()
    {
        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['invalid-code', 'valid-code'])),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'recovery_code' => 'missing-code',
        ]);

        $response->assertRedirect('/two-factor-challenge')
            ->assertSessionHas('login.id')
            ->assertSessionHasErrors(['recovery_code']);
        $this->assertNull(Auth::getUser());
    }

    public function test_two_factor_challenge_requires_a_challenged_user()
    {
        $response = $this->withSession([])->withoutExceptionHandling()->get('/two-factor-challenge');

        $response->assertRedirect('/login');
        $this->assertNull(Auth::getUser());
    }
}
