/**
 * KlinikForm SIMRS front-end runtime.
 *
 * Everything clinical is recomputed here for immediate feedback, and the
 * server recomputes it again on submit. The two implementations are separate
 * on purpose — one runs in the ward browser, the other runs against the
 * database — but they are held to the same behaviour by tests/Unit.
 */

/* eslint-env browser */

/* -------------------------------------------------------------------------- */
/* Glasgow Coma Scale — mirrors App\Services\Clinical\GcsCalculator            */
/* -------------------------------------------------------------------------- */

const GCS_BANDS = [
    { max: 3, key: 'coma', label: 'Coma' },
    { max: 8, key: 'sopor', label: 'Sopor' },
    { max: 11, key: 'somnolent', label: 'Somnolent' },
    { max: 14, key: 'apatis', label: 'Apatis' },
    { max: 15, key: 'compos_mentis', label: 'Compos Mentis' },
];

export function gcsBand(total) {
    return GCS_BANDS.find((band) => total <= band.max);
}

export function registerGcsCalculator(Alpine) {
    Alpine.data('gcsWidget', (initial = {}, readonly = false, target = null) => ({
        parts: {
            eye: initial.eye ?? null,
            verbal: initial.verbal ?? null,
            motor: initial.motor ?? null,
        },
        readonly,
        // The submit path POSTs the root payload directly rather than the form,
        // so the widget owns its state but must publish it upward. See sync().
        target,

        init() {
            if (!this.target) return;
            this.$watch('parts', () => this.sync());
            this.sync();
        },

        // total and category are sent because the schema validates them, but
        // the server does not trust them: GcsCalculator recomputes both from
        // the raw parts before they are stored.
        sync() {
            this.target.gcs = {
                eye: this.parts.eye === null ? null : Number(this.parts.eye),
                verbal: this.parts.verbal === null ? null : Number(this.parts.verbal),
                motor: this.parts.motor === null ? null : Number(this.parts.motor),
                total: this.total,
                category: this.category,
            };
        },

        get complete() {
            return [this.parts.eye, this.parts.verbal, this.parts.motor].every(
                (v) => v !== null && v !== undefined && v !== '',
            );
        },

        get total() {
            if (!this.complete) return null;
            return Number(this.parts.eye) + Number(this.parts.verbal) + Number(this.parts.motor);
        },

        get category() {
            return this.complete ? gcsBand(this.total).key : null;
        },

        get label() {
            return this.complete ? gcsBand(this.total).label : '';
        },
    }));
}

/* -------------------------------------------------------------------------- */
/* Pediatric dosage — mirrors App\Services\Clinical\DosageCalculator           */
/* -------------------------------------------------------------------------- */

export function registerDosageCalculator(Alpine) {
    Alpine.data('dosageWidget', (rules = [], weightField = 'weight_kg', target = null) => ({
        rules,
        weightField,
        drug: null,
        override: null,
        reason: '',
        target,

        init() {
            if (!this.target) return;
            this.$watch('drug', () => this.sync());
            this.$watch('override', () => this.sync());
            this.$watch('reason', () => this.sync());
            this.$watch('weight', () => this.sync());
            this.sync();
        },

        sync() {
            this.target.pediatric_dosage = this.result === null
                ? {}
                : {
                    drug: this.result.drug,
                    dose_mg: this.displayedDose,
                    doses_per_day: this.result.doses_per_day,
                    dose_per_day_mg: this.result.dose_per_day_mg,
                    max_daily_mg: this.result.max_daily_mg,
                    exceeds_max: this.result.exceeds_max,
                    override_reason: this.override === null || this.override === '' ? '' : this.reason,
                };
        },

        get rule() {
            return this.rules.find((r) => r.drug === this.drug) ?? null;
        },

        get weight() {
            return this.target?.[this.weightField] ?? null;
        },

        get result() {
            if (!this.rule) return null;

            const weight = Number(this.weight ?? 0);
            if (!Number.isFinite(weight) || weight <= 0) return null;

            const dosePerAdministration = weight * Number(this.rule.mg_per_kg_per_dose);
            const dosePerDay = dosePerAdministration * Number(this.rule.doses_per_day);
            const maxPerDay = weight * Number(this.rule.max_mg_per_kg_per_day ?? 0);

            return {
                drug: this.rule.drug,
                dose_mg: round(dosePerAdministration),
                doses_per_day: Number(this.rule.doses_per_day),
                dose_per_day_mg: round(dosePerDay),
                max_daily_mg: round(maxPerDay),
                exceeds_max: maxPerDay > 0 && dosePerDay > maxPerDay,
            };
        },

        get displayedDose() {
            if (this.result === null) return null;
            if (this.override === null || this.override === '') return this.result.dose_mg;
            return Number(this.override);
        },
    }));
}

