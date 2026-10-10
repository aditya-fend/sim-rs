# Tech Stack

## Languages and runtimes with versions
- **PHP 8.3**: Core server-side runtime executing the Laravel 11 application logic, custom Eloquent encryption casts, FormRequest validation pipelines, and interoperability gateway transformers.
- **Node.js 20.x LTS**: Build-time JavaScript runtime utilized by Vite 5 to bundle frontend assets, compile Tailwind CSS utilities, and execute Alpine.js component modules.
- **JavaScript (ES2022+ / Alpine.js 3.x)**: Client-side runtime executing lightweight reactive form bindings, Glasgow Coma Scale calculations, anatomical canvas coordinate mapping, and browser `localStorage` draft caching.

## Frontend
- **Laravel Blade**: Server-side rendering template engine optimizing initial page load performance and rendering dynamic form layouts across hospital workstations.
- **Tailwind CSS 3.4**: Utility-first CSS framework enforcing consistent design tokens, spacing grids, and high-contrast clinical UI layouts.
- **Alpine.js 3.x**: Lightweight declarative JavaScript framework handling reactive UI behaviors, client-side validation thresholds, and 5-second local draft auto-saves without heavy SPA overhead.
- **HTML5 Canvas / SVG**: Interactive anatomical pain mapping component capturing normalized coordinate percentages (`x`, `y`) and anatomical region metadata.

## Backend
- **Laravel 11**: Full-stack PHP framework providing robust MVC routing, Eloquent ORM, middleware pipeline execution, and event dispatching.
- **Spatie Laravel-Permission 6.x**: Role-Based Access Control package managing role segregation across Triage Nurses, Attending Doctors (DPJP), Medical Records Officers, and System Administrators.
- **Pest PHP 2.x & PHPUnit 10.x**: Automated testing frameworks executing unit, feature, and integration tests across clinical workflows and API gateways.
- **PHPStan (Level 8)**: Static analysis tool enforcing strict typing rules and eliminating edge-case runtime errors prior to deployment.

## Database
- **PostgreSQL 14+ (Primary Production)** / **MySQL 8.0 (Secondary)**: Relational database management systems supporting transactional integrity, native JSONB column storage, GIN indexing for dynamic schemas, and strict trigger execution.
- **Eloquent ORM**: Native Laravel database abstraction layer handling model relationships, query building, and attribute casting.
- **Custom Eloquent Casts (`EncryptedGcm`)**: Implements transparent AES-256-GCM authenticated encryption and decryption for sensitive patient records (`nik`, `phone`, `address`, `anamnesis`).

## Infrastructure and hosting
- **Docker & Docker Compose**: Containerization platform standardizing development, testing, and staging environments to match production cluster nodes.
- **Nginx 1.24**: High-performance HTTP server and reverse proxy terminating TLS 1.3 connections and passing FastCGI requests to PHP-FPM 8.3.
- **PHP-FPM 8.3**: Process manager executing PHP scripts with opcache enabled for optimal execution speed.
- **Redis 7.2**: In-memory data store powering session management, schema definition caching, and application queue worker job dispatching.
- **Virtual Private Server (VPS) / Cloud VM (e.g., AWS EC2 t3.medium or DigitalOcean Premium Droplet)**: Production compute hosting the containerized SIMRS application stack within regional Indonesian infrastructure.

## CI/CD
- **GitHub Actions**: Automated CI/CD pipeline orchestrating test execution, static analysis, and deployment routines.
- **PHP CS Fixer / Laravel Pint**: Automated code style enforcement ensuring adherence to PSR-12 coding standards.
- **Automated Test Pipeline**: Executes Pest PHP test suites and PHPStan Level 8 static analysis on every pull request merge.

## Package management
- **Composer 2.x**: PHP dependency manager locked via `composer.lock` to pin backend package versions.
- **npm 10.x**: JavaScript package manager managing Vite, Tailwind CSS, and Alpine.js dependencies via `package-lock.json`.

