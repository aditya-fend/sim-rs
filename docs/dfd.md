# Data Flow Diagram

## Context Diagram (Level 0)

The Context Diagram (Level 0) defines the system boundary of **KlinikForm SIMRS**. The subsystem acts as a secure, metadata-driven clinical intake engine that interfaces human actors across hospital wards with regulatory health authorities and insurance clearinghouses.

```mermaid
graph TD
    subgraph KlinikForm SIMRS Subsystem
        P0["0.0 KlinikForm SIMRS Subsystem<br/>(Laravel 11 Core, Dynamic Form Engine, AES-256 Cryptography & Interoperability Gateway)"]
    end

    EE_Nurse["Nurse (Triage & Ward)<br/>[Triage & Intake Operations]"]
    EE_Doctor["Attending Doctor (DPJP)<br/>[Diagnostic & Clinical Sign-Off]"]
    EE_Admin["SIMRS Admin / Implementer<br/>[Schema & Configuration Management]"]
    EE_RekamMedis["Medical Records Officer<br/>[Audit & Compliance Review]"]
    EE_SatuSehat["Kemenkes SatuSehat Platform<br/>[National HL7 FHIR R4 Repository]"]
    EE_BPJS["BPJS Kesehatan VClaim Server<br/>[Insurance Eligibility & SEP Gateway]"]

    %% Nurse Flows
    EE_Nurse -->|"1. Patient NIK / RM Lookup & Intake Data<br/>2. GCS Values & Pain Canvas Coordinates"| P0
    P0 -->|"1. Active Intake Schema & Calculated Scores<br/>2. Local Draft Recovery Prompts & Submission Status"| EE_Nurse

    %% Doctor Flows
    EE_Doctor -->|"1. Worklist Request & Objective Findings<br/>2. ICD-10 Diagnostic Search & Cryptographic Sign-Off"| P0
    P0 -->|"1. Decrypted Nurse Anamnesis & Patient History<br/>2. Locked Record Verification Stamp"| EE_Doctor

    %% Admin Flows
    EE_Admin -->|"1. JSON Schema Definition Updates (v1.x)<br/>2. Bridging Configuration & Test Payloads"| P0
    P0 -->|"1. Schema Validation Reports & Version Logs<br/>2. Interoperability Handshake Status"| EE_Admin

    %% Medical Records Flows
    EE_RekamMedis -->|"1. Permenkes 24/2022 Audit Filters<br/>2. Archival & Compliance Report Requests"| P0
    P0 -->|"1. Immutable Audit Trail Logs<br/>2. Signed Integrity Checksum Verification Reports"| EE_RekamMedis

    %% SatuSehat Interoperability
    P0 -->|"1. FHIR R4 Encounter Payload (JSON)<br/>2. FHIR R4 Condition Payload (ICD-10)"| EE_SatuSehat
    EE_SatuSehat -->|"1. IHS Patient ID & IHS Practitioner ID<br/>2. Transaction Acknowledgement (HTTP 200/201)"| P0

    %% BPJS VClaim Interoperability
    P0 -->|"1. HMAC-SHA256 Auth Headers (ConsID, Timestamp, Signature)<br/>2. SEP Creation Payload (Kartu BPJS, Poli, Referral)"| EE_BPJS
    EE_BPJS -->|"1. 19-Digit SEP Number & Participant Coverage Status<br/>2. VClaim Error Response (e.g., Code 401 Timestamp Expired)"| P0
```

### Context Boundary Description
- **System Boundary**: Encompasses the Laravel 11 application layer, dynamic JSON schema renderer, custom Eloquent AES-256-GCM encryption cast, blind index calculation engine, client-side Alpine.js state managers, append-only audit logger, and outbound FHIR/VClaim API serializers.
- **Inputs**: User credentials, 16-digit patient NIKs, vital sign measurements, Glasgow Coma Scale (GCS) sub-scores, anatomical pain map relative percentages, free-text anamnesis notes, ICD-10 diagnosis selections, versioned JSON schema definitions, and BPJS verification queries.
- **Outputs**: Rendered Blade dynamic forms, real-time calculated GCS totals and pediatric drug dosages, encrypted database persistence, locked digital record stamps with SHA-256 payload signatures, HL7 FHIR R4 `Encounter` and `Condition` JSON bundles, 19-character BPJS *Surat Eligibilitas Peserta* (SEP) strings, and Permenkes No. 24/2022 compliance audit exports.

---

## Level 1 Decomposition

The Level 1 Data Flow Diagram decomposes Process 0.0 into six discrete functional processes, illustrating data movement between processing engines, field-encrypted data stores, and external systems.

