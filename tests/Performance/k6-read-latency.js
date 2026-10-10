/**
 * testing.md §"Performance and Load Testing" — FHIR read latency.
 *
 * Target: < 250 ms under 50 concurrent requests (NFR-03).
 *
 *   k6 run -e BASE_URL=https://simrs.klinikform.local -e FAPI_TOKEN=... \
 *          -e ENCOUNTER_ID=... tests/Performance/k6-read-latency.js
 */
import http from 'k6/http';
import { check } from 'k6';

export const options = {
    scenarios: {
        fhir_reads: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '20s', target: 50 },
                { duration: '1m', target: 50 },
                { duration: '20s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<250'],
        http_req_failed: ['rate<0.01'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const TOKEN = __ENV.FAPI_TOKEN || '';
const ENCOUNTER_ID = __ENV.ENCOUNTER_ID || '';

const params = {
    headers: {
        Authorization: `Bearer ${TOKEN}`,
        Accept: 'application/json',
    },
};

export default function () {
    const encounter = http.get(`${BASE_URL}/api/v1/fhir/Encounter/${ENCOUNTER_ID}`, params);

    check(encounter, {
        'status is 200': (r) => r.status === 200,
        'is an FHIR Encounter': (r) => r.json('resourceType') === 'Encounter',
        'carries a subject': (r) => r.json('subject.reference') !== null,
    });

    http.get(`${BASE_URL}/api/v1/fhir/Condition?encounter=${ENCOUNTER_ID}`, params);
}
