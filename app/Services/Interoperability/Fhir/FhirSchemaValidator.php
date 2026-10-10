<?php

declare(strict_types=1);

namespace App\Services\Interoperability\Fhir;

use JsonSchema\Validator;

/**
 * Validates generated payloads against the official HL7 FHIR R4 JSON Schema
 * (AC-06.3, testing.md release criterion "100% schema validation pass rate").
 *
 * The schema itself is vendored at tests/Fixtures/fhir-r4/fhir.schema.json
 * (downloaded from hl7.org/fhir/R4) rather than fetched at test time, so a
 * network outage cannot turn a conformance check into a skipped one.
 *
 * The file is a single draft-06 document holding all 680 resource definitions,
 * so a resource of type X validates against `{ "$ref": "#/definitions/X" }`
 * with the whole `definitions` map carried alongside the $ref.
 */
final class FhirSchemaValidator
{
    private const SCHEMA_PATH = 'tests/Fixtures/fhir-r4/fhir.schema.json';

    /** @var array<string, mixed>|null */
    private static ?array $definitions = null;

    /**
     * @param  array<string, mixed>  $resource
     * @return list<string> Human-readable violations; empty means conformant.
     */
    public function validate(array $resource): array
    {
        $type = $resource['resourceType'] ?? null;

        if (! is_string($type) || $type === '') {
            return ['Resource is missing a resourceType.'];
        }

        if (! isset($this->definitions()[$type])) {
            return ["FHIR R4 schema has no definition for resource type '{$type}'."];
        }

        $validator = new Validator;

        // Format checking is left off deliberately: FHIR R4 states that
        // `format` is documentation and a consumer must not fail on it. The
        // assertion that matters here is structural — required elements,
        // cardinalities and value types.
        //
        // The resource's own definition becomes the root schema rather than
        // being reached through a $ref. FHIR's definitions point at each other
        // with "#/definitions/X", which only resolves when the definitions map
        // is in scope from the root; handing over just {"$ref": ...} silently
        // validates nothing at all.
        // The transformer returns PHP arrays, but the validator reads a PHP
        // associative array as a JSON *array*, not a JSON object. A round trip
        // through json_encode/json_decode turns objects back into stdClass,
        // which is what the schema engine expects.
        $document = json_decode((string) json_encode($resource), false, 512, JSON_THROW_ON_ERROR);

        $validator->validate($document, $this->rootSchemaFor($type));

        $errors = [];

        foreach ($validator->getErrors() as $error) {
            $errors[] = sprintf('[%s] %s', (string) ($error['property'] ?? '?'), (string) $error['message']);
        }

        return $errors;
    }

    /** @param array<string, mixed> $resource */
    public function isValid(array $resource): bool
    {
        return $this->validate($resource) === [];
    }

    /**
     * The resource's definition promoted to the root, with the shared
     * definitions map attached so its `#/definitions/...` references resolve.
     *
     * @return array<string, mixed>
     */
    private function rootSchemaFor(string $type): array
    {
        $definitions = $this->definitions();

        /** @var array<string, mixed> $schema */
        $schema = $definitions[$type];

        // `id` would re-point the internal $refs at a URI the resolver cannot
        // fetch offline, so it is dropped along with the discriminator, which
        // only means anything when the root oneOf is present.
        unset($schema['id'], $schema['discriminator'], $schema['$schema']);
        $schema['$schema'] = 'http://json-schema.org/draft-06/schema#';
        $schema['definitions'] = $this->definitions();

        return $schema;
    }

    /**
     * Load the definitions once and repair them for offline validation.
     *
     * HL7's generated schema omits "type": "object" from every definition that
     * has `properties`. JSON Schema only requires `properties` to apply to
     * objects, but validators generally gate `required` and
     * `additionalProperties` on an explicit type — so without this repair a
     * resource missing a required element would validate clean. Injecting it
     * across the whole map rather than at the root also covers nested
     * definitions such as Coding and CodeableConcept.
     *
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $path = base_path(self::SCHEMA_PATH);

        if (! is_file($path)) {
            throw new \RuntimeException(
                "FHIR R4 schema fixture missing at {$path}; the schema conformance check cannot be skipped silently."
            );
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $definitions */
        $definitions = $decoded['definitions'] ?? [];

        return self::$definitions = $this->repair($definitions);
    }

    /**
     * @param  array<string, mixed>  $definitions
     * @return array<string, mixed>
     */
    private function repair(array $definitions): array
    {
        foreach ($definitions as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (! isset($definition['type']) && isset($definition['properties'])) {
                $definitions[$name]['type'] = 'object';
            }
        }

        return $definitions;
    }
}