```mermaid
graph TD
    %% External Entities
    EE_Nurse["Nurse (Triage & Ward)"]
    EE_Doctor["Attending Doctor (DPJP)"]
    EE_Admin["SIMRS Admin / Implementer"]
    EE_RekamMedis["Medical Records Officer"]
    EE_SatuSehat["Kemenkes SatuSehat Platform"]
    EE_BPJS["BPJS Kesehatan VClaim Server"]

    %% Processes
    P1["1.0 Authentication & Access Control Engine"]
    P2["2.0 Patient Identifier Lookup & Cryptographic Engine"]
    P3["3.0 Dynamic Form Schema & Rendering Engine"]
    P4["4.0 Clinical Intake & Sign-Off Processing Engine"]
    P5["5.0 Regulatory Compliance & Access Audit Logger"]
    P6["6.0 External Interoperability & Bridging Gateway"]

    %% Data Stores
    D1[("D1: users & roles")]
    D2[("D2: patients")]
    D3[("D3: encounters")]
    D4[("D4: form_templates")]
    D5[("D5: form_submissions")]
    D6[("D6: draft_submissions")]
    D7[("D7: audit_logs")]
    D8[("D8: bpjs_sep_records")]

    %% Process 1 Flows
    EE_Nurse -->|"Login Credentials"| P1
    EE_Doctor -->|"Login Credentials"| P1
    EE_Admin -->|"Login Credentials"| P1
    EE_RekamMedis -->|"Login Credentials"| P1
    P1 -->|"Query User & Roles"| D1
    D1 -->|"User Session & Permissions"| P1
    P1 -->|"Authenticated Context & User UUID"| P3
    P1 -->|"Authenticated Context & Role Gate"| P4
    P1 -->|"Log Authentication Event"| P5

    %% Process 2 Flows
    EE_Nurse -->|"Plain NIK / MRN Input"| P2
    P2 -->|"HMAC-SHA256 Hash Query (nik_hash)"| D2
    D2 -->|"Encrypted Data (AES-256-GCM)"| P2
    P2 -->|"Decrypt Fields (NIK, Phone, Address)"| P2
    P2 -->|"Decrypted Patient Context"| P3
    P2 -->|"Log Patient Read Event"| P5

    %% Process 3 Flows
    EE_Admin -->|"Publish JSON Schema (v1.x)"| P3
    P3 -->|"Write Versioned Schema"| D4
    D4 -->|"Active JSON Schema Definition"| P3
    P3 -->|"Render Dynamic Form Fields"| EE_Nurse
    EE_Nurse -->|"Debounced Auto-Save Inputs"| P3
    P3 -->|"Sync 72h Draft Payload"| D6

    %% Process 4 Flows
    EE_Nurse -->|"Submit Clinical Intake Payload"| P4
    EE_Doctor -->|"Objective Findings, ICD-10 & Sign-Off"| P4
    D6 -->|"Retrieve Draft State"| P4
    P4 -->|"Calculate GCS & AES-256 Anamnesis"| P4
    P4 -->|"Read Encounter & Patient Context"| D3
    P4 -->|"Persist Intake Submission & Lock Record"| D5
    P4 -->|"Purge Completed Draft"| D6
    P4 -->|"Trigger Status Transition (LOCKED)"| D3
    P4 -->|"Dispatch Execution Event"| P5
    P4 -->|"Trigger Asynchronous FHIR & SEP Sync"| P6

    %% Process 5 Flows
    EE_RekamMedis -->|"Audit Filter Criteria"| P5
    P5 -->|"Append Immutable Log (No Update/Delete)"| D7
    D7 -->|"Audit Records & SHA-256 Checksums"| P5
    P5 -->|"Render Permenkes 24/2022 Audit Trail"| EE_RekamMedis

    %% Process 6 Flows
    P6 -->|"Fetch Signed Payload & ICD-10 Code"| D5
    P6 -->|"Fetch Encounter & Patient Metadata"| D3
    P6 -->|"Fetch Encrypted Patient Identifiers"| D2
    P6 -->|"Serialize HL7 FHIR Encounter / Condition"| EE_SatuSehat
    EE_SatuSehat -->|"Return IHS IDs & Sync Status"| P6
    P6 -->|"Calculate HMAC-SHA256 Signature Header"| P6
    P6 -->|"POST /sep/insert Payload"| EE_BPJS
    EE_BPJS -->|"Return 19-Digit SEP Response"| P6
    P6 -->|"Persist VClaim Transaction & SEP"| D8
    P6 -->|"Log API Interoperability Event"| P5
```

---

## External Entities

The system interfaces with four internal human user roles and two external government/insurer network entities.

