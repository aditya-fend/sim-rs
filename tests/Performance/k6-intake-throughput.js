/**
 * testing.md §"Performance and Load Testing" — concurrent intake submissions.
 *
 * Target: zero failed requests under a 100 VU spike (testing.md), and the
 * submission stays under the three-minute clinical entry budget (NFR success
 * metrics).
 *
 *   k6 run -e BASE_URL=http://127.0.0.1:8000 -e SESSION=... \
 *          -e ENCOUNTER_ID=... -e TEMPLATE_ID=... \
 *          -e CSRF_TOKEN=... tests/Performance/k6-intake-throughput.js
 */
import http from 'k6/http';
import { check } from 'k6';

export const options = {
    scenarios: {
        intake_spike: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '15s', target: 100 },
                { duration: '45s', target: 100 },
                { duration: '15s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['count==0'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const ENCOUNTER_ID = __ENV.ENCOUNTER_ID;
const TEMPLATE_ID = __ENV.TEMPLATE_ID;
const CSRF_TOKEN = __ENV.CSRF_TOKEN;
const SESSION = __ENV.SESSION || '';

// X-Idempotency-Key keeps the spike from writing the same submission twice.
const IDEMPOTENCY_KEY = `k6-${__VU}-${__ITER}`;

export default function () {
    const response = http.post(
        `${BASE_URL}/api/v1/encounters/${ENCOUNTER_ID}/submissions`,
        JSON.stringify({
            form_template_id: TEMPLATE_ID,
            payload_data: {
                arrival_time: new Date().toISOString().slice(0, 16),
                transport_mode: 'walker',
                triage_category: 'urgent',
                triage_nurse: 'k6',
                systolic: 130,
                diastolic: 85,
                heart_rate: 92,
                respiratory_rate: 20,
                body_temperature: 37.1,
                oxygen_saturation: 98,
                chief_complaint: 'Nyeri ulu hati sejak 2 hari',
                gcs: { eye: 3, verbal: 4, motor: 5, total: 12, category: 'apatis' },
            },
        }),
        {
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'X-Idempotency-Key': IDEMPOTENCY_KEY,
                Cookie: `laravel_session=${SESSION}`,
            },
        },
    );

    check(response, {
        'created': (r) => r.status === 201,
        'gcs recomputed server-side': (r) => r.json('data.gcs_total') === 12,
    });
}