/* -------------------------------------------------------------------------- */
/* Anatomical pain map — mirrors PainMarkerNormalizer + BodyMap                */
/* -------------------------------------------------------------------------- */

export function registerPainCanvas(Alpine) {
    Alpine.data('painCanvas', (initial = [], readonly = false, target = null) => ({
        markers: Array.isArray(initial) ? initial.map((m) => ({ ...m })) : [],
        readonly,
        focused: false,
        pending: null,
        severity: 0,
        reticle: { x: 50, y: 40 },
        target,

        init() {
            if (!this.target) return;
            this.$watch('markers', () => this.sync());
            this.sync();
        },

        sync() {
            this.target.pain_markers = this.markers.map((m) => ({
                x: Number(m.x),
                y: Number(m.y),
                region: m.region,
                severity: Number(m.severity),
            }));
        },

        get visiblePins() {
            return this.markers;
        },

        get band() {
            if (this.severity === 0) return 'Tidak Nyeri';
            if (this.severity <= 3) return 'Ringan';
            if (this.severity <= 6) return 'Sedang';
            return 'Berat';
        },

        onClick(event) {
            if (this.readonly) return;

            const point = this.toPercent(event.currentTarget, event.clientX, event.clientY);
            // The hit areas carry data-region, so the click target already knows
            // which zone it was; keyboard placement falls back to the reticle.
            const region = event.target?.dataset?.region ?? 'unknown';

            this.reticle = point;
            this.pending = { ...point, region };
            this.severity = 0;
        },

        // NFR-08: arrows move the reticle, Enter drops a pin, 0-9 set severity.
        onKeydown(event) {
            if (this.readonly) return;

            const step = event.shiftKey ? 5 : 1;

            switch (event.key) {
                case 'ArrowUp':
                    this.reticle.y = clamp(this.reticle.y - step);
                    break;
                case 'ArrowDown':
                    this.reticle.y = clamp(this.reticle.y + step);
                    break;
                case 'ArrowLeft':
                    this.reticle.x = clamp(this.reticle.x - step);
                    break;
                case 'ArrowRight':
                    this.reticle.x = clamp(this.reticle.x + step);
                    break;
                case 'Enter': {
                    if (!this.pending) {
                        this.pending = { ...this.reticle, region: 'unknown' };
                        this.severity = 0;
                    }
                    break;
                }
                default:
                    if (/^[0-9]$/.test(event.key) && this.pending) {
                        this.severity = Number(event.key);
                    }
                    return;
            }

            event.preventDefault();
        },

        confirm() {
            if (!this.pending) return;

            this.markers.push({
                x: clamp(this.pending.x),
                y: clamp(this.pending.y),
                region: this.pending.region,
                severity: clampSeverity(this.severity),
            });

            this.pending = null;
            this.$nextTick(() => this.$dispatch('pain-markers-changed'));
        },

        remove(index) {
            this.markers.splice(index, 1);
        },

        // Percentages of the element box, so pins survive a resize.
        toPercent(element, clientX, clientY) {
            const rect = element.getBoundingClientRect();
            return {
                x: round(clamp(((clientX - rect.left) / rect.width) * 100)),
                y: round(clamp(((clientY - rect.top) / rect.height) * 100)),
            };
        },

    }));
}