| Entity ID | Entity Name | Type | Description | Primary Data Inputs to System | Primary Data Outputs from System | Protocol / Interface | Trust Level |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **EE-01** | **Triage & Ward Nurse** | Human Persona | Frontline nurse executing physical intake in IGD or inpatient wards. | Credentials, Patient NIK, Vital signs, GCS scores, Pain coordinates, Free-text anamnesis. | Dynamic forms, Validation feedback, Calculated GCS/dosages, Saved status indicators. | HTTPS / Web Browser (Blade + Alpine.js) | Authenticated (Role: `Triage Nurse`) |
| **EE-02** | **Attending Doctor (DPJP)** | Human Persona | Examining physician responsible for diagnostic sign-off and treatment. | Credentials, Objective exam notes, ICD-10 search queries, Prescription overrides, Sign-off trigger. | Worklist, Decrypted nurse intake, ICD-10 suggestions, Locked record status, Digital stamp. | HTTPS / Web Browser (Blade + Alpine.js) | Authenticated (Role: `DPJP`) |
| **EE-03** | **SIMRS Admin / Engineer** | Human Persona | Implementer managing hospital ward schemas and bridging setups. | Credentials, Versioned JSON form schemas, Field mapping rules, API staging credentials. | Schema validation logs, Syntax error banners, System metrics, Version activation history. | HTTPS / Web Browser (Blade Admin UI) | Authenticated (Role: `Admin`) |
| **EE-04** | **Medical Records Officer** | Human Persona | Regulatory officer overseeing Permenkes No. 24/2022 compliance. | Credentials, Audit search filters (date range, RM number, action type), Export requests. | Access audit trails, Checksum verification states, Permenkes compliance PDF reports. | HTTPS / Web Browser (Compliance UI) | Authenticated (Role: `Rekam Medis`) |
| **EE-05** | **Kemenkes SatuSehat Platform** | Remote System | Indonesian MoH national health data repository (HL7 FHIR R4). | IHS Patient ID, IHS Practitioner ID, Resource sync HTTP status (200/201/400/500). | HL7 FHIR R4 `Encounter` JSON resources, HL7 FHIR R4 `Condition` JSON resources. | HTTPS / REST API (OAuth2 + JSON) | External Boundary (TLS 1.3 Strict) |
| **EE-06** | **BPJS Kesehatan VClaim Server** | Remote System | National health insurance clearinghouse for SEP claim verification. | SEP creation response (`noSep`, `catatan`), HTTP status, BPJS error metadata. | Authorization headers (`X-cons-id`, `X-timestamp`, `X-signature`, `user_key`), SEP request payload. | HTTPS / REST API (HMAC-SHA256 Auth) | External Boundary (TLS 1.3 Strict) |

---

## Data Stores

The system maintains eight logical data stores supporting field-level encryption, dynamic JSON schema querying, auto-save buffers, and append-only auditing.

| Store ID | Data Store Name | Primary Entity / Table | Physical Storage Engine | Encryption & Security Constraints | Access Operations (Read / Write) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **D1** | **User Credentials & RBAC Store** | `users`, `roles`, `model_has_roles` | PostgreSQL / MySQL Relational | Passwords hashed via Bcrypt/Argon2id. Sensitive practitioner NIKs blind-indexed (`nik_hash`). | **Read**: P1 (Auth), P4 (Sign-off), P5 (Audit).<br/>**Write**: System setup / Admin management. |
| **D2** | **Encrypted Patient Registry** | `patients` | PostgreSQL / MySQL Relational | `nik_encrypted`, `phone_encrypted`, `address_encrypted` encrypted via AES-256-GCM. `nik_hash` HMAC-SHA256 indexed. | **Read**: P2 (Lookup), P4 (Intake), P6 (Bridging).<br/>**Write**: P2 (Patient creation/update). |
| **D3** | **Clinical Encounters Registry** | `encounters` | PostgreSQL / MySQL Relational | Unencrypted operational visit records. Soft locks enforced upon status transition to `finished`. | **Read**: P4 (Intake), P6 (Bridging), P5 (Audit).<br/>**Write**: P4 (Status transition, DPJP assignment). |
| **D4** | **Dynamic Form Schema Store** | `form_templates` | PostgreSQL / MySQL (`JSONB` column) | Schema definitions stored as versioned JSONB. Partial unique index guarantees single active version per code. | **Read**: P3 (Rendering), P4 (Validation).<br/>**Write**: P3 (Schema version creation by Admin). |
| **D5** | **Clinical Submissions Store** | `form_submissions` | PostgreSQL / MySQL (`JSONB` column) | Narrative anamnesis encrypted via AES-256-GCM inside JSON/text. SHA-256 `signature_hash` enforces immutability when `status = 'LOCKED'`. | **Read**: P4 (Doctor review), P6 (Bridging), P5 (Audit).<br/>**Write**: P4 (Nurse intake save, Doctor sign-off). Update/Delete blocked when LOCKED. |
| **D6** | **Auto-Save Draft Storage** | `draft_submissions` | PostgreSQL / MySQL (`JSONB` column) + LocalStorage | Ephemeral buffers with a 72-hour `expires_at` TTL. Purged immediately upon form submission. | **Read**: P3 (Draft restore), P4 (Submission conversion).<br/>**Write**: P3 (Debounced sync), P4 (Draft purge). |
| **D7** | **Immutable Access Audit Trail** | `audit_logs` | PostgreSQL / MySQL Append-Only Table | Append-only store. Database rules/triggers reject all SQL `UPDATE` and `DELETE` commands. Payload checksums stored as SHA-256. | **Read**: P5 (Compliance review).<br/>**Write**: P5 (Append log event from P1, P2, P3, P4, P6). Modifying existing rows strictly blocked. |
| **D8** | **BPJS VClaim Claims Registry** | `bpjs_sep_records` | PostgreSQL / MySQL Relational | Stores calculated HMAC signatures, request timestamps, 19-digit SEP strings, and raw JSON response payloads. | **Read**: P6 (Claim review), EE-01/02 (SEP print).<br/>**Write**: P6 (SEP generation persistence). |

