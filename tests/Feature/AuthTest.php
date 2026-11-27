<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/group')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Welcome Back');
    }

    #[Test]
    public function company_users_land_on_their_dashboard_and_group_users_on_the_group_view(): void
    {
        $w = $this->world();

        $this->post('/login', ['email' => $w['userA']->email, 'password' => 'password'])->assertRedirect('/');
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Alpha Sdn Bhd');
        $this->assertNotNull($w['userA']->fresh()->last_login_at);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', ['email' => $w['ceo']->email, 'password' => 'password']);
        $this->get('/')->assertRedirect('/group');
    }

    #[Test]
    public function wrong_password_is_rejected_and_passwords_are_hashed(): void
    {
        $w = $this->world();

        $this->post('/login', ['email' => $w['userA']->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotSame('password', $w['userA']->getRawOriginal('password'));
        $this->assertStringStartsWith('$2y$', $w['userA']->getRawOriginal('password'));
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        $w = $this->world();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $w['userA']->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $w['userA']->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    #[Test]
    public function inactive_users_and_users_of_inactive_companies_cannot_sign_in(): void
    {
        $w = $this->world();
        $w['userA']->update(['is_active' => false]);
        $this->post('/login', ['email' => $w['userA']->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $w['b']->update(['is_active' => false]);
        $this->post('/login', ['email' => $w['userB']->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function a_session_is_ended_when_the_user_is_deactivated(): void
    {
        $w = $this->world();
        $this->actingAs($w['userA'])->get('/dashboard')->assertOk();

        $w['userA']->update(['is_active' => false]);
        $this->actingAs($w['userA']->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    #[Test]
    public function password_reset_does_not_reveal_whether_an_email_exists(): void
    {
        Notification::fake();
        $w = $this->world();

        $known = $this->post('/forgot-password', ['email' => $w['userA']->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        Notification::assertSentTo($w['userA'], ResetPassword::class);
    }

    #[Test]
    public function a_user_can_reset_their_password_with_a_valid_token(): void
    {
        Notification::fake();
        $w = $this->world();
        $this->post('/forgot-password', ['email' => $w['userA']->email]);

        Notification::assertSentTo($w['userA'], ResetPassword::class, function ($n) use ($w) {
            $this->post('/reset-password', [
                'token' => $n->token,
                'email' => $w['userA']->email,
                'password' => 'NewSecret123',
                'password_confirmation' => 'NewSecret123',
            ])->assertRedirect('/login');

            return true;
        });

        $this->post('/login', ['email' => $w['userA']->email, 'password' => 'NewSecret123']);
        $this->assertAuthenticatedAs(User::find($w['userA']->id));
    }

    #[Test]
    public function responses_carry_security_headers_and_forms_need_csrf(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('name="_token"', false);
    }
}