/* -------------------------------------------------------------------------- */
/* The form engine itself                                                     */
/* -------------------------------------------------------------------------- */

export function registerFormEngine(Alpine) {
    Alpine.data('clinicalForm', (config) => ({
        templateId: config.templateId,
        endpoint: config.endpoint,
        draftEndpoint: config.draftEndpoint,
        draftKey: config.draftKey,
        encounterId: config.encounterId,

        payload: config.initial ?? {},
        errors: {},
        submitting: false,
        submitted: false,
        draftStatus: 'saved',
        lastSavedAt: null,
        recoveredAt: null,

        localIntervalMs: (config.localIntervalSeconds ?? 5) * 1000,
        serverIntervalMs: (config.serverIntervalSeconds ?? 30) * 1000,

        init() {
            // AC-04.2: offer to restore before anything is written over.
            const stored = this.readLocalDraft();

            if (stored) {
                this.recoveredAt = stored.saved_at;
            }

            // 5 s local buffer, 30 s server sync — architecture.md.
            this.localTimer = setInterval(() => this.persistLocal(), this.localIntervalMs);
            this.serverTimer = setInterval(() => this.syncServer(), this.serverIntervalMs);

            window.addEventListener('beforeunload', () => this.persistLocal());

            this.$watch('payload', () => this.persistLocal(), { deep: true });
        },

        destroy() {
            clearInterval(this.localTimer);
            clearInterval(this.serverTimer);
        },

        /* --- localStorage draft (AC-04.1) -------------------------------- */

        persistLocal() {
            // Nothing left to protect once the server has acknowledged the
            // submission (AC-04.3); without this guard the autosave timer
            // recreates the key the submit handler just purged.
            if (this.submitted) return;

            // AC-04.2: while a stored draft is still waiting to be restored or
            // discarded, the autosave would overwrite the very thing the
            // recovery banner is offering.
            if (this.recoveredAt) return;

            try {
                localStorage.setItem(
                    this.draftKey,
                    JSON.stringify({
                        payload: this.payload,
                        saved_at: new Date().toISOString(),
                    }),
                );
                this.draftStatus = 'saved';
                this.lastSavedAt = new Date();
            } catch {
                // Quota exceeded or private mode: the 30 s server sync is the
                // remaining line of defence (R-03).
                this.draftStatus = 'offline_cached';
            }
        },

        readLocalDraft() {
            try {
                const raw = localStorage.getItem(this.draftKey);
                if (!raw) return null;

                const parsed = JSON.parse(raw);
                return parsed && typeof parsed === 'object' ? parsed : null;
            } catch {
                return null;
            }
        },

        restore() {
            const stored = this.readLocalDraft();
            if (!stored) return;

            // Mutated in place, not reassigned: the nested widgets hold a
            // reference to this exact object (see the sync() methods above).
            Object.assign(this.payload, stored.payload);
            this.recoveredAt = null;
        },

        discard() {
            localStorage.removeItem(this.draftKey);
            this.recoveredAt = null;

            for (const key of Object.keys(this.payload)) {
                delete this.payload[key];
            }
        },

        /* --- server draft (R-03) ----------------------------------------- */

        async syncServer() {
            if (!navigator.onLine) {
                this.draftStatus = 'offline_cached';
                return;
            }

            this.draftStatus = 'saving';

            try {
                const response = await fetch(this.draftEndpoint, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                    },
                    body: JSON.stringify({
                        encounter_id: this.encounterId,
                        form_template_id: this.templateId,
                        draft_payload: this.payload,
                    }),
                });

                this.draftStatus = response.ok ? 'saved' : 'offline_cached';
                if (response.ok) this.lastSavedAt = new Date();
            } catch {
                this.draftStatus = 'offline_cached';
            }
        },

        /* --- submission ---------------------------------------------------- */

        async submit() {
            this.submitting = true;
            this.errors = {};

            try {
                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                    },
                    body: JSON.stringify({
                        form_template_id: this.templateId,
                        payload_data: this.payload,
                    }),
                });

                const body = await response.json();

                if (response.status === 422) {
                    // AC-01.2 / ui-spec.md: focus the first invalid field.
                    this.errors = body?.error?.details ?? {};
                    this.focusFirstError();
                    return;
                }

                if (!response.ok) {
                    this.draftStatus = 'offline_cached';
                    window.dispatchEvent(
                        new CustomEvent('klinikform:toast', {
                            detail: { tone: 'error', message: body?.error?.message ?? 'Simpan gagal.' },
                        }),
                    );
                    return;
                }

                // AC-04.3: the draft is only discarded once the server has
                // acknowledged with 201.
                localStorage.removeItem(this.draftKey);
                this.submitted = true;
                window.dispatchEvent(
                    new CustomEvent('klinikform:toast', {
                        detail: {
                            tone: 'success',
                            message: 'Data Triase Berhasil Disimpan',
                            body,
                        },
                    }),
                );
            } catch {
                this.draftStatus = 'offline_cached';
                window.dispatchEvent(
                    new CustomEvent('klinikform:toast', {
                        detail: {
                            tone: 'warning',
                            message: 'Koneksi Terputus. Draft tersimpan lokal.',
                        },
                    }),
                );
            } finally {
                this.submitting = false;
            }
        },

        focusFirstError() {
            this.$nextTick(() => {
                const first = Object.keys(this.errors)[0];
                if (!first) return;

                const el = document.querySelector(`[name*="${first}"]`);
                el?.focus();
                el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        },

        csrfToken() {
            return document.querySelector('meta[name=csrf-token]')?.content ?? '';
        },
    }));
}

