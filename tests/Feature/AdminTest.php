<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AmpTarget;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 10:00');
        $this->w = $this->world();
    }

    /** Every admin-only endpoint: [method, uri] with {a} = company A id. */
    public static function adminEndpoints(): array
    {
        return [
            ['get', '/admin/amp'],
            ['get', '/admin/periods'],
            ['put', '/admin/periods'],
            ['get', '/admin/companies'],
            ['get', '/admin/companies/create'],
            ['post', '/admin/companies'],
            ['get', '/admin/companies/{a}/edit'],
            ['put', '/admin/companies/{a}'],
            ['get', '/admin/users'],
            ['get', '/admin/users/create'],
            ['post', '/admin/users'],
            ['put', '/group/companies/{a}/amp/2026'],
            ['post', '/group/companies/{a}/amp/2026/import'],
            ['get', '/admin/audit'],
        ];
    }

    #[Test]
    #[DataProvider('adminEndpoints')]
    public function ceo_and_company_users_get_403_on_admin_endpoints(string $method, string $uri): void
    {
        $uri = str_replace('{a}', (string) $this->w['a']->id, $uri);
        foreach (['ceo', 'userA'] as $who) {
            $this->actingAs($this->w[$who])->{$method}($uri, ['name' => 'x', 'locked' => 1, 'year' => 2026, 'month' => 1])
                ->assertForbidden();
        }
        $this->assertSame(0, Period::count());
        $this->assertSame(0, AmpTarget::count());
    }

    #[Test]
    public function group_pages_are_open_to_admin_and_ceo_only(): void
    {
        foreach (['/group', '/group/compare', '/group/compare?month=3&metric=pbt', '/group/tracker', '/group/tracker?month=2', "/group/companies/{$this->w['b']->id}/amp/2026"] as $uri) {
            $this->actingAs($this->w['admin'])->get($uri)->assertOk();
            $this->actingAs($this->w['ceo'])->get($uri)->assertOk();
            $this->actingAs($this->w['userA'])->get($uri)->assertForbidden();
        }
    }

    #[Test]
    public function ceo_sees_no_edit_controls(): void
    {
        $this->actingAs($this->w['ceo']);
        $amp = $this->get("/group/companies/{$this->w['a']->id}/amp/2026")->assertOk()->getContent();
        $this->assertStringContainsString('View only', $amp);
        $this->assertStringNotContainsString('Save AMP', $amp);
        $this->assertStringNotContainsString('Import Excel', $amp);
        $this->assertStringNotContainsString('name="amp[', $amp);

        $sidebar = $this->get('/group')->getContent();
        $this->assertStringNotContainsString('Period Locks', $sidebar);
        $this->assertStringNotContainsString('Audit Log', $sidebar);
    }

    #[Test]
    public function admin_sets_amp_with_audit_and_company_user_sees_it_read_only(): void
    {
        $rev = FinancialLine::standard()->where('code', 'revenue')->value('id');
        $dm = FinancialLine::standard()->where('code', 'direct_materials')->value('id');

        $this->actingAs($this->w['admin'])->put("/group/companies/{$this->w['a']->id}/amp/2026", ['amp' => [
            $rev => [1 => '13,542.21', 2 => '1000'],
            $dm => [1 => '11622.91'],
        ]])->assertSessionHas('status', 'AMP saved. 3 targets updated.');

        $this->assertSame('13542.21', AmpTarget::where(['month' => 1, 'financial_line_id' => $rev])->value('amount'));
        $this->assertSame(3, AuditLog::where('action', 'amp.grid')->count());

        // Clearing a cell removes the target.
        $this->put("/group/companies/{$this->w['a']->id}/amp/2026", ['amp' => [$rev => [2 => '']]]);
        $this->assertSame(2, AmpTarget::count());

        $this->actingAs($this->w['userA'])->get('/amp/2026')->assertOk()
            ->assertSee('13,542.21')->assertSee('1,919.30') // GP derived
            ->assertSee('View only')->assertDontSee('Save AMP');
        // Company B's user can't see A's plan.
        $this->actingAs($this->w['userB'])->get('/amp/2026')->assertDontSee('13,542.21');
    }

    #[Test]
    public function admin_imports_amp_from_csv_then_saves(): void
    {
        Storage::fake('local');
        $csv = "Line,Jan,Feb,Mar,Apr,May,Jun,Jul,Aug,Sep,Oct,Nov,Dec\nRevenue,100,100,100,100,100,100,100,100,100,100,100,\"1,200\"\nUnknown,1,1,1,1,1,1,1,1,1,1,1,1\n";
        $path = tempnam(sys_get_temp_dir(), 'amp').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->w['admin'])
            ->post("/group/companies/{$this->w['a']->id}/amp/2026/import", ['file' => new UploadedFile($path, 'amp.csv', 'text/csv', null, true)])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '12 AMP values loaded'))
            ->assertSessionHas('amp_warnings', fn ($w) => count($w) === 1);

        $this->assertSame(0, AmpTarget::count()); // staged only
        $this->get("/group/companies/{$this->w['a']->id}/amp/2026")->assertSee('12 values from upload')->assertSee('2,300.00');
    }

    #[Test]
    public function locking_a_month_blocks_entry_and_is_audited(): void
    {
        $this->actingAs($this->w['admin'])->put('/admin/periods', ['year' => 2026, 'month' => 10, 'locked' => 1])
            ->assertSessionHas('status', 'October 2026 locked for all companies.');
        $this->assertTrue(Period::isLocked($this->w['a']->id, 2026, 10));
        $this->assertSame(1, AuditLog::where('action', 'period.locked')->count());

        $rev = FinancialLine::standard()->where('code', 'revenue')->value('id');
        $this->actingAs($this->w['userA'])->put('/entries/2026/10', ['cells' => [$rev => [1 => ['actual' => '1']]]])
            ->assertSessionHasErrors('period');

        // Unlock just company A.
        $this->actingAs($this->w['admin'])->put('/admin/periods', ['year' => 2026, 'month' => 10, 'company_id' => $this->w['a']->id, 'locked' => 0]);
        $this->assertFalse(Period::isLocked($this->w['a']->id, 2026, 10));
        $this->assertTrue(Period::isLocked($this->w['b']->id, 2026, 10));
    }

    #[Test]
    public function admin_manages_companies_and_users(): void
    {
        $this->actingAs($this->w['admin']);

        $this->post('/admin/companies', ['name' => 'HICOM Sixth', 'code' => 'h6', 'color' => '#123456', 'is_active' => 1])
            ->assertRedirect('/admin/companies');
        $six = Company::where('code', 'H6')->firstOrFail();

        // Duplicate code rejected.
        $this->post('/admin/companies', ['name' => 'Other', 'code' => 'H6', 'color' => '#123456'])->assertSessionHasErrors('code');

        // Company user must have a company.
        $this->post('/admin/users', ['name' => 'X', 'email' => 'x@six.test', 'role' => 'company_user', 'password' => 'Secret12345', 'password_confirmation' => 'Secret12345', 'is_active' => 1])
            ->assertSessionHasErrors('company_id');

        $this->post('/admin/users', ['name' => 'Six User', 'email' => 'Six@Six.test', 'role' => 'company_user', 'company_id' => $six->id,
            'password' => 'Secret12345', 'password_confirmation' => 'Secret12345', 'is_active' => 1])->assertRedirect('/admin/users');
        $u = User::where('email', 'six@six.test')->firstOrFail();
        $this->assertSame($six->id, $u->company_id);

        // A CEO never gets a company even if one is posted.
        $this->post('/admin/users', ['name' => 'Boss', 'email' => 'boss@hesb.test', 'role' => 'ceo', 'company_id' => $six->id,
            'password' => 'Secret12345', 'password_confirmation' => 'Secret12345', 'is_active' => 1]);
        $this->assertNull(User::where('email', 'boss@hesb.test')->value('company_id'));

        // The new company shows up in the group dashboard; deactivating hides it.
        $this->get('/group')->assertSee('HICOM Sixth');
        $this->put("/admin/companies/{$six->id}", ['name' => 'HICOM Sixth', 'code' => 'H6', 'color' => '#123456', 'is_active' => 0]);
        $this->get('/admin/companies'); // consumes the "updated" flash message
        $this->get('/group')->assertDontSee('HICOM Sixth');
        $this->post('/logout');
        $this->post('/login', ['email' => 'six@six.test', 'password' => 'Secret12345'])->assertSessionHasErrors('email');
    }

    #[Test]
    public function the_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->w['admin'];
        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'ceo', 'is_active' => 1,
        ])->assertSessionHasErrors('role');
        $this->assertSame(Role::Admin, $admin->fresh()->role);
    }

    #[Test]
    public function comparison_ranks_companies_and_tracker_shows_status(): void
    {
        $rev = FinancialLine::standard()->where('code', 'revenue')->value('id');
        foreach ([[$this->w['a'], '120.00'], [$this->w['b'], '80.00']] as [$c, $act]) {
            AmpTarget::create(['company_id' => $c->id, 'year' => 2026, 'month' => 1, 'financial_line_id' => $rev, 'amount' => '100.00']);
            Entry::create(['company_id' => $c->id, 'year' => 2026, 'month' => 1, 'week' => 1, 'financial_line_id' => $rev, 'actual' => $act]);
        }
        Entry::create(['company_id' => $this->w['a']->id, 'year' => 2026, 'month' => 11, 'week' => 2, 'financial_line_id' => $rev, 'actual' => '1']);

        $html = $this->actingAs($this->w['ceo'])->get('/group/compare?year=2026&month=1')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Bravo Sdn Bhd'), strpos($html, 'Alpha Sdn Bhd'));
        $this->assertStringContainsString('120.00%', $html);
        $this->assertStringContainsString('80.00%', $html);

        $this->get('/group/tracker?year=2026&month=11')->assertOk()
            ->assertSee('Submitted')->assertSee('Pending');
    }

    #[Test]
    public function audit_log_lists_and_filters(): void
    {
        $rev = FinancialLine::standard()->where('code', 'revenue')->value('id');
        $this->actingAs($this->w['userA'])->put('/entries/2026/11', ['cells' => [$rev => [1 => ['actual' => '55']]]]);

        $this->actingAs($this->w['admin'])->get('/admin/audit?action=entry')->assertOk()
            ->assertSee('entry.grid')->assertSee('55.00')->assertSee('revenue');
        $this->get("/admin/audit?company={$this->w['b']->id}")->assertDontSee('55.00');
    }
}
