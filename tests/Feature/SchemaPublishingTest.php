<?php

declare(strict_types=1);

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Services\FormEngine\SchemaRepository;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;

/**
 * FR-02 / AC-01.3 — a form definition is versioned, never edited in place.
 * A submission captured under v2 must keep rendering against v2 after v3 is
 * published, which is only possible if publishing inserts a new row.
 */
beforeEach(function (): void {
    seedFormTemplates();
    $this->admin = makeUser([RoleSeeder::ADMIN]);
    $this->active = FormTemplate::query()
        ->where('code', 'TRIAGE-ED-01')
        ->where('is_active', true)
        ->firstOrFail();
});

function definitionWithDescription(string $description): string
{
    $definition = json_decode(
        (string) file_get_contents(__DIR__.'/../../database/schemas/triage-ed-01.json'),
        true,
    );
    $definition['description'] = $description;

    return (string) json_encode($definition);
}

it('inserts the next version and retires the previous active row', function (): void {
    $this->actingAs($this->admin)
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            'schema_definition' => definitionWithDescription('Revisi 3.'),
            'activate' => '1',
        ])
        ->assertRedirect();

    $versions = FormTemplate::query()
        ->where('code', 'TRIAGE-ED-01')
        ->orderBy('version')
        ->get();

    expect($versions)->toHaveCount(2)
        ->and($versions->last()->version)->toBe($this->active->version + 1)
        ->and($versions->last()->is_active)->toBeTrue()
        ->and($this->active->fresh()->is_active)->toBeFalse()
        // The old row keeps its own definition untouched.
        ->and($versions->first()->schema_definition['description'])->not->toBe('Revisi 3.');
});

it('keeps exactly one active version per code', function (): void {
    $this->actingAs($this->admin)
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            'schema_definition' => definitionWithDescription('Revisi 3.'),
            'activate' => '1',
        ]);

    $this->actingAs($this->admin)
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            'schema_definition' => definitionWithDescription('Revisi 4.'),
            'activate' => '1',
        ]);

    $activeCount = FormTemplate::query()
        ->where('code', 'TRIAGE-ED-01')
        ->where('is_active', true)
        ->count();

    expect($activeCount)->toBe(1)
        ->and(FormTemplate::query()->where('code', 'TRIAGE-ED-01')->count())->toBe(3);
});

it('can publish without activating so a draft version waits for review', function (): void {
    $this->actingAs($this->admin)
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            'schema_definition' => definitionWithDescription('Revisi 3.'),
            // Absent `activate` defaults to true, so a version that should
            // wait for review has to be sent as 0.
            'activate' => '0',
        ]);

    $published = FormTemplate::query()
        ->where('code', 'TRIAGE-ED-01')
        ->orderByDesc('version')
        ->firstOrFail();

    expect($published->is_active)->toBeFalse()
        // The previously active version is still serving intake.
        ->and($this->active->fresh()->is_active)->toBeTrue();
});

it('refuses to publish a structurally invalid definition', function (): void {
    $this->actingAs($this->admin)
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            // A section without a key cannot be addressed by the renderer.
            'schema_definition' => (string) json_encode([
                'form_code' => 'TRIAGE-ED-01',
                'sections' => [['label' => 'Tanpa key', 'fields' => []]],
            ]),
        ])
        ->assertSessionHasErrors('schema_definition');

    expect(FormTemplate::query()->where('code', 'TRIAGE-ED-01')->count())->toBe(1);
});

it('refuses to publish malformed JSON without writing a version', function (): void {
    $this->actingAs($this->admin)
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            'schema_definition' => '{bukan json',
        ])
        ->assertSessionHasErrors('schema_definition');

    expect(FormTemplate::query()->where('code', 'TRIAGE-ED-01')->count())->toBe(1);
});

it('reports the active versions for a batch of codes', function (): void {
    $templates = app(SchemaRepository::class)
        ->activeByCode(['TRIAGE-ED-01', 'TRIAGE-ED-01', 'TIDAK-ADA-99']);

    expect($templates)->toHaveCount(1)
        ->and($templates['TRIAGE-ED-01']->version)->toBe($this->active->version);
});

it('resolves the exact version a submission was captured under', function (): void {
    $submission = new FormSubmission([
        'form_template_id' => $this->active->id,
        'schema_version' => $this->active->version,
    ]);
    $submission->setRelation('template', $this->active);

    $this->actingAs($this->admin)
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            'schema_definition' => definitionWithDescription('Revisi 3.'),
            'activate' => '1',
        ]);

    $resolved = app(SchemaRepository::class)->versionFor($submission);

    expect($resolved->id)->toBe($this->active->id)
        ->and($resolved->version)->toBe($this->active->version);
});

it('refuses a publish from a role without the permission', function (): void {
    $this->actingAs(makeUser([RoleSeeder::NURSE]))
        ->post("/admin/schemas/{$this->active->id}/publish", [
            'name' => $this->active->name,
            'schema_definition' => definitionWithDescription('Revisi 3.'),
        ])
        ->assertForbidden();

    expect(FormTemplate::query()->where('code', 'TRIAGE-ED-01')->count())->toBe(1);
});

it('throws a validation exception when the repository is called directly', function (): void {
    // The service is also reachable outside HTTP (imports, seeding), so the
    // guard has to hold at that layer too.
    expect(fn () => app(SchemaRepository::class)->publish(
        'TRIAGE-ED-01',
        'Broken',
        ['form_code' => 'TRIAGE-ED-01', 'sections' => []],
        $this->admin,
    ))->toThrow(ValidationException::class);
});
