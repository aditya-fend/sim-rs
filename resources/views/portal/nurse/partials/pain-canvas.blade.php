{{--
    c-widget-pain-canvas — ui-spec.md component inventory.

    Coordinates are stored as percentages of the viewBox rather than pixels, so
    a marker dropped on a 10" ward tablet lands in the same anatomical place on
    a 1920px DPJP workstation (FR-06).

    Keyboard path (NFR-08): Tab reaches the map, arrows move the reticle, Enter
    drops a pin, and 0-9 set the NRS severity without touching the pointer.
--}}
@props(['name' => 'pain_markers', 'readonly' => false, 'regions' => []])

@php
    $markers = old('payload.'.$name, []);
    $bands = ['Tidak Nyeri', 'Ringan', 'Sedang', 'Berat'];
@endphp

<div class="card p-4" x-data="painCanvas(@js($markers), @js($readonly), payload)">
    <p class="text-heading-sm">Peta Nyeri Anatomis</p>

    <div class="mt-3 grid gap-4 sm:grid-cols-2">
        @foreach ([['anterior', 'Tampak Depan'], ['posterior', 'Tampak Belakang']] as [$view, $caption])
            <figure>
                <figcaption class="mb-1 text-caption text-text-secondary">{{ $caption }}</figcaption>

                <div class="relative rounded-md border border-border-subtle bg-surface-subtle"
                     x-ref="canvas"
                     @if (! $readonly)
                         tabindex="0"
                         role="application"
                         aria-label="Peta nyeri tubuh tampak {{ strtolower($caption) }}. Gunakan tombol panah untuk memindahkan penanda, Enter untuk menempatkan, 0 sampai 9 untuk tingkat nyeri."
                         x-on:keydown="onKeydown($event)"
                         x-on:click="onClick($event)"
                         x-on:focus="focused = true"
                         x-on:blur="focused = false"
                     @else
                         aria-label="Peta nyeri tubuh tampak {{ strtolower($caption) }}"
                     @endif>

                    {{-- Simplified anterior/posterior silhouette. Region hit
                         areas are drawn from the same region list the schema
                         declares, so adding a region needs no view change. --}}
                    <svg viewBox="0 0 100 200" class="h-auto w-full"
                         x-bind:class="focused ? 'ring-2 ring-brand-600' : ''">
                        <rect width="100" height="200" fill="transparent"/>

                        <g fill="#cbd5e1" stroke="#94a3b8" stroke-width="0.6">
                            <circle cx="50" cy="18" r="11"/>
                            <path d="M50 29 C40 33 36 44 36 54 L36 84 C36 92 41 96 42 104
                                     L40 150 C40 156 44 158 47 158 L53 158 C56 158 60 156 60 150
                                     L58 104 C59 96 64 92 64 84 L64 54 C64 44 60 33 50 29 Z"/>
                        </g>

                        @foreach ($regions as $region)
                            @php
                                // Region geometry is derived from the region id
                                // so the map stays a single source of truth.
                                $box = \App\Services\Clinical\BodyMap::boxFor((string) $region['id']);
                            @endphp
                            <rect x="{{ $box[0] }}" y="{{ $box[1] }}" width="{{ $box[2] }}" height="{{ $box[3] }}"
                                  data-region="{{ $region['id'] }}"
                                  fill="transparent"
                                  class="cursor-pointer hover:fill-brand-600/10"/>
                        @endforeach
                    </svg>

                    {{-- Dropped pins. x-text on the label is not readable by a
                         screen reader, so each pin also carries a title. --}}
                    <template x-for="(pin, i) in visiblePins(@js($view))" :key="i">
                        <span class="pain-marker-drop absolute grid h-6 w-6 -translate-x-1/2 -translate-y-1/2
                                     place-items-center rounded-full bg-danger-600 text-[10px] font-bold text-white shadow-mid"
                              :style="`left: ${pin.x}%; top: ${pin.y}%`"
                              :title="`${pin.region} — Nyeri ${pin.severity}/10`">
                            <span x-text="i + 1"></span>
                        </span>
                    </template>
                </div>
            </figure>
        @endforeach
    </div>

    {{-- NRS modal. ui-spec.md pin-placing state. --}}
    <div x-show="pending" x-cloak
         class="mt-4 rounded-md border border-border-strong bg-surface-base p-4"
         role="dialog" aria-modal="true" aria-labelledby="pain-modal-title">
        <p id="pain-modal-title" class="text-heading-sm">
            {{ 'Tingkat Keparahan Nyeri (Skala NRS)' }}
        </p>
        <p class="text-body-sm text-text-muted">{{ '0: Tidak Nyeri, 1–3: Ringan, 4–6: Sedang, 7–10: Berat' }}</p>

        <p class="mt-2 text-body-sm">
            Lokasi: <strong x-text="pending?.region"></strong>
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @for ($i = 0; $i <= 10; $i++)
                {{-- The radio itself is visually hidden (it stays focusable and
                     announced), so the label carries the id that the click
                     target and its aria description hang off. --}}
                <label id="pain-severity-label-{{ $i }}" for="pain-severity-{{ $i }}"
                       class="cursor-pointer">
                    <input type="radio" id="pain-severity-{{ $i }}" class="peer sr-only" name="pain-severity"
                           :value="{{ $i }}" x-model.number="severity">
                    <span class="grid h-9 w-9 place-items-center rounded-md border border-border-subtle
                                 font-mono-md transition-colors duration-150
                                 peer-checked:border-brand-600 peer-checked:bg-brand-600 peer-checked:text-white">
                        {{ $i }}
                    </span>
                </label>
            @endfor
        </div>

        <p class="mt-2 text-body-sm text-text-secondary">Ter Stadium: <strong x-text="band"></strong></p>

        <div class="mt-3 flex gap-2">
            <button type="button" id="pain-pin-confirm" class="btn-primary" x-on:click="confirm()">
                Simpan Penanda
            </button>
            <button type="button" id="pain-pin-cancel" class="btn-secondary" x-on:click="pending = null">
                Batal
            </button>
        </div>
    </div>

    {{-- Hidden inputs carry the markers into the payload. --}}
    @foreach ($markers as $i => $marker)
        @foreach (['x', 'y', 'region', 'severity'] as $key)
            <input type="hidden" name="payload[{{ $name }}][{{ $i }}][{{ $key }}]"
                   value="{{ is_array($marker) ? ($marker[$key] ?? '') : '' }}">
        @endforeach
    @endforeach

    @if ($errors->has($name))
        <p role="alert" class="mt-2 text-body-sm text-danger-600">{{ $errors->first($name) }}</p>
    @endif
</div>