/* -------------------------------------------------------------------------- */
/* Helpers                                                                     */
/* -------------------------------------------------------------------------- */

function clamp(value) {
    return Math.max(0, Math.min(100, Number(value) || 0));
}

function clampSeverity(value) {
    return Math.max(0, Math.min(10, Number(value) || 0));
}

function round(value) {
    return Math.round(Number(value) * 100) / 100;
}
/* -------------------------------------------------------------------------- */
/* Page-level components                                                       */
/* -------------------------------------------------------------------------- */

/**
 * Patient lookup (AC-05.3, user-flow.md §Flow 1 step 1).
 *
 * Registered here rather than in a page script: Alpine.start() runs when this
 * module is evaluated, so an inline `alpine:init` listener added afterwards
 * would never fire and the component would silently not exist.
 */
export function registerPatientLookup(Alpine) {
    Alpine.data('patientLookup', (config = {}) => ({
        nik: config.initial ?? '',
        loading: false,
        error: null,
        result: null,

        get formUrl() {
            return `/portal/nurse/intake/${this.result.open_encounter_id}`;
        },

        async search() {
            this.error = null;
            this.result = null;

            const value = this.nik.replace(/\D/g, '');

            if (value.length !== 16) {
                this.error = 'NIK harus terdiri dari 16 digit angka.';
                return;
            }

            this.loading = true;

            try {
                const response = await fetch(`/api/v1/patients/search?nik=${value}`, {
                    headers: { Accept: 'application/json' },
                });

                const body = await response.json();

                if (response.status === 404) {
                    this.error = 'Pasien dengan NIK tersebut tidak ditemukan.';
                    return;
                }

                if (!response.ok) {
                    this.error = body?.error?.message ?? 'Pencarian gagal.';
                    return;
                }

                this.result = body.data;
            } catch {
                this.error = 'Koneksi terputus. Periksa jaringan intranet.';
            } finally {
                this.loading = false;
            }
        },

        // A patient with no open visit still needs an encounter before the
        // intake form can be opened.
        async createEncounter() {
            this.error = null;
            this.loading = true;

            try {
                const response = await fetch('/api/v1/encounters', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        patient_id: this.result.id,
                        encounter_type: 'EMER',
                        service_unit: 'IGD',
                    }),
                });

                const body = await response.json();

                if (!response.ok) {
                    this.error = body?.error?.message ?? 'Kunjungan gagal dibuat.';
                    return;
                }

                window.location.assign(`/portal/nurse/intake/${body.data.id}`);
            } catch {
                this.error = 'Koneksi terputus. Periksa jaringan intranet.';
            } finally {
                this.loading = false;
            }
        },
    }));
}