---

## Process Specifications

### Process 1.0: Authentication & Access Control Engine

```mermaid
graph TD
    A[Incoming Request] --> B{Valid Session?}
    B -->|No| C[Prompt Credentials / Challenge]
    C --> D[Verify Bcrypt Password Hash in D1]
    D -->|Invalid| E[Log Auth Failure to P5 & Reject]
    D -->|Valid| F[Issue Session & Resolve Spatie Roles]
    B -->|Yes| G[Resolve Authenticated User Context]
    F --> H[Evaluate Policy Gate for Route]
    G --> H
    H -->|Authorized| I[Pass Context to P2, P3, P4, or P5]
    H -->|Unauthorized| J[Log Authorization Barrier Violation to P5 & Return HTTP 403]
```

#### Processing Logic & Rules
1. **Credential Verification**:
   - Accepts login credentials (`email`, `password`). Queries `users` table in **D1**.
   - Computes `Hash::check(password, user.password)`. If invalid, triggers `P5.0` (`action = 'LOGIN_FAILED'`) and returns HTTP 401.
2. **Role & Policy Resolution**:
   - Loads Spatie roles and permissions from `model_has_roles` and `roles` in **D1**.
   - Evaluates Laravel Gate policies (`NursePolicy`, `DoctorPolicy`, `AdminPolicy`, `AuditPolicy`) for requested route actions:
     - `Nurse`: Permitted to execute `P2.0` (lookup), `P3.0` (drafting), `P4.0` (submit intake). Blocked from signing assessments or publishing schemas.
     - `DPJP Doctor`: Permitted to execute `P2.0`, `P4.0` (edit medical findings, assign ICD-10, sign & lock). Blocked from editing schemas.
     - `Admin`: Permitted to execute `P3.0` (publish schemas), manage users. Blocked from modifying signed clinical notes without emergency flags.
     - `Rekam Medis`: Permitted to execute `P5.0` (view audit trails, export reports). Read-only clinical access.
3. **Session Context Propagation**:
   - Binds authenticated `user_id`, active `role_name`, client `ip_address`, and `user_agent` to execution context for downstream audit logging.

---

### Process 2.0: Patient Identifier Lookup & Cryptographic Engine

```mermaid
graph TD
    A[Incoming Search Query: NIK or MRN] --> B{Input Type?}
    B -->|16-Digit NIK| C[Compute HMAC-SHA256 Hash using BLIND_INDEX_PEPPER]
    C --> D["Query D2: SELECT * FROM patients WHERE nik_hash = ?"]
    B -->|Medical Record Number| E["Query D2: SELECT * FROM patients WHERE medical_record_number = ?"]
    D --> F{Record Found?}
    E --> F
    F -->|No| G[Return HTTP 404 / Prompt Patient Registration]
    F -->|Yes| H[Retrieve Ciphertext Row from D2]
    H --> I[Execute Custom Eloquent Cast: Decrypt AES-256-GCM]
    I --> J[Extract IV & Auth Tag from Base64 String]
    J --> K[Decrypt nik_encrypted, phone_encrypted, address_encrypted]
    K --> L[Log Patient Access Event to P5]
    L --> M[Return Decrypted Patient Entity to Caller]
```

