<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\FormTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\FormEngine\SchemaRepository;
use App\Services\FormEngine\SchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * user-flow.md §Flow 3 — the SIMRS deployment engineer's schema manager.
 *
 * Publishing never edits a row. It validates the candidate definition and
 * inserts the next version, retiring the previous one, so every historical
 * submission keeps rendering against the schema it was captured under.
 */
class SchemaManagerController extends Controller
{
    public function __construct(
        private readonly SchemaRepository $schemas = new SchemaRepository,
        private readonly SchemaValidator $validator = new SchemaValidator,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function index(): View
    {
        $templates = FormTemplate::query()
            ->with('author')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->groupBy('code');

        return view('admin.schemas.index', ['templates' => $templates]);
    }

    public function edit(FormTemplate $template): View
    {
        return view('admin.schemas.edit', [
            'template' => $template,
            'json' => SchemaValidator::toJson($template->schema_definition),
            'history' => $this->schemas->history($template->code),
        ]);
    }

    /**
     * Dry-run validation. user-flow.md promises "0 Error, N Field Baru
     * Terdeteksi" before anything is written.
     */
    public function validate(Request $request): JsonResponse
    {
        $this->authorize('create', FormTemplate::class);

        $definition = $this->parse($request);

        if ($definition === null) {
            return response()->json([
                'valid' => false,
                'errors' => ['JSON tidak valid.'],
            ], 422);
        }

        $errors = $this->validator->validate($definition);

        $baseline = $request->input('baseline');
        $added = $baseline === null
            ? []
            : array_values(array_diff(
                $this->fieldNames($definition),
                $this->fieldNames(SchemaValidator::fromJson((string) $baseline)),
            ));

        return response()->json([
            'valid' => $errors === [],
            'errors' => $errors,
            'new_fields' => $added,
            'summary' => sprintf(
                'Validasi %s: %d Error, %d Field Baru Terdeteksi',
                $errors === [] ? 'Berhasil' : 'Gagal',
                count($errors),
                count($added),
            ),
        ]);
    }

    public function publish(Request $request, FormTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $definition = $this->parse($request);

        if ($definition === null) {
            return back()->withErrors(['schema_definition' => 'JSON tidak valid.']);
        }

        $errors = $this->validator->validate($definition);

        if ($errors !== []) {
            return back()->withErrors(['schema_definition' => $errors])->withInput();
        }

        $published = $this->schemas->publish(
            $template->code,
            $request->input('name', $template->name),
            $definition,
            $this->currentUser($request),
            activate: $request->boolean('activate', true),
        );

        $this->audit->record(
            AuditAction::Create,
            FormTemplate::class,
            $published->id,
            ['code' => $published->code, 'version' => $published->version],
            $request,
        );

        return redirect()
            ->route('admin.schemas.edit', $published)
            ->with('status', sprintf('Schema v%d diterbitkan & diaktifkan.', $published->version));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(Request $request): ?array
    {
        try {
            return SchemaValidator::fromJson((string) $request->input('schema_definition', ''));
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function fieldNames(array $definition): array
    {
        $names = [];

        foreach ($definition['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (isset($field['name'])) {
                    $names[] = (string) $field['name'];
                }
            }
        }

        return $names;
    }
}