/**
 * Live summary rail beside the intake form.
 */
export function registerFormSummary(Alpine) {
    Alpine.data('formSummary', () => ({
        get triageLabel() {
            return window.KlinikForm.triage[this.payload.triage_category]?.label ?? '—';
        },
        get bloodPressure() {
            if (!this.payload.systolic || !this.payload.diastolic) return '—';
            return `${this.payload.systolic}/${this.payload.diastolic} mmHg`;
        },
        get peakPain() {
            const markers = this.payload.pain_markers;
            if (!Array.isArray(markers) || markers.length === 0) return '—';
            return `${Math.max(...markers.map((m) => Number(m.severity) || 0))}/10`;
        },
    }));
}

/**
 * DPJP sign-off (AC-08, user-flow.md §Flow 2 step 5).
 */
export function registerAssessmentForm(Alpine) {
    Alpine.data('assessmentForm', (submissionId, endpointTemplate) => ({
        submissionId,
        endpointTemplate,
        selectedCode: '',
        objective_findings: '',
        errors: {},
        signing: false,

        async submit() {
            if (!this.submissionId) return;

            this.signing = true;
            this.errors = {};

            try {
                const response = await fetch(
                    this.endpointTemplate.replace('{submission}', this.submissionId),
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify({
                            primary_icd10_code: this.selectedCode,
                            objective_findings: this.objective_findings,
                        }),
                    },
                );

                const body = await response.json();

                if (response.status === 422) {
                    this.errors = body?.error?.details ?? {};
                    return;
                }

                // 409 means someone else signed it first; reload to show the
                // locked record rather than an error.
                if (response.status === 409 || response.ok) {
                    window.location.reload();
                    return;
                }

                toast('error', body?.error?.message ?? 'Penandatanganan gagal.');
            } catch {
                toast('warning', 'Koneksi terputus. Coba lagi.');
            } finally {
                this.signing = false;
            }
        },
    }));
}

/**
 * Schema editor (user-flow.md §Flow 3): dry-run validation then publish.
 */
export function registerSchemaEditor(Alpine) {
    Alpine.data('schemaEditor', (json, currentVersion, validateUrl, name = '') => ({
        json,
        // Left undefined the #name input renders empty, and its `required`
        // attribute then silently blocks the publish submit.
        name,
        // Declared for the same reason: x-model="activate" on an undeclared
        // property raises a ReferenceError that aborts the submit handler.
        activate: true,
        baseline: json,
        nextVersion: Number(currentVersion) + 1,
        valid: false,
        publishing: false,
        errors: [],
        newFields: [],
        summary: null,

        async validate() {
            const response = await fetch(validateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ schema_definition: this.json, baseline: this.baseline }),
            });

            const body = await response.json();

            this.valid = body.valid === true;
            this.errors = body.errors ?? [];
            this.newFields = body.new_fields ?? [];
            this.summary = body.summary ?? (body.errors ?? ['JSON tidak valid.']).join(' ');
        },

        // The form arrives from the submit event: Alpine has no $root magic,
        // and this.$el is null inside the handler, so neither can be used to
        // reach it.
        publish(form) {
            this.publishing = true;

            form.querySelector('#schema_definition').value = this.json;
            form.submit();
        },
    }));
}

/* -------------------------------------------------------------------------- */
/* Shared helpers                                                             */
/* -------------------------------------------------------------------------- */

function csrfToken() {
    return document.querySelector('meta[name=csrf-token]')?.content ?? '';
}

/** Surface an outcome in the shared toast region (role="status"). */
export function toast(tone, message, body = null) {
    window.dispatchEvent(new CustomEvent('klinikform:toast', { detail: { tone, message, body } }));
}