#### Processing Logic & Rules
1. **Blind Index Computation**:
   - For NIK search (`3315012345670001`), normalizes input string (strips spaces/dashes).
   - Computes deterministic blind index:
     $$\text{nik\_hash} = \text{hash\_hmac}('sha256', \text{nik\_normalized}, \text{config}('app.blind\_index\_pepper'))$$
   - Queries store **D2**: `SELECT * FROM patients WHERE nik_hash = :nik_hash LIMIT 1`. Executed in $O(1)$ time without scanning unencrypted rows.
2. **AES-256-GCM Field Decryption**:
   - Retrieves stored Base64 string from `nik_encrypted`, `phone_encrypted`, or `address_encrypted`.
   - Decodes Base64 structure containing: `payload` (ciphertext), `iv` (12-byte initialization vector), and `tag` (16-byte authentication tag).
   - Decrypts using OpenSSL:
     $$\text{plaintext} = \text{openssl\_decrypt}(\text{ciphertext}, \text{'aes-256-gcm'}, \text{APP\_KEY}, 0, \text{iv}, \text{tag})$$
   - If tag validation fails (indicating database tampering), throws a `CryptographicIntegrityException` and alerts `P5.0`.
3. **Audit Attribution**:
   - Dispatches read event to `P5.0` recording `action = 'VIEW'`, `resource_type = 'Patient'`, `resource_id = patient.id`.

---

### Process 3.0: Dynamic Form Schema & Rendering Engine

```mermaid
graph TD
    A[Request Form Interface: Form Code & Encounter ID] --> B["Query D4: SELECT * FROM form_templates WHERE code = ? AND is_active = TRUE"]
    B --> C[Parse schema_definition JSONB Payload]
    C --> D[Initialize Alpine.js Form Renderer State]
    D --> E["Check D6 for Active Draft (encounter_id, template_id, user_id)"]
    E -->|Draft Found| F[Prompt User: Restore Unsaved Local Draft?]
    F -->|Restore| G[Populate Active State from D6 / LocalStorage]
    F -->|Discard| H[Populate State from Schema Default Values]
    E -->|No Draft| H
    G --> I[Render Reactive UI Controls]
    H --> I
    I --> J[Execute Client Auto-Save Loop every 5s]
    J --> K["Debounced Sync to D6 (expires_at = NOW() + 72h)"]
```

#### Processing Logic & Rules
1. **Schema Retrieval & Version Binding**:
   - Receives form code (e.g., `TRIAGE-ED-01`) and encounter UUID.
   - Queries **D4** for active version (`is_active = TRUE`). Parses `schema_definition` JSON structure containing field configurations, validation constraints, and conditional visibility rules.
   - If rendering a historical submission, queries **D4** using the specific `schema_version` bound to the submission record rather than the latest version.
2. **Dynamic Rule Evaluation**:
   - Binds Alpine.js reactive components to JSON directives:
     - Conditional evaluation: `travel_endemic_area` field displays only when `body_temperature >= 38.0`.
     - Numeric boundary checks: Systolic BP enforced strictly within range $[40, 300]\text{ mmHg}$.
3. **Dual-Tier Draft Buffering**:
   - Client writes state to browser `localStorage` key `draft_{encounter_id}_{template_id}` every 5 seconds.
   - Debounced background AJAX worker posts state to **D6** (`draft_submissions`) every 30 seconds, maintaining server-side draft recovery across device crashes.

---

### Process 4.0: Clinical Intake & Sign-Off Processing Engine

```mermaid
graph TD
    A[Form Submission Payload Received] --> B[Execute FormRequest Server-Side Validation]
    B -->|Validation Failed| C[Return HTTP 422 with Field Error Bag]
    B -->|Validation Passed| D{Action Type?}
    D -->|Nurse Intake Save| E[Compute GCS Total & Pain Canvas Coordinates]
    E --> F[Encrypt Confidential Anamnesis Notes via AES-256-GCM]
    F --> G["Persist Submission to D5 (status = 'SUBMITTED')"]
    G --> H["Purge Draft from D6 & LocalStorage"]
    D -->|DPJP Doctor Sign-Off| I{Verify User Role == DPJP}
    I -->|No| J[Return HTTP 403 Unauthorized]
    I -->|Yes| K[Verify ICD-10 Code & Objective Findings]
    K --> L[Generate SHA-256 Signature Hash]
    L --> M["Update D5: Set signature_hash, signed_by, signed_at, status = 'LOCKED'"]
    M --> N["Update D3: Set encounter status = 'finished'"]
    G --> O[Trigger P5.0 Audit Log & P6.0 Bridging Gateway]
    N --> O
```

