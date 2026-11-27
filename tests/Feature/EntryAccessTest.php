<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\FinancialLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves a company user can't read or write another company's financial data by
 * direct URL or forged request, and that the CEO can read but never write.
 */
class EntryAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 10:00');
    }

    private function revenueId(): int
    {
        return FinancialLine::standard()->where('code', 'revenue')->value('id');
    }

    #[Test]
    public function company_user_cannot_read_another_companys_grid_or_template(): void
    {
        $w = $this->world();
        Entry::create(['company_id' => $w['b']->id, 'year' => 2026, 'month' => 11, 'week' => 1, 'financial_line_id' => $this->revenueId(), 'actual' => '777777.77']);

        $this->actingAs($w['userA']);
        $this->get("/group/companies/{$w['b']->id}/entries/2026/11")->assertForbidden();
        $this->get("/group/companies/{$w['b']->id}/entries/2026/11/template")->assertForbidden();

        // Their own grid never leaks B's numbers, whatever the query string says.
        $this->get("/entries/2026/11?company_id={$w['b']->id}")->assertOk()->assertDontSee('777,777.77')->assertSee('Alpha Sdn Bhd');
    }

    #[Test]
    public function company_user_cannot_write_another_companys_data(): void
    {
        $w = $this->world();
        $rev = $this->revenueId();
        $this->actingAs($w['userA']);

        // Group write URLs: blocked by role.
        $this->put("/group/companies/{$w['b']->id}/entries/2026/11", ['cells' => [$rev => [1 => ['actual' => '1']]]])->assertForbidden();
        $this->post("/group/companies/{$w['b']->id}/entries/2026/11/upload", ['file' => UploadedFile::fake()->create('x.xlsx', 5)])->assertForbidden();
        $this->post("/group/companies/{$w['b']->id}/lines", ['label' => 'Hack', 'group' => 'revenue'])->assertForbidden();

        // Forged company_id in the body is ignored: data lands in A.
        $this->put('/entries/2026/11', ['company_id' => $w['b']->id, 'cells' => [$rev => [1 => ['actual' => '5']]]])->assertSessionHasNoErrors();
        $this->assertSame(0, Entry::where('company_id', $w['b']->id)->count());
        $this->assertSame(1, Entry::where('company_id', $w['a']->id)->count());

        // Writing to B's custom line through A's own endpoint is rejected.
        $bLine = FinancialLine::factory()->create(['company_id' => $w['b']->id]);
        $this->put('/entries/2026/11', ['cells' => [$bLine->id => [1 => ['actual' => '5']]]])->assertSessionHasErrors("cells.{$bLine->id}");
        $this->assertSame(0, Entry::where('financial_line_id', $bLine->id)->count());

        // Deleting B's custom line, or a standard line: 404.
        $this->delete("/lines/{$bLine->id}")->assertNotFound();
        $this->delete("/lines/{$rev}")->assertNotFound();
        $this->assertNotNull($bLine->fresh());
    }

    #[Test]
    public function json_clients_get_403s_too(): void
    {
        $w = $this->world();
        $this->actingAs($w['userA'])
            ->putJson("/group/companies/{$w['b']->id}/entries/2026/11", ['cells' => []])
            ->assertForbidden();
        $this->getJson("/group/companies/{$w['b']->id}/entries/2026/11")->assertForbidden();
    }

    #[Test]
    public function ceo_can_view_any_grid_but_every_write_is_forbidden(): void
    {
        $w = $this->world();
        $rev = $this->revenueId();
        $b = $w['b']->id;
        $this->actingAs($w['ceo']);

        $html = $this->get("/group/companies/{$b}/entries/2026/11")->assertOk()->assertSee('View only')->getContent();
        $this->assertStringNotContainsString('Upload Excel', $html);
        $this->assertStringNotContainsString('Save November 2026', $html);
        $this->assertStringNotContainsString('+ Add Row', $html);
        $this->assertDoesNotMatchRegularExpression('/name="cells\[\d+\]\[1\]\[actual\]"(?![^>]*disabled)[^>]*>/s', $html);
        $this->get("/group/companies/{$b}/entries/2026/11/template")->assertOk();

        $this->put("/group/companies/{$b}/entries/2026/11", ['cells' => [$rev => [1 => ['actual' => '1']]]])->assertForbidden();
        $this->post("/group/companies/{$b}/entries/2026/11/upload", ['file' => UploadedFile::fake()->create('x.xlsx', 5)])->assertForbidden();
        $this->post("/group/companies/{$b}/lines", ['label' => 'X', 'group' => 'revenue'])->assertForbidden();
        $line = FinancialLine::factory()->create(['company_id' => $b]);
        $this->delete("/group/companies/{$b}/lines/{$line->id}")->assertForbidden();

        // Company-user write URLs are closed to the CEO as well.
        $this->put('/entries/2026/11', ['cells' => [$rev => [1 => ['actual' => '1']]]])->assertForbidden();

        $this->assertSame(0, Entry::count());
    }

    #[Test]
    public function admin_can_edit_any_company(): void
    {
        $w = $this->world();
        $rev = $this->revenueId();

        $this->actingAs($w['admin'])
            ->put("/group/companies/{$w['b']->id}/entries/2026/11", ['cells' => [$rev => [1 => ['actual' => '42']]]])
            ->assertRedirect("/group/companies/{$w['b']->id}/entries/2026/11");

        $this->assertSame('42.00', Entry::where('company_id', $w['b']->id)->value('actual'));
        $this->get("/group/companies/{$w['b']->id}/entries/2026/11")->assertSee('Save November 2026');
    }
}
