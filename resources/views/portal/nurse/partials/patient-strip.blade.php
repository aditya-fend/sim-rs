{{--
    c-patient-strip — ui-spec.md component inventory.

    Persistent, non-scrolling context of the patient under assessment, so a
    nurse working through a queue cannot type a reading into the wrong record.
    NIK is always masked (security.md §PII Handling).
--}}
@php
    $triage = $encounter?->submissions()->latest('id')->first()?->triage_category;
@endphp

<div class="sticky top-14 z-20 flex h-12 items-center gap-4 border-b border-border-subtle bg-surface-subtle px-4 sm:px-6">
    @if ($patient === null)
        {{-- unassigned state: grey, and clearly says so. --}}
        <span class="pill bg-surface-subtle text-text-muted">Pasien belum dipilih</span>
    @else
        <span class="text-heading-sm text-text-primary">{{ $patient->full_name }}</span>

        <span class="hidden text-body-sm text-text-secondary sm:inline">
            RM <span class="font-mono-sm text-text-primary">{{ $patient->medical_record_number }}</span>
        </span>
        <span class="hidden text-body-sm text-text-secondary sm:inline">
            NIK <span class="font-mono-sm text-text-primary">{{ $patient->maskedNik() }}</span>
        </span>
        <span class="hidden text-body-sm text-text-secondary md:inline">
            {{ $patient->ageInYears() }} thn / {{ $patient->gender->shortLabel() }}
        </span>

        {{-- Triage urgency, with the label always present so colour is never
             the only signal (ui-spec.md §Accessibility). --}}
        @if ($triage !== null)
            <span class="pill ml-auto {{ $triage->presentation()['css'] }}">
                {{ $triage->presentation()['label'] }}
                <span class="font-mono-sm">({{ $triage->presentation()['code'] }})</span>
            </span>
        @else
            <span class="pill ml-auto bg-surface-base text-text-muted">Belum ditriase</span>
        @endif
    @endif
</div>