#### Processing Logic & Rules
1. **Dynamic Validation & Clinical Calculations**:
   - Validates incoming request parameters against schema rules stored in **D4**.
   - Computes Glasgow Coma Scale (GCS) total:
     $$\text{GCS}_{\text{total}} = \text{Eye} (1\text{--}4) + \text{Verbal} (1\text{--}5) + \text{Motor} (1\text{--}6)$$
     Assigns consciousness category: $\text{Coma} (3)$, $\text{Sopor} (4\text{--}8)$, $\text{Somnolent} (9\text{--}11)$, $\text{Apatis} (12\text{--}14)$, $\text{Compos Mentis} (15)$.
   - Converts anatomical pain markers into normalized JSON arrays: `[{"x": 48.2, "y": 32.5, "region": "epigastrium", "severity": 7}]`.
2. **Anamnesis Encryption**:
   - Encrypts confidential narrative notes using AES-256-GCM prior to SQL `INSERT` into `form_submissions.encrypted_anamnesis` in **D5**.
3. **Cryptographic Sign-Off & Lock Enforcement**:
   - Upon DPJP sign-off request, verifies user holds role `DPJP` via **D1**.
   - Formulates signature concatenation payload:
     $$\text{sig\_payload} = \text{submission\_id} + \text{payload\_json} + \text{dpjp\_user\_id} + \text{timestamp}$$
   - Calculates cryptographic SHA-256 hash:
     $$\text{signature\_hash} = \text{hash}('sha256', \text{sig\_payload})$$
   - Sets `status = 'LOCKED'`, `signature_hash`, `signed_by`, and `signed_at`.
   - Post-condition: Database triggers block subsequent SQL `UPDATE` and `DELETE` commands on locked submission UUIDs in **D5**.
4. **Draft Clearance & Pipeline Escalation**:
   - Purges corresponding draft row in **D6**.
   - Dispatches event to `P5.0` (`action = 'LOCK'`) and queues background jobs for `P6.0`.

---

### Process 5.0: Regulatory Compliance & Access Audit Logger

```mermaid
graph TD
    A[Incoming System Event Stream] --> B[Extract Event Context: User ID, Role, Action, Resource, IP, Payload]
    B --> C[Compute Payload SHA-256 Checksum]
    C --> D["Assemble Audit Row (user_id, role_name, action, resource_type, resource_id, ip_address, user_agent, payload_checksum)"]
    D --> E["Execute SQL INSERT into D7 (audit_logs)"]
    E --> F[Database Rule Blocks UPDATE & DELETE Commands]
    G[Medical Records Officer Request] --> H{Verify User Role == Rekam Medis or Admin}
    H -->|No| I[Return HTTP 403 Forbidden]
    H -->|Yes| J[Query D7 using Date Range, Patient RM, or User Filters]
    J --> K[Re-Calculate Checksums to Verify Log Integrity]
    K --> L[Render Audit Trail UI / Export Permenkes 24/2022 Report]
```

#### Processing Logic & Rules
1. **Event Capture & Checksum Generation**:
   - Intercepts all application events (`VIEW`, `CREATE`, `UPDATE`, `LOCK`, `EXPORT`, `LOGIN`).
   - Computes SHA-256 checksum of target resource state:
     $$\text{payload\_checksum} = \text{hash}('sha256', \text{json\_encode}(\text{target\_model\_attributes}))$$
2. **Immutable Persistence**:
   - Appends audit row to store **D7** (`audit_logs`).
   - Enforces table immutability: Store **D7** has explicit database rules overriding update/delete attempts (`ON UPDATE DO INSTEAD NOTHING`, `ON DELETE DO INSTEAD NOTHING`).
3. **Compliance Audit Inspection**:
   - Receives query filters from Medical Records Officer via **D1** authorized session.
   - Returns paginated audit trail. Verifies payload checksums against active database states; flags discrepancies as potential unauthorized database modifications.

---

### Process 6.0: External Interoperability & Bridging Gateway

