<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant isolation: a company user must never reach another company's data,
 * whatever URL, query string or body they send. More cases are added as each
 * stage introduces new routes (see EntryAccessTest, etc.).
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function company_users_cannot_open_group_pages_or_other_companies(): void
    {
        $w = $this->world();
        $this->actingAs($w['userA']);

        $this->get('/group')->assertForbidden();
        $this->get("/group/companies/{$w['b']->id}")->assertForbidden();
        $this->get("/group/companies/{$w['a']->id}")->assertForbidden(); // even their own, via the group URL
    }

    #[Test]
    public function the_company_dashboard_ignores_company_ids_in_the_request(): void
    {
        $w = $this->world();

        $this->actingAs($w['userA'])
            ->get("/dashboard?company_id={$w['b']->id}&company={$w['b']->id}")
            ->assertOk()
            ->assertSee('Alpha Sdn Bhd')
            ->assertDontSee('Bravo Sdn Bhd');
    }

    #[Test]
    public function admin_and_ceo_can_view_every_company(): void
    {
        $w = $this->world();

        foreach (['admin', 'ceo'] as $role) {
            $this->actingAs($w[$role]);
            $this->get('/group')->assertOk();
            $this->get("/group/companies/{$w['a']->id}")->assertOk()->assertSee('Alpha Sdn Bhd');
            $this->get("/group/companies/{$w['b']->id}")->assertOk()->assertSee('Bravo Sdn Bhd');
            $this->get('/dashboard')->assertForbidden(); // company-only route
        }
    }

    #[Test]
    public function unknown_companies_return_404(): void
    {
        $w = $this->world();
        $this->actingAs($w['admin'])->get('/group/companies/999999')->assertNotFound();
    }
}