## Observability and logging
- **Laravel Log Manager / Monolog**: Application logging service capturing errors, exceptions, and operational warnings.
- **Immutable Audit Logging (`audit_logs`)**: Append-only tamper-evident database store recording user ID, role, IP address, user-agent, action verb (`VIEW`, `CREATE`, `UPDATE`, `LOCK`, `EXPORT`), and SHA-256 payload checksums per Permenkes No. 24/2022.
- **Health Check Endpoints (`/up`)**: Native monitoring routes reporting database connectivity, Redis cache availability, and disk storage metrics.

## Alternatives considered and why they were rejected
- **Single Page Application (React / Vue.js + Laravel API)**: Rejected because heavy SPA runtime overhead increases bundle size and risks performance degradation on entry-level hospital ward hardware and low-bandwidth local LAN connections. Blade + Alpine.js provides instant server-rendered feedback.
- **MongoDB / NoSQL Document Stores**: Rejected because relational integrity, strict foreign key constraints, and transactional guarantees are required for medical encounter records and Permenkes-compliant audit trails. PostgreSQL JSONB provides schema flexibility while preserving relational integrity.
- **Standard AES-CBC or AES-ECB Encryption**: Rejected because unauthenticated modes do not protect ciphertext integrity against tampering. AES-256-GCM is selected to ensure authenticated encryption with built-in integrity verification tags.

## Indicative monthly cost

| Resource Component | Specification / Sizing | Estimated Monthly Cost (USD) |
| :--- | :--- | :--- |
| **App Server VPS (Production)** | 2 vCPU, 4GB RAM, 80GB NVMe SSD (AWS EC2 / DigitalOcean Droplet) | $24.00 |
| **Database Server VPS** | 2 vCPU, 4GB RAM, 50GB NVMe SSD (PostgreSQL Managed / VPS) | $30.00 |
| **Redis Cache Instance** | 1 vCPU, 1GB RAM (Managed Redis / Dedicated Container) | $15.00 |
| **Backup & Object Storage** | 100GB Encrypted Backup Storage & Snapshot Retention | $10.00 |
| **Domain & SSL / DNS** | Custom hospital domain, wildcard TLS certificate | $5.00 |
| **Total Estimated Monthly Cost** | **Production-grade regional hospital deployment** | **$84.00 / month** |
## Implementation deviations from this document

Recorded during the build; each is a deliberate choice with a reason, not drift.

| Specified here | Implemented | Why |
| :--- | :--- | :--- |
| Laravel 11 | Laravel 12.69.3 | Laravel 11 is end-of-life and every 11.x release carries an unresolved security advisory. `composer audit` is clean on 12. |
| Vite 5 | Vite 7.3.7 | Vite 5 pulls `esbuild <= 0.24.2` (GHSA-67mh-4wv8-2f99) and `vite <= 5.4.19` (GHSA-g4jq-h2w9-997g); both are dev-server advisories with no patched 5.x release. |
| Tailwind CSS 3.4 | Tailwind CSS 4.3.3 | Same advisory chain: `@tailwindcss/vite 3.x` depends on the vulnerable Vite range, and Tailwind 3.4.x has no standalone fix. |
| Pest 2.x & PHPUnit 10.x | Pest 3.2 / PHPUnit 11.5.56 | Pest 2 is only compatible with PHPUnit 10, which is what drags in Laravel 11's test harness. |
| PHPStan (Level 8) | PHPStan 2.3.1 via Larastan | Level 8 as specified; Larastan adds the Eloquent-aware stubs that make that level reachable on a Laravel codebase. |

Additions beyond this document, each added because a referenced feature could not work without it:

- `dompdf/dompdf` — the Permenkes 24/2022 compliance PDF (AC-11).
- `justinrainbow/json-schema` — validating the form schema itself, not just submitted payloads.
- `laravel/telescope` — deployment.md's request/audit inspection requirement.
- `laravel/dusk` — the E2E-01..05 scenarios in testing.md.

`phpunit.xml` coverage is measured over `app/Http/Requests`, `app/Casts`,
`app/Services/FormEngine`, `app/Services/Clinical` and
`app/Services/Interoperability`, which is what the ≥90% target in testing.md
names. PCOV is the driver in CI and in the container image.