```mermaid
graph TD
    A[Asynchronous Interoperability Trigger] --> B{Integration Target?}

    %% SatuSehat Sub-Flow
    B -->|SatuSehat HL7 FHIR| C["Fetch Submission & Encounter Data from D5, D3, D2"]
    C --> D[Transform Patient to FHIR R4 Patient Resource]
    C --> E[Transform Encounter to FHIR R4 Encounter Resource]
    C --> F[Transform ICD-10 Diagnosis to FHIR R4 Condition Resource]
    D --> G[Validate JSON Payloads against HL7 FHIR R4 Schemas]
    E --> G
    F --> G
    G -->|Validation Error| H[Log Mapping Error to P5 & Flag Pending]
    G -->|Valid| I[Dispatch OAuth2 REST POST to SatuSehat EE-05]
    I --> J["Update D3 with satusehat_encounter_id"]

    %% BPJS VClaim Sub-Flow
    B -->|BPJS VClaim Simulator| K["Fetch Patient & Encounter Claims Metadata from D2, D3"]
    K --> L[Retrieve UTC Timestamp in Seconds]
    L --> M[Calculate HMAC-SHA256 Auth Signature]
    M --> N["Format Request Headers (X-cons-id, X-timestamp, X-signature, user_key)"]
    N --> O[Dispatch POST /sep/insert Payload to BPJS EE-06]
    O --> P{Check Server Timestamp Drift <= 300s}
    P -->|Drift > 300s| Q[Return HTTP 401: Timestamp Kadaluarsa]
    P -->|Valid| R[Receive 19-Digit SEP Number String]
    R --> S["Persist Claims Transaction into D8 (bpjs_sep_records)"]
    S --> T[Log Interoperability Execution to P5]
```

#### Processing Logic & Rules
1. **SatuSehat HL7 FHIR R4 Mapping**:
   - Fetches locked submission data from **D5**, encounter from **D3**, and decrypted patient from **D2**.
   - Serializes data into HL7 FHIR R4 resource definitions:
     - `Encounter`: `status` (`arrived` $\rightarrow$ `finished`), `class` (`EMER` or `IMP`), `subject` (`Patient/{ihs_patient_id}`), `participant` (`Practitioner/{ihs_practitioner_id}`), `period` (`admission_time` to `discharge_time`).
     - `Condition`: `clinicalStatus` (`active`), `verificationStatus` (`confirmed`), `code.coding` (`system: "http://hl7.org/fhir/sid/icd-10"`, `code: primary_icd10_code`).
   - Dispatches payload to Kemenkes SatuSehat REST API (**EE-05**). Updates `satusehat_encounter_id` in **D3** upon HTTP 201 response.
2. **BPJS VClaim HMAC-SHA256 Handshake & SEP Generation**:
   - Calculates BPJS authorization signature header using UTC timestamp $T$:
     $$\text{sig\_string} = \text{ConsID} + \text{"\&"} + T$$
     $$\text{X-signature} = \text{base64\_encode}(\text{hash\_hmac}('sha256', \text{sig\_string}, \text{SecretKey}, \text{true}))$$
   - Sends request to BPJS VClaim Gateway (**EE-06**) with headers: `X-cons-id`, `X-timestamp`, `X-signature`, `user_key`.
   - Validates that server timestamp $T_{\text{server}}$ satisfies $|T_{\text{client}} - T_{\text{server}}| \le 300\text{ seconds}$. If drifted, returns HTTP 401 with BPJS code `401: Timestamp kadaluarsa`.
   - Upon successful verification, receives 19-character SEP string formatted as `0301R001{MM}{YY}B{7-digit-counter}` and stores transaction payload in **D8** (`bpjs_sep_records`). Logs event to `P5.0`.

---

## Trust Boundaries

The system is compartmentalized into four distinct security trust boundaries to enforce defense-in-depth isolation across networks, application runtime layers, persistent data stores, and external government API boundaries.

```mermaid
graph TD
    subgraph TB1["Trust Boundary 1: Client Web Browser Environment (Untrusted Network Zone)"]
        UI["Blade Views + Alpine.js Dynamic Form Controls"]
        LocalStore["Browser LocalStorage (Transient Auto-Save Drafts)"]
    end

    subgraph TB2["Trust Boundary 2: Application / MVC Core Layer (Secured HTTPS / TLS 1.3 Zone)"]
        AuthMiddleware["P1.0 Authentication & RBAC Policy Gates"]
        ValidationEngine["P3.0 & P4.0 Dynamic Form Request Validation"]
        CryptoCastEngine["P2.0 & P4.0 AES-256-GCM Encryption / Decryption Casts"]
        AuditEngine["P5.0 Permenkes 24/2022 Audit Dispatcher"]
    end

    subgraph TB3["Trust Boundary 3: Database & Persistence Layer (Restricted Storage Zone)"]
        RelationalDB[("D1, D3, D4, D5, D6, D8 Database Tables")]
        EncryptedFields[("D2 & D5 Encrypted Columns (AES-256-GCM Ciphertext)")]
        AppendOnlyAudit[("D7 Append-Only Audit Trail (Update/Delete Disabled)")]
    end

    subgraph TB4["Trust Boundary 4: External Regulatory & Integration Zone (National Networks)"]
        SatuSehatAPI["EE-05 Kemenkes SatuSehat Platform (HL7 FHIR R4)"]
        BPJSAPI["EE-06 BPJS Kesehatan VClaim Gateway"]
    end

    %% Crossing Connections
    UI <-->|"1. HTTPS / TLS 1.3 (Bearer Token / Session Cookie)"| AuthMiddleware
    LocalStore -.->|"Transient Local Auto-Save (Wiped on HTTP 201)"| UI

    AuthMiddleware -->|"2. Authenticated Context"| ValidationEngine
    ValidationEngine -->|"3. Unencrypted Model Operations"| CryptoCastEngine
    CryptoCastEngine <-->|"4. Plaintext <-> Ciphertext Conversion using APP_KEY"| RelationalDB
    CryptoCastEngine <-->|"5. Blind Index HMAC Queries using PEPPER"| EncryptedFields
    AuditEngine -->|"6. Immutable Append SQL INSERT"| AppendOnlyAudit

    ValidationEngine -->|"7. Outbound REST Payload (OAuth2 / HMAC Headers)"| SatuSehatAPI
    ValidationEngine -->|"8. Outbound VClaim SEP Handshake"| BPJSAPI
```

### Trust Boundary Details

| Boundary ID | Boundary Name | Cross-Boundary Data Flows | Inbound Protection Mechanisms | Outbound Sanitization / Encryption | Risk & Mitigation |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TB-01** | **Client Web Browser Zone** | User inputs $\rightarrow$ System;<br/>Rendered forms $\rightarrow$ User. | Session cookies with `HttpOnly`, `Secure`, `SameSite=Strict`. CSRF tokens on all POST requests. | Inputs sanitized via HTML entity encoding; XSS protection in Blade templating (`{{ }}`). | **Risk**: Unsubmitted draft exposure on shared hospital workstations.<br/>**Mitigation**: LocalStorage draft keys purged immediately upon HTTP 201 receipt; auto-wipe after 72 hours. |
| **TB-02** | **Application / MVC Core Zone** | HTTP Requests $\rightarrow$ Controllers;<br/>Controllers $\rightarrow$ DB Models / APIs. | Authentication middleware (`auth:web`), Spatie RBAC Gates/Policies, FormRequest schema validation. | Decrypted patient attributes restricted to memory scope during execution; never rendered in debug logs. | **Risk**: Privilege escalation / unauthorized clinical edit.<br/>**Mitigation**: Strict role checks (`Nurse` vs `DPJP`); record status locking prevents edits once signed. |
| **TB-03** | **Database Persistence Zone** | App Models $\rightarrow$ DB Tables;<br/>DB Tables $\rightarrow$ App Models. | PostgreSQL/MySQL socket access isolated to application host IP. Cryptographic keys injected via env. | Fields `nik`, `phone`, `address`, `anamnesis` encrypted via AES-256-GCM. NIK hashed via HMAC-SHA256 pepper. | **Risk**: Plaintext exposure during database backup leaks or infrastructure breach.<br/>**Mitigation**: 100% encryption at rest for sensitive fields; audit table triggers block updates/deletes. |
| **TB-04** | **External Integration Zone** | App $\rightarrow$ SatuSehat / BPJS;<br/>SatuSehat / BPJS $\rightarrow$ App. | Mutual TLS / TLS 1.3 encryption, OAuth2 bearer tokens, HMAC-SHA256 signature verification. | Strips internal system identifiers; formats identity to national standard specs (`nik`, `ihs_id`, `bpjs_card`). | **Risk**: Replay attacks / timestamp spoofing on BPJS VClaim claim submissions.<br/>**Mitigation**: Enforces strict $\pm 300\text{ second}$ timestamp validation window; unique HMAC signatures per request. |

### Data Crossing Verification Matrix

1. **Crossing TB-01 to TB-02 (Browser to Application Server)**:
   - All incoming form data must pass `FormRequest` validation. Unvalidated JSON inputs are rejected with HTTP 422 before reaching business logic or data persistence services.
2. **Crossing TB-02 to TB-03 (Application Server to Persistent Database)**:
   - Sensitive fields pass through custom Eloquent Casts (`EncryptedGcm`). The engine generates a unique 12-byte IV per write operation, executes AES-256-GCM encryption using `APP_KEY`, and appends a 16-byte authentication tag. Plaintext sensitive data never crosses into raw SQL queries.
3. **Crossing TB-02 to TB-04 (Application Server to External Gateways)**:
   - Patient identity attributes are mapped to official government standards (NIK mapped to `https://fhir.kemkes.go.id/id/nik`). Headers calculated using SHA-256 HMAC prevent tampering during network transmission to Kemenkes SatuSehat or BPJS Kesehatan.