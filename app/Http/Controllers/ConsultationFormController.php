<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Models\ClinicForm;
use App\Models\ConsultationFormResponse;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use App\Models\ConsultationSession;
use App\Models\Order;


class ConsultationFormController extends Controller
{
    /**
     * Backwards‑compatible POST endpoint that accepts a flat payload
     * from blades using route('consultations.save').
     * It resolves the session and the correct ClinicForm, then delegates to save().
     */
    public function saveByPost(Request $request)
    {
        // Validate essentials; detailed field validation will run in save()
        $validated = $request->validate([
            'session_id' => ['required', 'integer'],
            'form_type'  => ['nullable', 'string'], // allow deriving from __step_slug
            'service'    => ['nullable', 'string'],
            'treatment'  => ['nullable', 'string'],
        ]);

        // Resolve session
        $session = ConsultationSession::query()->findOrFail((int) $validated['session_id']);

        // Normalise form_type to underscore for DB lookups; fall back to __step_slug
        $ftRaw = (string) ($validated['form_type'] ?? $request->input('__step_slug', ''));
        $ft = \Illuminate\Support\Str::of($ftRaw ?: 'raf')->replace('-', '_')->lower()->__toString();

        // Determine service/treatment scope, preferring posted over session, supporting both slug and plain values
        $service = \Illuminate\Support\Str::slug((string) ($validated['service']
            ?? ($session->service_slug ?? $session->service ?? '')));
        $treat   = \Illuminate\Support\Str::slug((string) ($validated['treatment']
            ?? ($session->treatment_slug ?? $session->treatment ?? '')));

        // Try to find the most specific matching ClinicForm, then gracefully fall back
        $q = \App\Models\ClinicForm::query()->where('form_type', $ft);

        if ($service !== '') {
            $q->where(function ($qq) use ($service) {
                $qq->whereNull('service_slug')->orWhere('service_slug', $service);
            });
        }
        if ($treat !== '') {
            $q->where(function ($qq) use ($treat) {
                $qq->whereNull('treatment_slug')->orWhere('treatment_slug', $treat);
            });
        }

        $form = $q->orderByRaw('CASE WHEN service_slug IS NULL THEN 1 ELSE 0 END')
                  ->orderByRaw('CASE WHEN treatment_slug IS NULL THEN 1 ELSE 0 END')
                  ->orderByDesc('id')
                  ->first();

        if (! $form) {
            // Last‑chance fallback to any form with this type
            $form = \App\Models\ClinicForm::query()
                ->where('form_type', $ft)
                ->orderByDesc('id')
                ->firstOrFail();
        }

        // Ensure __step_slug exists for the main save() validator
        if (! $request->has('__step_slug')) {
            $step = match ($ft) {
                'pharmacist_declaration' => 'pharmacist-declaration',
                'pharmacist_advice'      => 'pharmacist-advice',
                'record_of_supply'       => 'record-of-supply',
                'risk_assessment'        => 'risk-assessment',
                default                  => \Illuminate\Support\Str::slug($ft, '-'),
            };
            $request->merge(['__step_slug' => $step]);
        }

        // Delegate to the canonical saver
        return $this->save($request, $session->id, $form);
    }

    private function coerceConsultationNoteText($raw): string
    {
        if ($raw === null) return '';

        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') return '';

            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                return $trim;
            }
        }

        if (is_array($raw)) {
            if (empty($raw)) return '';

            $last = end($raw);

            if (is_string($last)) return trim($last);

            if (is_array($last)) {
                $t = $last['note'] ?? $last['text'] ?? $last['value'] ?? $last['content'] ?? null;
                return is_scalar($t) ? trim((string) $t) : '';
            }

            return is_scalar($last) ? trim((string) $last) : '';
        }

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    private function appendUserConsultationNote(?User $user, string $noteText, ?string $reference = null): void
    {
        try {
            $noteText = trim($noteText);
            if ($noteText === '' || !$user) return;

            if (!Schema::hasColumn('users', 'consultation_notes')) return;

            $existing = $user->consultation_notes ?? null;

            if (is_string($existing)) {
                $decoded = json_decode(trim($existing), true);
                $existing = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            }

            if (!is_array($existing)) $existing = [];

            $row = [
                'note' => $noteText,
                'at' => now('UTC')->toIso8601String(),
            ];

            if ($reference) $row['reference'] = $reference;

            $existing[] = $row;

            $seen = [];
            $clean = [];

            foreach ($existing as $r) {
                if (!is_array($r)) continue;

                $n = trim((string)($r['note'] ?? ''));
                if ($n === '') continue;

                $at = trim((string)($r['at'] ?? $r['created_at'] ?? $r['timestamp'] ?? ''));
                $ref = trim((string)($r['reference'] ?? ''));

                $key = strtolower(preg_replace('/\s+/', ' ', $n)) . '|' . $at . '|' . $ref;
                if (isset($seen[$key])) continue;

                $seen[$key] = true;

                $clean[] = [
                    'note' => $n,
                    'at' => $at !== '' ? $at : now('UTC')->toIso8601String(),
                ] + ($ref !== '' ? ['reference' => $ref] : []);
            }

            $user->consultation_notes = json_encode($clean, JSON_UNESCAPED_SLASHES);
            $user->save();
        } catch (\Throwable $e) {
            \Log::warning('consultation.save.user_notes_failed', ['message' => $e->getMessage()]);
        }
    }

    public function save(Request $request, $sessionId, ClinicForm $form)
    {
        // 1) Basic validation
        try {
            $validated = $request->validate([
                '__step_slug'     => ['required', 'string'],
                '__mark_complete' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first() ?? 'Validation failed.';
            Notification::make()->title('Please fix the required fields')->body($first)->danger()->send();
            return back()->withErrors($e->errors())->withInput();
        }

        $stepSlug     = $validated['__step_slug'];
        $markComplete = (bool)($validated['__mark_complete'] ?? false);
        $goNext       = (bool) $request->boolean('__go_next');
        \Log::info('consultation.flags', [
            'session_id'    => $sessionId,
            'step'          => $stepSlug,
            'mark_complete' => $markComplete,
            'ship_now'      => $request->boolean('__ship_now'),
            'go_next'       => $goNext,
            'form_id'       => $form->id ?? null,
            'form_type'     => $form->form_type ?? null,
            'method'        => $request->method(),
            'url'           => $request->fullUrl(),
        ]);
        $userId       = auth()->id();

        // Normalise nested payloads to flat keys so schema mapping sees everything
        // Hoist answers[...] and data[...] into top-level keys only when a key does not already exist
        try {
            $all = $request->all();

            if (isset($all['answers']) && is_array($all['answers'])) {
                foreach ($all['answers'] as $k => $v) {
                    if (!array_key_exists($k, $all)) {
                        $request->merge([$k => $v]);
                    }
                }
            }

            // Refresh the snapshot after merging answers
            $all = $request->all();

            if (isset($all['data']) && is_array($all['data'])) {
                foreach ($all['data'] as $k => $v) {
                    if (!array_key_exists($k, $all)) {
                        $request->merge([$k => $v]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Do not block saving if normalisation fails
            \Log::warning('consultation.save.normalise_failed', ['message' => $e->getMessage()]);
        }

        // Ensure the consultation session exists before using it
        $session = ConsultationSession::query()->findOrFail($sessionId);
        try {
    \Log::info('consultation.save.hit', [
        'session_id'      => $session->id,
        'order_id'        => $session->order_id ?? null,
        'service_slug'    => $session->service_slug ?? null,
        'treatment_slug'  => $session->treatment_slug ?? null,
        'step_slug'       => $stepSlug,
        'request_keys'    => array_keys($request->except(['_token'])),
        'has_answers'     => is_array($request->input('answers')),
        'answers_keys'    => is_array($request->input('answers')) ? array_keys($request->input('answers')) : [],
        'has_files'       => !empty($request->allFiles()),
    ]);
} catch (\Throwable $e) {
    \Log::warning('consultation.save.hit_log_failed', ['message' => $e->getMessage()]);
}
        // Derive non-null identifiers for DB scope
        $derivedFormType = $form->form_type;
        if (!$derivedFormType) {
            // Prefer the current step slug, then infer from the form metadata, then fallback to 'form'
            $derivedFormType = Str::of((string) $stepSlug)->replace('-', '_')->__toString();
            if ($derivedFormType === '' || $derivedFormType === 'form') {
                $slugGuess = $this->slugForForm($form); // e.g. pharmacist-advice
                $derivedFormType = Str::of((string) $slugGuess)->replace('-', '_')->__toString();
            }
            if ($derivedFormType === '') {
                $derivedFormType = 'form';
            }
        }

        // Prefer session values; support either *_slug or plain names on the session
        $serviceSlugForForm = (string) (($session->service_slug ?? null)
            ?: (isset($session->service) ? Str::slug((string) $session->service) : '')
            ?: ($form->service_slug ?? ''));
        $treatmentSlugForForm = (string) (($session->treatment_slug ?? null)
            ?: (isset($session->treatment) ? Str::slug((string) $session->treatment) : '')
            ?: ($form->treatment_slug ?? ''));
        if ($serviceSlugForForm === '' && ($form->service_name ?? null)) {
            $serviceSlugForForm = Str::slug((string) $form->service_name);
        }
        if ($treatmentSlugForForm === '' && ($form->treatment_name ?? null)) {
            $treatmentSlugForForm = Str::slug((string) $form->treatment_name);
        }

        // 3) Previously we enforced a strict service/treatment match between the session
        //    and the ClinicForm, aborting with 422 on mismatch. This caused valid flows
        //    to fail when generic forms were reused across services. We still compute
        //    the values for potential logging, but we no longer block the save here.
        $sessionService    = Str::slug((string) (($session->service_slug ?? null) ?: ($session->service ?? '')));
        $sessionTreatment  = Str::slug((string) (($session->treatment_slug ?? null) ?: ($session->treatment ?? '')));
        $formService       = Str::slug((string) ($form->service_slug ?? ''));
        $formTreatment     = Str::slug((string) ($form->treatment_slug ?? ''));

        $serviceMismatch   = $sessionService   !== '' && $formService   !== '' && $formService   !== $sessionService;
        $treatmentMismatch = $sessionTreatment !== '' && $formTreatment !== '' && $formTreatment !== $sessionTreatment;

        if ($serviceMismatch || $treatmentMismatch) {
            \Log::info('consultation.save.service_mismatch', [
                'session_id'        => $session->id,
                'derived_form_type' => $derivedFormType,
                'session_service'   => $sessionService,
                'session_treatment' => $sessionTreatment,
                'form_service'      => $formService,
                'form_treatment'    => $formTreatment,
            ]);
        }

        // 3b) Schema-driven validation for required fields
        $__fileFieldNames = [];
        $__fileFieldExisting = [];
        $rawSchema = is_array($form->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);
        $rules = [];
        foreach ($rawSchema as $idx => $fld) {
            $type = strtolower($fld['type'] ?? 'text_input');
            $cfg  = (array)($fld['data'] ?? []);

            // Skip non-input blocks
            if ($type === 'text_block') {
                continue;
            }

            // Resolve the posted field name. Different renderers may derive the name
            // from schema->name, from a slugged label, or fall back to field_{idx}.
            $labelRaw   = $cfg['label'] ?? ($fld['label'] ?? null);
            $slugLabelUnderscore  = $labelRaw ? Str::slug($labelRaw, '_') : null;
            $slugLabelDash        = $labelRaw ? Str::slug($labelRaw, '-') : null;
            // honour explicit keys from builder or importer
            $schemaKey            = $cfg['key'] ?? ($fld['key'] ?? null);
            $schemaKeyDash        = $schemaKey ? str_replace('_', '-', $schemaKey) : null;

            $candidates = array_values(array_filter([
                $fld['name'] ?? null,
                $schemaKey,
                $schemaKeyDash,
                $slugLabelUnderscore,
                $slugLabelDash,
                ($type === 'text_block' ? ('block_'.$idx) : ('field_'.$idx)),
            ]));

            $name = null;
            foreach ($candidates as $cand) {
                if (
                    $request->has($cand)
                    || $request->hasFile($cand)
                    || array_key_exists($cand, $request->all())
                ) {
                    $name = $cand;
                    break;
                }
            }
            if (!$name) {
                // Prefer explicit name in schema, then slug of label, then field_{idx}
                $name = $candidates[0] ?? ('field_'.$idx);
            }

            if (!empty($cfg['required'])) {
                switch ($type) {
                    case 'checkbox':
                        $rules[$name] = 'accepted';
                        break;
                    case 'number':
                        $numRules = ['required','numeric'];
                        if (isset($cfg['min']) && $cfg['min'] !== null && $cfg['min'] !== '') {
                            $numRules[] = 'min:'.$cfg['min'];
                        }
                        if (isset($cfg['max']) && $cfg['max'] !== null && $cfg['max'] !== '') {
                            $numRules[] = 'max:'.$cfg['max'];
                        }
                        $rules[$name] = implode('|', $numRules);
                        break;
                    case 'date':
                        $rules[$name] = 'required|date';
                        break;
                    case 'select':
                        // support single and multiple selections
                        $options = (array)($cfg['options'] ?? ($fld['options'] ?? []));
                        $values = [];
                        foreach ($options as $ov => $ol) {
                            $values[] = is_array($ol) ? ($ol['value'] ?? $ov) : $ov;
                        }
                        $isMultiple = (bool)($cfg['multiple'] ?? ($fld['multiple'] ?? false));

                        $notAllowed = [];
                        if (in_array('0', $values, true)) { $notAllowed[] = '0'; }
                        if (in_array('',  $values, true)) { $notAllowed[] = ''; }

                        if ($isMultiple) {
                            $rules[$name] = ['required', 'array'];
                            if (!empty($values)) {
                                $rules[$name . '.*'] = array_filter([Rule::in($values), $notAllowed ? Rule::notIn($notAllowed) : null]);
                            }
                        } else {
                            $rules[$name] = empty($values)
                                ? ['required']
                                : array_filter(['required', Rule::in($values), $notAllowed ? Rule::notIn($notAllowed) : null]);
                        }
                        break;
                    case 'file_upload':
                    case 'image':
                        // Build robust rules so existing paths satisfy required
                        $accept = strtolower((string)($cfg['accept'] ?? ''));
                        $r = ['nullable', 'sometimes', 'file'];
                        if ($type === 'image' || str_contains($accept, 'image')) {
                            $r[] = 'image';
                        }
                        // apply "required_without" only when schema marks the field required
                        if (!empty($cfg['required'])) {
                            $r[] = 'required_without:' . $name . '__existing';
                        }
                        $rules[$name] = $r;
                        // Validate the companion __existing payload as an array of strings if present
                        $rules[$name . '__existing'] = ['nullable', 'array'];
                        // track file input candidates for existing-path normalisation
                        $__fileFieldNames[] = $name;
                        break;
                    case 'signature':
                        $rules[$name] = 'required';
                        break;
                    case 'textarea':
                    case 'text_input':
                    default:
                        $rules[$name] = 'required|string';
                        break;
                }
            }
        }

        // Normalise __existing for all file inputs so required_without sees an array
        foreach ($__fileFieldNames as $fname) {
            $raw = $request->input($fname . '__existing');
            $arr = [];
            if (is_array($raw)) {
                $arr = array_values(array_filter($raw));
            } elseif (is_string($raw) && $raw !== '') {
                $dec = json_decode($raw, true);
                $arr = is_array($dec) ? array_values(array_filter($dec)) : [];
            }
            $__fileFieldExisting[$fname] = $arr;
            // Merge back to the request in a canonical array form
            $request->merge([$fname . '__existing' => $arr]);
        }

        // Strip any non-file payloads for file fields so "file" rule doesn't trip on strings/JSON
        foreach ($__fileFieldNames as $fname) {
            if (! $request->hasFile($fname) && $request->has($fname)) {
                // Remove accidental string/JSON remnants under the file input name
                $request->request->remove($fname);
            }
        }

        // Run validation for required fields
        if (!empty($rules)) {
            try {
                $request->validate($rules);
            } catch (ValidationException $e) {
                $first = collect($e->errors())->flatten()->first() ?? 'Please complete the required fields.';
                Notification::make()->title('Missing required information')->body($first)->danger()->send();
                return back()->withErrors($e->errors())->withInput();
            }
        }

        // 4) Build a schema-driven payload with stable keys and normalised types
        //    This ensures fields are saved under explicit schema keys and that
        //    unchecked checkboxes/toggles are persisted as 0, and multi-selects
        //    round-trip as arrays.
        $rawSchema = is_array($form->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);

        // Build a field map of candidate post names -> single stable store key
        $fields = [];
        foreach ($rawSchema as $idx => $fld) {
            $type = strtolower($fld['type'] ?? 'text_input');
            $cfg  = (array)($fld['data'] ?? []);

            // Skip non-input content blocks
            if ($type === 'text_block') {
                continue;
            }

            $labelRaw   = $cfg['label'] ?? ($fld['label'] ?? null);
            $slugLabelUnderscore  = $labelRaw ? Str::slug($labelRaw, '_') : null;
            $slugLabelDash        = $labelRaw ? Str::slug($labelRaw, '-') : null;
            $schemaKey            = $cfg['key'] ?? ($fld['key'] ?? null);
            $schemaKeyDash        = $schemaKey ? str_replace('_', '-', $schemaKey) : null;

            // Posted name candidates as they may differ between renderers
            $candidates = array_values(array_filter([
                $fld['name'] ?? null,
                $schemaKey,
                $schemaKeyDash,
                $slugLabelUnderscore,
                $slugLabelDash,
                ($type === 'text_block' ? ('block_'.$idx) : ('field_'.$idx)),
            ]));

            // Stable storage key prioritises explicit key, then name, then slug(label), then field_{n}
            $storeKey = $schemaKey ?: ($fld['name'] ?? ($slugLabelUnderscore ?? ('field_'.$idx)));

            $fields[] = [
                'type'       => $type,
                'multiple'   => (bool)($cfg['multiple'] ?? ($fld['multiple'] ?? false)),
                'candidates' => $candidates,
                'store'      => $storeKey,
            ];
        }

        // Pull values from the request using any candidate name and normalise types
        $submitted = [];

        foreach ($fields as $f) {
            $nameFound = null;
            foreach ($f['candidates'] as $cand) {
                // has() won't catch empty strings for some inputs, include array_key_exists
                if ($request->has($cand) || array_key_exists($cand, $request->all())) {
                    $nameFound = $cand;
                    break;
                }
            }

            // Checkbox/toggle are omitted when unchecked: store 0 explicitly
            if (in_array($f['type'], ['checkbox', 'toggle', 'switch'], true)) {
                $submitted[$f['store']] = $nameFound ? (int) $request->boolean($nameFound) : 0;
                continue;
            }

            // Multi-select should always be an array
            if ($f['type'] === 'select' && $f['multiple']) {
                $submitted[$f['store']] = $nameFound ? (array) $request->input($nameFound, []) : [];
                continue;
            }

            // Regular inputs
            if ($nameFound !== null) {
                $val = $request->input($nameFound);
                if (is_string($val)) {
                    $val = trim($val);
                    if ($val === '') {
                        $val = null;
                    }
                }
                $submitted[$f['store']] = $val;
            }
        }

        // 4a) Normalise any uploaded files to stored public paths under the correct store key
        if ($request->allFiles()) {
            foreach ($request->allFiles() as $key => $file) {
                // Persist files to the public disk so thumbnails can render
                $objects = [];

                if (is_array($file)) {
                    foreach ($file as $f) {
                        if (!$f) continue;
                        $p = $f->store('consultations', ['disk' => 'public']);
                        $objects[] = [
                            'name' => $f->getClientOriginalName(),
                            'path' => '/storage/' . ltrim($p, '/'),
                            'type' => $f->getMimeType(),
                            'size' => $f->getSize(),
                        ];
                    }
                } else {
                    if ($file instanceof UploadedFile) {
                        $p = $file->store('consultations', ['disk' => 'public']);
                        $objects[] = [
                            'name' => $file->getClientOriginalName(),
                            'path' => '/storage/' . ltrim($p, '/'),
                            'type' => $file->getMimeType(),
                            'size' => $file->getSize(),
                        ];
                    }
                }

                // Map the posted upload field name to its schema "store" key
                foreach ($fields as $f) {
                    if (in_array($key, $f['candidates'], true)) {
                        // Always store as a list of file objects to align with the viewer's pretty-printer
                        $submitted[$f['store']] = $objects;
                        break;
                    }
                }
            }
        }
        // For any file fields with no upload this request, carry forward existing paths posted by the client preview
        if (!empty($__fileFieldExisting)) {
            foreach ($fields as $f) {
                $storeKey = $f['store'] ?? null;
                if (!$storeKey) continue;
                // find any matching candidate that has an __existing payload
                $existing = [];
                foreach ((array)($f['candidates'] ?? []) as $cand) {
                    if (!empty($__fileFieldExisting[$cand])) {
                        $existing = $__fileFieldExisting[$cand];
                        break;
                    }
                }
                if (!empty($existing) && !array_key_exists($storeKey, $submitted)) {
                    // Keep whatever shape the client sent the paths in; viewers handle strings or objects
                    $submitted[$storeKey] = $existing;
                }
            }
            
        }
        // 4aa) Fallback merge for blades that post nested answers[...] keys
        // Build a tolerant map of allowed storage keys so we can accept
        // hyphen/underscore and other renderer variations without losing data.
        $storeMap = [];
        foreach ($fields as $f) {
            $s = $f['store'] ?? null;
            if (!$s) {
                continue;
            }
            // Canonical
            $storeMap[$s] = $s;
            // Common variants
            $storeMap[\Illuminate\Support\Str::slug($s, '_')] = $s; // foo-bar -> foo_bar
            $storeMap[\Illuminate\Support\Str::slug($s, '-')] = $s; // foo_bar -> foo-bar
            $storeMap[str_replace('-', '_', $s)] = $s;
            $storeMap[str_replace('_', '-', $s)] = $s;
        }

        // Helper to normalise scalar values
        $normaliseVal = function ($v) {
            if (is_string($v)) {
                $vv  = trim($v);
                $low = strtolower($vv);
                if (in_array($low, ['on','true','yes','1'], true))  return 1;
                if (in_array($low, ['off','false','no','0'], true)) return 0;
                return $vv === '' ? null : $vv;
            }
            return $v;
        };

        // Helper to place a value into the submitted payload only if not already present
        $putValue = function (string $storeKey, $value) use (&$submitted, $normaliseVal) {
            if (!array_key_exists($storeKey, $submitted)) {
                $submitted[$storeKey] = $normaliseVal($value);
            }
        };

        // Merge flat answers[...] if present
        $answersArray = $request->input('answers', []);
        if (is_array($answersArray)) {
            foreach ($answersArray as $k => $v) {
                $kStr   = is_string($k) ? $k : (string) $k;
                $target = $storeMap[$kStr]
                    ?? ($storeMap[\Illuminate\Support\Str::slug($kStr, '_')] ?? null)
                    ?? ($storeMap[\Illuminate\Support\Str::slug($kStr, '-')] ?? null);

                if ($target) {
                    $putValue($target, $v);
                } else {
                    // Unknown key: keep it under a safe normalised name as a last-resort
                    $putValue(\Illuminate\Support\Str::slug($kStr, '_'), $v);
                }
            }
        }

        // Merge any dot-style answers.foo inputs as well
        $reserved = [
            '_token','_method','__step_slug','__mark_complete','__go_next',
            'session_id','service','treatment','form_type'
        ];
        $dot = \Illuminate\Support\Arr::dot($request->except($reserved));

        foreach ($dot as $k => $v) {
            if (!str_starts_with($k, 'answers.')) {
                continue;
            }
            $raw    = substr($k, 8); // strip "answers."
            $target = $storeMap[$raw]
                ?? ($storeMap[\Illuminate\Support\Str::slug($raw, '_')] ?? null)
                ?? ($storeMap[\Illuminate\Support\Str::slug($raw, '-')] ?? null);

            if ($target) {
                $putValue($target, $v);
            } else {
                $putValue(\Illuminate\Support\Str::slug($raw, '_'), $v);
            }
        }

        // Last-resort catch-all so we never drop a user's submission silently
        if (empty($submitted)) {
            $fallback = $answersArray;
            if (!is_array($fallback) || empty($fallback)) {
                $fallback = $request->except($reserved);
            }
            if (is_array($fallback)) {
                foreach ($fallback as $k => $v) {
                    if (is_object($v)) {
                        // ignore file objects here
                        continue;
                    }
                    $kStr = is_string($k) ? $k : (string) $k;
                    $target = $storeMap[$kStr]
                        ?? ($storeMap[\Illuminate\Support\Str::slug($kStr, '_')] ?? null)
                        ?? ($storeMap[\Illuminate\Support\Str::slug($kStr, '-')] ?? null)
                        ?? \Illuminate\Support\Str::slug($kStr, '_');

                    $putValue($target, $v);
                }
            }
        }
        // 4b) Final payload for persistence
        $payload = $submitted;

        // 5) Upsert by unique scope (with robust fallbacks)
        $scope = [
            'consultation_session_id' => (int) $session->id,
            'form_type'               => (string) $derivedFormType,
        ];

        /** @var ConsultationFormResponse|null $existing */
        $existing = ConsultationFormResponse::query()->where($scope)->first();

        // Preserve completion timestamp unless transitioning to complete
        $isComplete  = $existing ? (bool)$existing->is_complete : false;
        $completedAt = $existing ? $existing->completed_at : null;
        if ($markComplete && !$isComplete) {
            $isComplete  = true;
            $completedAt = now();
            // Shipping is now triggered in the dedicated complete() endpoint.
        }

        // Ensure a non-null form_version for NOT NULL constraint
        $formVersion = (int) ($form->version ?? 0);
        if ($formVersion <= 0) {
            $formVersion = 1;
        }

        // 6) Merge with existing data and save
        $persistData = $existing
            ? array_replace_recursive((array) $existing->data, $payload)
            : $payload;

        // 6a) Remove values for fields whose showIf conditions are not satisfied
        $evalShowIf = function (array $cond, array $data): bool {
            $field = (string)($cond['field'] ?? '');
            if ($field === '') return true; // no field -> treat as visible

            $raw = null;
            foreach (array_values(array_unique(array_filter([
                $field,
                str_replace('-', '_', $field),
                str_replace('_', '-', $field),
                \Illuminate\Support\Str::slug($field, '_'),
                \Illuminate\Support\Str::slug($field, '-'),
            ]))) as $fieldKey) {
                if (array_key_exists($fieldKey, $data)) {
                    $raw = $data[$fieldKey];
                    break;
                }
            }
            $rawVals = is_array($raw) ? array_map('strval', $raw) : [ (string) $raw ];
            // Build slug-normalised versions to allow "Yes" vs "yes" vs "YES" etc.
            $normVals = array_map(function ($v) {
                return \Illuminate\Support\Str::slug($v ?? '');
            }, $rawVals);

            // equals
            if (array_key_exists('equals', $cond)) {
                $eq = (string) $cond['equals'];
                $eqNorm = \Illuminate\Support\Str::slug($eq);
                return in_array($eq, $rawVals, true) || in_array($eqNorm, $normVals, true);
            }

            // in
            if (!empty($cond['in']) && is_array($cond['in'])) {
                $inRaw  = array_map('strval', $cond['in']);
                $inNorm = array_map(fn ($v) => \Illuminate\Support\Str::slug($v), $inRaw);
                $hitRaw  = count(array_intersect($rawVals, $inRaw)) > 0;
                $hitNorm = count(array_intersect($normVals, $inNorm)) > 0;
                return $hitRaw || $hitNorm;
            }

            // notEquals
            if (array_key_exists('notEquals', $cond)) {
                $neq = (string) $cond['notEquals'];
                $neqNorm = \Illuminate\Support\Str::slug($neq);
                $isEq = in_array($neq, $rawVals, true) || in_array($neqNorm, $normVals, true);
                return !$isEq;
            }

            // truthy
            if (!empty($cond['truthy'])) {
                // Any non-empty raw value or any element in array counts as truthy
                foreach ($rawVals as $v) {
                    if (trim((string)$v) !== '') return true;
                }
                return false;
            }

            return true; // unknown condition -> keep
        };

        foreach ($rawSchema as $idx => $fld) {
            $type = $fld['type'] ?? 'text_input';
            $cfg  = (array)($fld['data'] ?? []);
            $cond = (array)($cfg['showIf'] ?? []);
            if (empty($cond)) continue;

            $labelRaw  = $cfg['label'] ?? ($fld['label'] ?? null);
            $slugLabel = $labelRaw ? Str::slug($labelRaw, '_') : null;
            $schemaKey = $cfg['key'] ?? ($fld['key'] ?? null);
            $name      = $fld['name'] ?? $schemaKey ?? $slugLabel ?? (($type === 'text_block') ? ('block_'.$idx) : ('field_'.$idx));

            // If condition not met, drop the value so it doesn't display later
            if ($name && array_key_exists($name, $persistData) && !$evalShowIf($cond, $persistData)) {
                unset($persistData[$name]);
            }
        }

        // Helpful debug for tracking saves
        \Log::info('consultation.save.persist', [
    'session_id'    => $session->id,
    'form_id'       => $form->id,
    'form_type'     => $derivedFormType,
    'step'          => $stepSlug,
    'saved_keys'    => array_keys($persistData),
    'count'         => count($persistData),
    'go_next'       => $goNext,
    'mark_complete' => $markComplete,
    'redirect_to'   => $target ?? null,
]);

        $model = ConsultationFormResponse::firstOrNew($scope);

        // preserve created_by if row already exists
        if (! $model->exists && isset($existing?->created_by)) {
            $model->created_by = $existing->created_by;
        } elseif (! $model->exists) {
            $model->created_by = $userId;
        }

        $model->clinic_form_id = $form->id;
        $model->form_type      = $derivedFormType;
        $model->service_slug   = $serviceSlugForForm;
        $model->treatment_slug = $treatmentSlugForForm;
        $model->step_slug      = $stepSlug;
        $model->form_version   = $formVersion;
        $model->is_complete    = $isComplete;
        $model->completed_at   = $completedAt;
        $model->updated_by     = $userId;

        // the important bit set data explicitly then save
        $model->data = $persistData;
        $model->save();

        // Mirror into session meta so runner and viewers that read session->meta can see latest values
        $sessionMeta = is_array($session->meta) ? $session->meta : (json_decode($session->meta ?? '[]', true) ?: []);
        $formKeyUnderscore = $derivedFormType;                 // e.g. risk_assessment
        $formSlug          = $this->slugForForm($form);        // e.g. risk-assessment

        data_set($sessionMeta, "forms.$formKeyUnderscore.answers", $persistData);
        data_set($sessionMeta, "forms.$formKeyUnderscore.schema",  $rawSchema);
        data_set($sessionMeta, "forms.$formKeyUnderscore.updated_at", now()->toIso8601String());

        // Also store under the hyphenated slug for consumers that expect that shape
        data_set($sessionMeta, "forms.$formSlug.answers", $persistData);
        data_set($sessionMeta, "forms.$formSlug.schema",  $rawSchema);
        data_set($sessionMeta, "forms.$formSlug.updated_at", now()->toIso8601String());

        // Backward compatible mirrors for legacy consumers that expect top-level formsQA or forms_qa
        data_set($sessionMeta, "formsQA.$formKeyUnderscore", $persistData);
        data_set($sessionMeta, "formsQA.$formSlug",        $persistData);
        data_set($sessionMeta, "forms_qa.$formKeyUnderscore", $persistData);
        data_set($sessionMeta, "forms_qa.$formSlug",          $persistData);
        data_set($sessionMeta, "formsQA.updated_at", now()->toIso8601String());
        data_set($sessionMeta, "forms_qa.updated_at", now()->toIso8601String());

        $session->meta = $sessionMeta;
        $session->save();

        // Persist last known weight for this patient so we can prefill next orders
        try {
            // Only do this for RAF-like steps
            $isRafLike = ($derivedFormType === 'raf')
                || (str_contains((string) $derivedFormType, 'raf'))
                || ($stepSlug === 'raf')
                || (str_contains((string) $stepSlug, 'raf'));

            if ($isRafLike && $userId) {
                $weightRaw = null;

                // Prefer explicit keys first
                $candidates = ['weight', 'weight_kg', 'current_weight', 'weightkg', 'body_weight', 'your_weight'];
                foreach ($candidates as $k) {
                    $v = data_get($persistData, $k);
                    if (is_string($v)) {
                        $vv = trim($v);
                        if ($vv !== '') {
                            $weightRaw = $vv;
                            break;
                        }
                    } elseif (is_numeric($v)) {
                        $weightRaw = (string) $v;
                        break;
                    }
                }

                // If not found, try a best-effort scan for any key containing "weight" but avoid target/goal
                if ($weightRaw === null && is_array($persistData)) {
                    foreach ($persistData as $k => $v) {
                        $ks = is_string($k) ? strtolower($k) : '';
                        if ($ks === '' || !str_contains($ks, 'weight')) {
                            continue;
                        }
                        if (str_contains($ks, 'target') || str_contains($ks, 'goal')) {
                            continue;
                        }
                        if (is_string($v)) {
                            $vv = trim($v);
                            if ($vv !== '') {
                                $weightRaw = $vv;
                                break;
                            }
                        } elseif (is_numeric($v)) {
                            $weightRaw = (string) $v;
                            break;
                        }
                    }
                }

                if ($weightRaw !== null) {
                    $rawLower = strtolower($weightRaw);

                    // Extract a number from the raw string
                    $num = null;
                    if (is_numeric($weightRaw)) {
                        $num = (float) $weightRaw;
                    } else {
                        if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $weightRaw, $m)) {
                            $num = (float) $m[1];
                        }
                    }

                    // Derive unit and normalise to kg when possible
                    $unit = null;
                    $kg = null;

                    if ($num !== null) {
                        if (str_contains($rawLower, 'kg') || str_contains($rawLower, 'kilogram')) {
                            $unit = 'kg';
                            $kg = $num;
                        } elseif (str_contains($rawLower, 'lb') || str_contains($rawLower, 'lbs') || str_contains($rawLower, 'pound')) {
                            $unit = 'lb';
                            $kg = $num * 0.45359237;
                        } elseif (str_contains($rawLower, 'st') || str_contains($rawLower, 'stone')) {
                            $unit = 'st';
                            $kg = $num * 6.35029318;
                        } else {
                            // Heuristic: assume kg for realistic adult ranges
                            $unit = 'kg';
                            $kg = $num;
                        }
                    }

                    // Clamp obviously invalid values
                    if ($kg !== null) {
                        if ($kg < 20 || $kg > 400) {
                            $kg = null;
                        }
                    }

                    // Save into user meta so we don't need DB schema changes
                    $user = User::query()->find($userId);
                    if ($user) {
                        $userMeta = is_array($user->meta) ? $user->meta : (json_decode($user->meta ?? '[]', true) ?: []);
                        if (!is_array($userMeta)) {
                            $userMeta = [];
                        }

                        data_set($userMeta, 'health.last_weight.raw', $weightRaw);
                        if ($kg !== null) {
                            data_set($userMeta, 'health.last_weight.kg', round($kg, 1));
                        }
                        if ($unit) {
                            data_set($userMeta, 'health.last_weight.unit', $unit);
                        }
                        data_set($userMeta, 'health.last_weight.at', now()->toIso8601String());

                        $user->meta = $userMeta;
                        $user->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-blocking: never fail the form save because weight persistence failed
            \Log::warning('consultation.save.weight_persist_failed', ['message' => $e->getMessage()]);
        }

        // Persist admin + consultation notes into the related order meta (non-blocking)
        try {
            $adminNotesRaw = $request->input('admin_notes');
            $consultNotesRaw = $request->input('consultation_notes');
            if ($consultNotesRaw === null || $consultNotesRaw === '') {
                $consultNotesRaw = $request->input('consultation-notes');
            }
            if (($consultNotesRaw === null || $consultNotesRaw === '') && is_array($persistData ?? null)) {
                foreach (['consultation_notes', 'consultation-notes', 'other-clinical-notes', 'other_clinical_notes'] as $noteKey) {
                    if (array_key_exists($noteKey, $persistData)) {
                        $consultNotesRaw = $persistData[$noteKey];
                        break;
                    }
                }
            }

            $adminNotesStr = is_string($adminNotesRaw) ? trim($adminNotesRaw) : '';
            $consultNotesStr = $this->coerceConsultationNoteText($consultNotesRaw);

            \Log::info('consultation.save.notes_detected', [
                'session_id'    => $session->id,
                'form_type'     => $derivedFormType,
                'step_slug'     => $stepSlug,
                'raw_type'      => gettype($consultNotesRaw),
                'notes_found'   => $consultNotesStr !== '',
                'notes_length'  => strlen($consultNotesStr),
                'request_has_snake' => $request->has('consultation_notes'),
                'request_has_dash'  => $request->has('consultation-notes'),
                'persist_keys'      => is_array($persistData ?? null) ? array_keys($persistData) : [],
            ]);

            if ($consultNotesStr !== '') {
                $refForNote = null;
                try {
                    $refForNote = (string) ($order->reference ?? ($session->order_reference ?? $session->reference ?? ''));
                } catch (\Throwable $e) {
                    $refForNote = null;
                }

                $userForNote = null;

                try {
                    if (isset($session->user_id) && $session->user_id) {
                        $userForNote = User::query()->find($session->user_id);
                    }
                } catch (\Throwable $e) {
                    $userForNote = null;
                }

                try {
                    if (!$userForNote && $order && isset($order->user_id) && $order->user_id) {
                        $userForNote = User::query()->find($order->user_id);
                    }
                } catch (\Throwable $e) {
                }

                $this->appendUserConsultationNote($userForNote, $consultNotesStr, $refForNote);
            }

            if ($adminNotesStr !== '' || $consultNotesStr !== '') {
                $order = null;

                try {
                    if (isset($session->order) && $session->order) {
                        $order = $session->order;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }

                // Fallbacks if relationship isn't loaded/available
                try {
                    if (!$order && isset($session->order_id) && class_exists('App\\Models\\Order')) {
                        $order = \App\Models\Order::find($session->order_id);
                    }
                } catch (\Throwable $e) {
                    // ignore
                }

                try {
                    if (!$order && isset($session->order_reference) && class_exists('App\\Models\\Order')) {
                        $order = \App\Models\Order::where('reference', $session->order_reference)->first();
                    }
                } catch (\Throwable $e) {
                    // ignore
                }

                if ($order) {
                    $meta = $order->meta ?? [];
                    if (is_string($meta)) {
                        $meta = json_decode($meta, true) ?: [];
                    }
                    if (!is_array($meta)) {
                        $meta = [];
                    }

                    $appendNote = function ($existing, string $note) {
                        $note = trim($note);
                        if ($note === '') {
                            return $existing;
                        }

                        if (is_array($existing)) {
                            $arr = array_values(array_filter(array_map(function ($v) {
                                return is_string($v) ? trim($v) : '';
                            }, $existing)));
                            $arr[] = $note;
                            return array_values(array_unique(array_filter($arr, fn ($v) => $v !== '')));
                        }

                        $s = is_string($existing) ? trim($existing) : '';
                        if ($s === '') {
                            return [$note];
                        }
                        if ($s === $note) {
                            return [$note];
                        }
                        return array_values(array_unique([$s, $note]));
                    };

                    $appendNoteWithTs = function ($existing, string $note) {
                        $note = trim($note);
                        if ($note === '') {
                            return $existing;
                        }

                        $at = now()->toIso8601String();
                        $newItem = ['note' => $note, 'at' => $at];

                        // Normalise existing into an array of {note, at}
                        $items = [];

                        if (is_array($existing)) {
                            foreach ($existing as $v) {
                                if (is_array($v)) {
                                    $n = data_get($v, 'note') ?? data_get($v, 'text') ?? data_get($v, 'message') ?? '';
                                    $n = is_string($n) ? trim($n) : '';
                                    if ($n === '') continue;

                                    $t = data_get($v, 'at') ?? data_get($v, 'ts') ?? data_get($v, 'created_at') ?? null;
                                    $t = is_string($t) ? trim($t) : (is_null($t) ? null : (string) $t);

                                    $items[] = ['note' => $n, 'at' => $t ?: null];
                                } else {
                                    $s = is_string($v) ? trim($v) : '';
                                    if ($s === '') continue;
                                    $items[] = ['note' => $s, 'at' => null];
                                }
                            }
                        } else {
                            $s = is_string($existing) ? trim($existing) : '';
                            if ($s !== '') {
                                $items[] = ['note' => $s, 'at' => null];
                            }
                        }

                        // De-dupe by note text (keep first occurrence)
                        $seen = [];
                        $deduped = [];
                        foreach ($items as $it) {
                            $k = (string) ($it['note'] ?? '');
                            if ($k === '') continue;
                            if (isset($seen[$k])) continue;
                            $seen[$k] = true;
                            $deduped[] = $it;
                        }

                        if (!isset($seen[$note])) {
                            $deduped[] = $newItem;
                        }

                        return array_values($deduped);
                    };

                    if ($adminNotesStr !== '') {
                        $meta['admin_notes'] = $appendNote($meta['admin_notes'] ?? [], $adminNotesStr);
                    }

                    if ($consultNotesStr !== '') {
                        $meta['consultation_notes'] = $appendNoteWithTs($meta['consultation_notes'] ?? [], $consultNotesStr);
                        // Keep both keys in sync since some pages read consultant_notes
                        $meta['consultant_notes'] = $meta['consultation_notes'];
                    }

                    $order->meta = $meta;
                    $order->save();
                }
            }
        } catch (\Throwable $e) {
            // Notes persistence is non-blocking
        }

        // 6b) If we were asked to complete the consultation, mark the session (and order) complete
        //     and prefer redirecting to an appropriate "done" page.
        $redirectAfterComplete = null;
        if ($markComplete) {
            try {
                // Mark the consultation session complete
                if (empty($session->status) || $session->status !== 'completed') {
                    $session->status = 'completed';
                    $session->save();
                }

                // If there's a related order, flip it to approved/completed if your domain model supports it
                try {
                    if (method_exists($session, 'order') || property_exists($session, 'order')) {
                        $ord = $session->order;
                        if ($ord) {
                            // Set some reasonable "completed" semantics while keeping existing values when present
                            if (empty($ord->status) || in_array($ord->status, ['pending','processing','approved'], true)) {
                                $ord->status = 'completed';
                            }
                            // Persist shipping meta echo for quick access from order views
                            $meta = is_array($ord->meta) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                            $sessMeta = is_array($session->meta) ? $session->meta : (json_decode($session->meta ?? '[]', true) ?: []);
                            if (data_get($sessMeta, 'shipping')) {
                                $meta['shipping'] = array_replace_recursive($meta['shipping'] ?? [], (array) data_get($sessMeta, 'shipping'));
                            }
                            $ord->meta = $meta;
                            $ord->save();

                            // Also mark any linked appointment as completed
                            try {
                                $appointment = \App\Models\Appointment::where('order_reference', $ord->reference)->latest()->first();
                                if ($appointment) {
                                    $st = strtolower((string) $appointment->status);
                                    if (in_array($st, ['booked', 'approved', 'pending', ''], true)) {
                                        $appointment->status = 'completed';
                                        $appointment->save();
                                    }
                                }
                            } catch (\Throwable $ae) {
                                \Log::warning('consultation.save.appointment_update_failed', [
                                    'session' => $session->id,
                                    'order'   => $ord->getKey(),
                                    'error'   => $ae->getMessage(),
                                ]);
                            }

                            // Prefer redirecting to the completed order details page
                            $redirectAfterComplete = url('/admin/orders/completed-orders/'.$ord->getKey().'/details');
                        }
                    }
                } catch (\Throwable $oe) {
                    \Log::warning('consultation.complete.order_update_failed', ['session' => $session->id, 'error' => $oe->getMessage()]);
                }
            } catch (\Throwable $se) {
                \Log::warning('consultation.complete.session_update_failed', ['session' => $session->id, 'error' => $se->getMessage()]);
            }
        }
        // 7) Smart redirect: keep user on the same tab
        $backUrl = url()->previous();
        $tabKey  = $this->defaultTabKeyForSlug($stepSlug);

        // append or replace the "tab" query with the current tabKey
        $target  = $backUrl ? preg_replace('/([?&])tab=[^&]*/', '$1tab='.$tabKey, $backUrl) : null;
        if ($target && !str_contains($target, 'tab=')) {
            $target .= (str_contains($target, '?') ? '&' : '?').'tab='.$tabKey;
        }
        // optional hint for the UI if it cares about stepping
        if ($target && $goNext) {
            $target .= (str_contains($target, '?') ? '&' : '?') . 'next=1';
        }

        // If we completed the consultation this request and computed a post‑completion target, use it.
        if ($markComplete && $redirectAfterComplete) {
            return redirect()->to($redirectAfterComplete)->with('success', 'Consultation completed');
        }
        if ($request->wantsJson()) {
            return response()->json([
                'ok'            => true,
                'message'       => 'Form saved successfully',
                'step'          => $stepSlug,
                'tab'           => $tabKey,
                'is_complete'   => $isComplete,
                'completed_at'  => optional($completedAt)->toISOString(),
                'saved_keys'    => array_keys($persistData),
            ]);
        }

        return $target
            ? redirect()->to($target)->with('success', 'Form saved successfully!')
            : back()->with('success', 'Form saved successfully!');
    }
    /**
     * Build a best-effort answers array for hydration by checking DB records first
     * then falling back to session meta stores used across the app.
     */
    protected function gatherAnswersFor(ConsultationSession $session, string $formKeyUnderscore, string $formSlug, ?int $clinicFormId = null): array
    {
        // 1. Most recent DB response for this form type within the session
        $resp = \App\Models\ConsultationFormResponse::query()
            ->where('consultation_session_id', $session->id)
            ->where('form_type', $formKeyUnderscore)
            ->orderByDesc('updated_at')
            ->first();
        if ($resp && is_array($resp->data ?? null)) {
            return (array) $resp->data;
        }

        // 1b. If clinic form id is known, try that as well
        if ($clinicFormId) {
            $byCf = \App\Models\ConsultationFormResponse::query()
                ->where('consultation_session_id', $session->id)
                ->where('clinic_form_id', $clinicFormId)
                ->orderByDesc('updated_at')
                ->first();
            if ($byCf && is_array($byCf->data ?? null)) {
                return (array) $byCf->data;
            }
        }

        // 2. Session meta fallbacks  forms and legacy formsQA shapes
        $meta = is_array($session->meta) ? $session->meta : (json_decode($session->meta ?? '[]', true) ?: []);
        $cands = [
            data_get($meta, "forms.$formKeyUnderscore.answers"),
            data_get($meta, "forms.$formSlug.answers"),
            data_get($meta, "formsQA.$formKeyUnderscore"),
            data_get($meta, "formsQA.$formSlug"),
            data_get($meta, "forms_qa.$formKeyUnderscore"),
            data_get($meta, "forms_qa.$formSlug"),
            data_get($session, 'formsQA'),
            data_get($session, 'assessment.answers'),
        ];
        foreach ($cands as $cand) {
            if (is_array($cand) && !empty($cand)) {
                return (array) $cand;
            }
            if (is_string($cand)) {
                $dec = json_decode($cand, true);
                if (is_array($dec) && !empty($dec)) {
                    return (array) $dec;
                }
            }
        }

        return [];
    }

    /**
     * Final step page: show a simple confirmation screen before completing the consultation.
     */
    public function showComplete(ConsultationSession $session)
    {
        $order   = $session->order ?? null;
        $patient = $order?->patient ?? null;

        return view('consultations.complete', [
            'session'     => $session,
            'order'       => $order,
            'patient'     => $patient,
            'sessionLike' => $session,
        ]);
    }

    /**
     * Merge previously saved tab responses into session/order meta and return back with a success message.
     */
    protected function mergeLatestSavedTabsIntoMeta(ConsultationSession $session): array
    {
        $sessionMeta = is_array($session->meta)
            ? $session->meta
            : (json_decode($session->meta ?? '[]', true) ?: []);

        $normalisePayload = function ($raw): array {
            if (is_array($raw)) {
                $payload = $raw;
            } elseif (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                $payload = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
            } else {
                $payload = [];
            }

            if (! is_array($payload)) {
                return [];
            }

            array_walk_recursive($payload, function (&$value, $key) {
                if (! is_string($value)) {
                    return;
                }

                if ($key === 'signature' && str_starts_with($value, 'data:image/')) {
                    $value = '[signature omitted]';
                    return;
                }

                if (str_starts_with($value, 'data:image/')) {
                    $value = '[embedded image omitted]';
                    return;
                }

                if (strlen($value) > 5000) {
                    $value = \Illuminate\Support\Str::limit($value, 1000, '');
                }
            });

            return $payload;
        };

        $findLatestRow = function (array $aliases, array $nameHints = []) use ($session) {
            $row = ConsultationFormResponse::query()
                ->where('consultation_session_id', $session->id)
                ->whereIn('form_type', $aliases)
                ->latest('updated_at')
                ->first();

            if ($row) {
                return $row;
            }

            $clinicFormIds = ClinicForm::query()
                ->where(function ($q) use ($aliases, $nameHints) {
                    if (! empty($aliases)) {
                        $q->whereIn('form_type', $aliases);
                    }

                    if (! empty($nameHints)) {
                        foreach ($nameHints as $hint) {
                            $q->orWhere('name', 'like', '%' . $hint . '%');
                        }
                    }
                })
                ->pluck('id')
                ->all();

            if (empty($clinicFormIds)) {
                return null;
            }

            return ConsultationFormResponse::query()
                ->where('consultation_session_id', $session->id)
                ->whereIn('clinic_form_id', $clinicFormIds)
                ->latest('updated_at')
                ->first();
        };

        $formsToCopy = [
            'reorder' => [
                'aliases'   => ['reorder'],
                'nameHints' => ['reorder'],
                'top'       => 'reorder',
            ],
            'pharmacist_advice' => [
                'aliases'   => ['pharmacist_advice', 'pharmacist-advice', 'advice'],
                'nameHints' => ['pharmacist advice', 'advice'],
                'top'       => 'pharmacist_advice',
            ],
            'pharmacist_declaration' => [
                'aliases'   => ['pharmacist_declaration', 'pharmacist-declaration', 'declaration'],
                'nameHints' => ['pharmacist declaration', 'declaration'],
                'top'       => 'pharmacist_declaration',
            ],
            'record_of_supply' => [
                'aliases'   => ['record_of_supply', 'record-of-supply', 'clinical_notes'],
                'nameHints' => ['record of supply', 'clinical notes'],
                'top'       => 'record_of_supply',
            ],
        ];

        $merged = [];
        foreach ($formsToCopy as $canonical => $cfg) {
            \Log::info('consultation.save_all_tabs.check', [
                'session_id' => $session->id,
                'canonical'  => $canonical,
                'aliases'    => $cfg['aliases'],
                'name_hints' => $cfg['nameHints'],
            ]);

            $row = $findLatestRow($cfg['aliases'], $cfg['nameHints']);

            \Log::info('consultation.save_all_tabs.row', [
                'session_id'     => $session->id,
                'canonical'      => $canonical,
                'row_id'         => $row->id ?? null,
                'row_form_type'  => $row->form_type ?? null,
                'clinic_form_id' => $row->clinic_form_id ?? null,
            ]);
            if (! $row) {
                continue;
            }

            $payload = $normalisePayload($row->data ?? null);
            \Log::info('consultation.save_all_tabs.payload_sanitised', [
                'session_id'    => $session->id,
                'canonical'     => $canonical,
                'payload_keys'  => array_keys($payload),
                'payload_count' => count($payload),
                'has_signature' => array_key_exists('signature', $payload),
            ]);
            \Log::info('consultation.save_all_tabs.payload', [
    'session_id'    => $session->id,
    'canonical'     => $canonical,
    'payload_keys'  => array_keys($payload),
    'payload_count' => count($payload),
]);
            if (empty($payload)) {
                continue;
            }

            $clinicForm = null;
            try {
                $clinicForm = $row->clinic_form_id ? ClinicForm::find($row->clinic_form_id) : null;
            } catch (\Throwable $e) {
                $clinicForm = null;
            }

            $schema = is_array($clinicForm?->schema)
                ? $clinicForm->schema
                : (json_decode($clinicForm?->schema ?? '[]', true) ?: []);

            $slug = $clinicForm ? $this->slugForForm($clinicForm) : null;
            $canonicalUnderscore = str_replace('-', '_', (string) ($slug ?: $cfg['top']));
            $canonicalSlug = str_replace('_', '-', $canonicalUnderscore);

            $allKeys = array_values(array_unique(array_filter(array_merge(
                $cfg['aliases'],
                [$canonicalUnderscore, $canonicalSlug]
            ))));

            foreach ($allKeys as $alias) {
                data_set($sessionMeta, "forms.$alias.answers", $payload);
                data_set($sessionMeta, "forms.$alias.updated_at", now()->toIso8601String());
                if (! empty($schema)) {
                    data_set($sessionMeta, "forms.$alias.schema", $schema);
                }
                data_set($sessionMeta, "formsQA.$alias", $payload);
                data_set($sessionMeta, "forms_qa.$alias", $payload);
            }

            data_set($sessionMeta, $cfg['top'], $payload);
            $merged[] = $canonical;
        }

        $session->meta = $sessionMeta;
        $session->save();

        try {
            $order = $session->order ?? null;
            if ($order) {
                $orderMeta = is_array($order->meta)
                    ? $order->meta
                    : (json_decode($order->meta ?? '[]', true) ?: []);

                foreach ($formsToCopy as $canonical => $cfg) {
                    if (! in_array($canonical, $merged, true)) {
                        continue;
                    }

                    $row = $findLatestRow($cfg['aliases'], $cfg['nameHints']);
                    if (! $row) {
                        continue;
                    }

                    $payload = $normalisePayload($row->data ?? null);
                    \Log::info('consultation.save_all_tabs.order_payload_sanitised', [
                        'session_id'    => $session->id,
                        'canonical'     => $canonical,
                        'payload_keys'  => array_keys($payload),
                        'payload_count' => count($payload),
                        'has_signature' => array_key_exists('signature', $payload),
                    ]);
                    if (empty($payload)) {
                        continue;
                    }

                    $clinicForm = null;
                    try {
                        $clinicForm = $row->clinic_form_id ? ClinicForm::find($row->clinic_form_id) : null;
                    } catch (\Throwable $e) {
                        $clinicForm = null;
                    }

                    $schema = is_array($clinicForm?->schema)
                        ? $clinicForm->schema
                        : (json_decode($clinicForm?->schema ?? '[]', true) ?: []);

                    $slug = $clinicForm ? $this->slugForForm($clinicForm) : null;
                    $canonicalUnderscore = str_replace('-', '_', (string) ($slug ?: $cfg['top']));
                    $canonicalSlug = str_replace('_', '-', $canonicalUnderscore);

                    $allKeys = array_values(array_unique(array_filter(array_merge(
                        $cfg['aliases'],
                        [$canonicalUnderscore, $canonicalSlug]
                    ))));

                    foreach ($allKeys as $alias) {
                        data_set($orderMeta, "forms.$alias.answers", $payload);
                        data_set($orderMeta, "forms.$alias.updated_at", now()->toIso8601String());
                        if (! empty($schema)) {
                            data_set($orderMeta, "forms.$alias.schema", $schema);
                        }
                        data_set($orderMeta, "formsQA.$alias", $payload);
                        data_set($orderMeta, "forms_qa.$alias", $payload);
                    }

                    data_set($orderMeta, $cfg['top'], $payload);
                }

                $order->meta = $orderMeta;
                $order->save();
            }
        } catch (\Throwable $e) {
            \Log::warning('consultation.save_all_tabs.order_meta_merge_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $merged;
    }

    /**
     * Controller action to merge all saved tabs into session/order meta.
     */
    public function saveAllTabs(Request $request, ConsultationSession $session)
{
    \Log::info('consultation.save_all_tabs.hit', [
        'session_id' => $session->id,
        'url'        => $request->fullUrl(),
        'method'     => $request->method(),
    ]);

    $merged = $this->mergeLatestSavedTabsIntoMeta($session);

    $consultationNotesRaw = $request->input('consultation_notes');
    if ($consultationNotesRaw === null || $consultationNotesRaw === '') {
        $consultationNotesRaw = $request->input('answers.consultation_notes');
    }
    if ($consultationNotesRaw === null || $consultationNotesRaw === '') {
        $consultationNotesRaw = $request->input('answers.consultation-notes');
    }
    if ($consultationNotesRaw === null || $consultationNotesRaw === '') {
        $consultationNotesRaw = $request->input('pharmacist_advice.consultation_notes');
    }
    if ($consultationNotesRaw === null || $consultationNotesRaw === '') {
        $consultationNotesRaw = $request->input('consultation-notes');
    }
    if ($consultationNotesRaw === null || $consultationNotesRaw === '') {
        $consultationNotesRaw = $request->input('other-clinical-notes');
    }
    if ($consultationNotesRaw === null || $consultationNotesRaw === '') {
        $consultationNotesRaw = $request->input('other_clinical_notes');
    }

    $consultationNotes = $this->coerceConsultationNoteText($consultationNotesRaw);

    if ($consultationNotes === '') {
        try {
            $candidateRows = ConsultationFormResponse::query()
                ->where('consultation_session_id', $session->id)
                ->whereIn('form_type', ['pharmacist_advice', 'pharmacist-advice', 'advice', 'clinical_notes', 'record_of_supply', 'record-of-supply'])
                ->orderByDesc('updated_at')
                ->get();

            foreach ($candidateRows as $candidateRow) {
                $candidateData = is_array($candidateRow->data)
                    ? $candidateRow->data
                    : (json_decode($candidateRow->data ?? '[]', true) ?: []);

                if (!is_array($candidateData)) {
                    continue;
                }

                foreach (['consultation_notes', 'consultation-notes', 'other-clinical-notes', 'other_clinical_notes'] as $noteKey) {
                    if (array_key_exists($noteKey, $candidateData)) {
                        $consultationNotes = $this->coerceConsultationNoteText($candidateData[$noteKey]);
                        if ($consultationNotes !== '') {
                            break 2;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('consultation.save_all_tabs.lookup_notes_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    \Log::info('consultation.save_all_tabs.notes_input', [
        'session_id'          => $session->id,
        'raw_type'            => gettype($consultationNotesRaw),
        'notes_found'         => $consultationNotes !== '',
        'notes_length'        => strlen($consultationNotes),
        'request_has_direct'  => $request->has('consultation_notes'),
        'request_has_answers' => $request->has('answers.consultation_notes'),
    ]);

    $consultationNotesLatest = $consultationNotes !== ''
        ? \Illuminate\Support\Str::limit($consultationNotes, 4000, '')
        : '';

    if ($consultationNotes !== '') {
        // Write consultation notes to the latest pharmacist advice ConsultationFormResponse row
        try {
            $adviceRow = ConsultationFormResponse::query()
                ->where('consultation_session_id', $session->id)
                ->whereIn('form_type', ['pharmacist_advice', 'pharmacist-advice', 'advice'])
                ->latest('updated_at')
                ->first();

            if ($adviceRow) {
                $adviceData = is_array($adviceRow->data)
                    ? $adviceRow->data
                    : (json_decode($adviceRow->data ?? '[]', true) ?: []);

                if (!is_array($adviceData)) {
                    $adviceData = [];
                }

                $adviceData['consultation_notes'] = $consultationNotes;
                $adviceData['consultation-notes'] = $consultationNotes;

                $adviceRow->data = $adviceData;
                $adviceRow->save();

                \Log::info('consultation.save_all_tabs.advice_row_updated', [
                    'session_id' => $session->id,
                    'row_id'     => $adviceRow->id,
                    'form_type'  => $adviceRow->form_type,
                    'keys'       => array_keys($adviceData),
                ]);
            } else {
                \Log::warning('consultation.save_all_tabs.advice_row_missing', [
                    'session_id' => $session->id,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('consultation.save_all_tabs.advice_row_update_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }
        try {
            $sessionMeta = is_array($session->meta)
                ? $session->meta
                : (json_decode($session->meta ?? '[]', true) ?: []);

            data_set($sessionMeta, 'consultation_notes', $consultationNotes);
            data_set($sessionMeta, 'consultation-notes', $consultationNotesLatest);
            data_set($sessionMeta, 'consultation_notes_latest', $consultationNotesLatest);
            data_set($sessionMeta, 'pharmacist_advice.consultation_notes', $consultationNotesLatest);
            data_set($sessionMeta, 'pharmacist_advice.consultation-notes', $consultationNotesLatest);
            data_set($sessionMeta, 'pharmacist_advice_notes', $consultationNotesLatest);
            data_set($sessionMeta, 'pharmacist-advice-notes', $consultationNotesLatest);

            $session->meta = $sessionMeta;
            $session->save();
        } catch (\Throwable $e) {
            \Log::warning('consultation.save_all_tabs.session_notes_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }

        try {
            $order = $session->order ?? null;
            if ($order) {
                $orderMeta = is_array($order->meta)
                    ? $order->meta
                    : (json_decode($order->meta ?? '[]', true) ?: []);

                $existingNotes = $orderMeta['consultation_notes'] ?? $orderMeta['consultant_notes'] ?? [];

                if (is_string($existingNotes)) {
                    $decodedExistingNotes = json_decode($existingNotes, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedExistingNotes)) {
                        $existingNotes = $decodedExistingNotes;
                    } elseif (trim($existingNotes) !== '') {
                        $existingNotes = [
                            [
                                'note' => trim($existingNotes),
                                'at'   => null,
                            ],
                        ];
                    } else {
                        $existingNotes = [];
                    }
                }

                if (! is_array($existingNotes)) {
                    $existingNotes = [];
                }

                $alreadyExists = false;
                foreach ($existingNotes as $item) {
                    $text = is_array($item)
                        ? trim((string) ($item['note'] ?? $item['text'] ?? ''))
                        : trim((string) $item);
                    if ($text === $consultationNotes) {
                        $alreadyExists = true;
                        break;
                    }
                }

                if (! $alreadyExists) {
                    $existingNotes[] = [
                        'note' => $consultationNotes,
                        'at'   => now('UTC')->toIso8601String(),
                    ];
                }

                $normalisedNotes = [];
                $seenNotes = [];
                foreach ($existingNotes as $item) {
                    $noteText = is_array($item)
                        ? trim((string) ($item['note'] ?? $item['text'] ?? ''))
                        : trim((string) $item);
                    $noteAt = is_array($item)
                        ? ($item['at'] ?? $item['created_at'] ?? $item['ts'] ?? null)
                        : null;

                    if ($noteText === '') {
                        continue;
                    }

                    $dedupeKey = strtolower(preg_replace('/\s+/', ' ', $noteText));
                    if (isset($seenNotes[$dedupeKey])) {
                        continue;
                    }

                    $seenNotes[$dedupeKey] = true;
                    $normalisedNotes[] = [
                        'note' => $noteText,
                        'at'   => $noteAt ?: null,
                    ];
                }

                $existingNotes = $normalisedNotes;

                $orderMeta['consultation_notes'] = $existingNotes;
                $orderMeta['consultant_notes'] = $existingNotes;
                $orderMeta['consultation_notes_latest'] = $consultationNotesLatest;
                $orderMeta['consultation-notes'] = $consultationNotesLatest;
                $orderMeta['pharmacist_advice_notes'] = $consultationNotesLatest;
                $orderMeta['pharmacist-advice-notes'] = $consultationNotesLatest;

                // Explicit mirrors for Completed Order details page
                data_set($orderMeta, 'consultation_notes', $existingNotes);
                data_set($orderMeta, 'consultant_notes', $existingNotes);

                \Log::info('consultation.save_all_tabs.order_notes_written', [
                    'session_id'   => $session->id,
                    'order_id'     => $order->id,
                    'notes_count'  => is_array($existingNotes) ? count($existingNotes) : 0,
                    'latest_length'=> strlen($consultationNotesLatest),
                    'meta_has_consultation_notes' => array_key_exists('consultation_notes', $orderMeta),
                    'meta_has_consultant_notes'   => array_key_exists('consultant_notes', $orderMeta),
                ]);

                $order->meta = $orderMeta;
                $order->save();
            }
        } catch (\Throwable $e) {
            \Log::warning('consultation.save_all_tabs.order_notes_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }

        try {
            $userForNote = null;
            if (isset($session->user_id) && $session->user_id) {
                $userForNote = User::query()->find($session->user_id);
            }
            if (! $userForNote && ($session->order ?? null) && isset($session->order->user_id) && $session->order->user_id) {
                $userForNote = User::query()->find($session->order->user_id);
            }

            $reference = (string) (($session->order->reference ?? null) ?: ($session->order_reference ?? $session->reference ?? ''));
            $this->appendUserConsultationNote($userForNote, $consultationNotes, $reference !== '' ? $reference : null);
        } catch (\Throwable $e) {
            \Log::warning('consultation.save_all_tabs.user_notes_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    \Log::info('consultation.save_all_tabs.result', [
        'session_id'                => $session->id,
        'merged'                    => $merged,
        'consultation_notes'        => $consultationNotes !== '',
        'consultation_notes_length' => strlen($consultationNotes),
        'latest_length'             => strlen($consultationNotesLatest),
    ]);

    if ($request->expectsJson() || $request->wantsJson()) {
        return response()->json([
            'ok' => true,
            'merged' => $merged,
            'consultation_notes_saved' => $consultationNotes !== '',
            'consultation_notes_length' => strlen($consultationNotes),
        ]);
    }

    if (empty($merged) && $consultationNotes === '') {
        return back()->with('warning', 'No previously saved tab responses were found to merge.');
    }

    $parts = [];
    if (! empty($merged)) {
        $parts[] = 'Saved tabs merged: ' . implode(', ', $merged);
    }
    if ($consultationNotes !== '') {
        $parts[] = 'Consultation notes saved.';
    }

    return back()->with('success', implode(' ', $parts));
}

    /**
     * Handle the final Confirm and complete action.
     * Marks the session as completed and, if present, updates the linked order.
     */
    public function complete(Request $request, ConsultationSession $session)
    {
        $request->validate([
            'confirm_complete' => 'accepted',
        ]);

        $order = $session->order ?? null;

        if ($order) {
            $orderMetaForDuplicateCheck = is_array($order->meta)
                ? $order->meta
                : (json_decode($order->meta ?? '[]', true) ?: []);

            $existingClickAndDropIdentifier = data_get($orderMetaForDuplicateCheck, 'clickanddrop.created_order_identifier')
                ?? data_get($orderMetaForDuplicateCheck, 'clickanddrop.order_identifier')
                ?? data_get($orderMetaForDuplicateCheck, 'clickanddrop.created_order.orderIdentifier')
                ?? data_get($orderMetaForDuplicateCheck, 'clickanddrop.createdOrders.0.orderIdentifier')
                ?? data_get($orderMetaForDuplicateCheck, 'shipping.response.createdOrders.0.orderIdentifier')
                ?? data_get($orderMetaForDuplicateCheck, 'royal_mail.orderIdentifier')
                ?? data_get($orderMetaForDuplicateCheck, 'royal_mail.order_identifier');

            if (strtolower((string) $order->status) === 'completed' && $existingClickAndDropIdentifier) {
                \Log::info('clickanddrop.complete.skipped_duplicate', [
                    'session' => $session->id,
                    'order' => $order->id,
                    'reference' => $order->reference,
                    'clickanddrop_order_identifier' => $existingClickAndDropIdentifier,
                ]);

                return redirect()
                    ->to(url('/admin/orders/completed-orders/' . $order->id . '/details'))
                    ->with('status', 'This order has already been completed and sent to Royal Mail.');
            }
        }


        // First mark session and order as completed
        DB::transaction(function () use ($session) {
            if (empty($session->status) || $session->status !== 'completed') {
                $session->status = 'completed';
                $session->save();
            }

            try {
                $order = $session->order ?? null;
                if ($order) {
                    if (empty($order->status) || in_array($order->status, ['pending', 'processing', 'approved'], true)) {
                        $order->status = 'completed';
                    }
                    $order->save();

                    // Also mark any linked appointment as completed by order_reference
                    try {
                        $appointment = \App\Models\Appointment::where('order_reference', $order->reference)->latest()->first();
                        if ($appointment) {
                            $st = strtolower((string) $appointment->status);
                            if (in_array($st, ['booked', 'approved', 'pending', ''], true)) {
                                $appointment->status = 'completed';
                                $appointment->save();
                                // If this is a weight management service create a Zoom meeting
                                // Zoom creation disabled at request — keeping previous logic commented for reference.
                                /*
                                try {
                                    $serviceSlug = $session->service_slug
                                        ?: \Illuminate\Support\Str::slug((string) $session->service);

                                    $weightSlugs = ['weight-management', 'weight-loss', 'mounjaro', 'wegovy'];

                                    if ($serviceSlug && in_array($serviceSlug, $weightSlugs, true)) {
                                        $zoom = app(\App\Services\ZoomMeetingService::class);
                                        $zoomInfo = $zoom->createForAppointment($appointment, null);

                                        if ($zoomInfo && !empty($zoomInfo['join_url'])) {
                                            $meta = is_array($order->meta)
                                                ? $order->meta
                                                : (json_decode($order->meta ?? '[]', true) ?: []);

                                            $meta['zoom'] = array_replace(
                                                $meta['zoom'] ?? [],
                                                [
                                                    'meeting_id' => $zoomInfo['id'] ?? null,
                                                    'join_url'   => $zoomInfo['join_url'] ?? null,
                                                    'start_url'  => $zoomInfo['start_url'] ?? null,
                                                ]
                                            );

                                            $order->meta = $meta;
                                            $order->save();

                                            \Log::info('consultation.zoom.link_saved', [
                                                'session'   => $session->id,
                                                'order'     => $order->getKey(),
                                                'join_url'  => $zoomInfo['join_url'] ?? null,
                                            ]);
                                        }
                                    }
                                } catch (\Throwable $ze) {
                                    \Log::warning('consultation.zoom.create_failed', [
                                        'session' => $session->id,
                                        'order'   => $order->getKey(),
                                        'error'   => $ze->getMessage(),
                                    ]);
                                }
                                */
                            }
                        }
                    } catch (\Throwable $ae) {
                        \Log::warning('consultation.complete.appointment_update_failed', [
                            'session' => $session->id,
                            'order'   => $order->getKey(),
                            'error'   => $ae->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('consultation.complete.order_update_failed', [
                    'session' => $session->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        });

        // Ensure Record of Supply and Invoice PDFs are generated and stored on the order before emailing
        try {
            $order = $session->order ?? null;
            if ($order) {
                // Maintain a back-reference to the consultation session on the order meta
                $orderMeta = is_array($order->meta)
                    ? $order->meta
                    : (json_decode($order->meta ?? '[]', true) ?: []);

                if (! isset($orderMeta['consultation_session_id'])) {
                    $orderMeta['consultation_session_id'] = $session->id;
                    $order->meta = $orderMeta;
                    $order->save();
                }

                // Generate Record of Supply and Invoice PDFs and update order->meta['pdfs'] paths
                app(\App\Http\Controllers\Admin\ConsultationPdfController::class)
                    ->generateAndStorePdfs($session);
            }
        } catch (\Throwable $e) {
            \Log::warning('consultation.complete.pdf_generate_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }



        $this->sendApprovedEmail($session);

        // After successfully completing, attempt to create a Royal Mail Click & Drop order
        try {
            $order = $session->order ?? null;
            if ($order) {
                \Log::info('clickanddrop.complete.start', [
                    'session'   => $session->id,
                    'order'     => $order->getKey(),
                    'reference' => $order->reference ?? ('CONS-' . $session->id),
                ]);

                // Hydrate missing patient identity and address fields from order->shipping_address and meta
                try {
                    $metaArr = is_array($session->meta)
                        ? $session->meta
                        : (json_decode($session->meta ?? '[]', true) ?: []);

                    $addr = [];

                    if ($order) {
                        // 1) Try the concrete shipping_address column first
                        if (is_array($order->shipping_address)) {
                            $addr = $order->shipping_address;
                        } else {
                            $addr = json_decode($order->shipping_address ?? '[]', true) ?: [];
                        }

                        // 2) If still empty, fall back to common paths inside order->meta
                        if (! is_array($addr) || empty($addr)) {
                            $orderMeta = is_array($order->meta)
                                ? $order->meta
                                : (json_decode($order->meta ?? '[]', true) ?: []);

                            $candidatePaths = [
                                'shipping_address',
                                'shipping.address',
                                'patient.shipping_address',
                                'shipping',
                            ];

                            foreach ($candidatePaths as $path) {
                                $found = data_get($orderMeta, $path);
                                if (is_array($found) && ! empty($found)) {
                                    $addr = $found;
                                    break;
                                }
                            }

                            // 3) As a last resort, try to build an address from top-level keys in meta
                            if ((! is_array($addr) || empty($addr)) && ! empty($orderMeta)) {
                                $guess = [
                                    'first_name'   => $orderMeta['first_name']   ?? $orderMeta['firstName']   ?? null,
                                    'last_name'    => $orderMeta['last_name']    ?? $orderMeta['lastName']    ?? null,
                                    'email'        => $orderMeta['email']        ?? null,
                                    'phone'        => $orderMeta['phone']        ?? $orderMeta['mobile']      ?? null,
                                    // Prefer shipping keys only
                                    'address1'     => data_get($orderMeta, 'shipping_address1')
                                                        ?? data_get($orderMeta, 'shipping.line1')
                                                        ?? data_get($orderMeta, 'shipping.address1')
                                                        ?? data_get($orderMeta, 'shippingAddress1')
                                                        ?? data_get($orderMeta, 'shipping.line_1')
                                                        ?? data_get($orderMeta, 'shipping_address.line1')
                                                        ?? null,
                                    'address2'     => data_get($orderMeta, 'shipping_address2')
                                                        ?? data_get($orderMeta, 'shipping.line2')
                                                        ?? data_get($orderMeta, 'shipping.address2')
                                                        ?? data_get($orderMeta, 'shippingAddress2')
                                                        ?? data_get($orderMeta, 'shipping.line_2')
                                                        ?? data_get($orderMeta, 'shipping_address.line2')
                                                        ?? null,
                                    'city'         => data_get($orderMeta, 'shipping_city')
                                                        ?? data_get($orderMeta, 'shipping.city')
                                                        ?? data_get($orderMeta, 'shipping.town')
                                                        ?? data_get($orderMeta, 'shippingAddress.city')
                                                        ?? null,
                                    'county'       => data_get($orderMeta, 'shipping_county')
                                                        ?? data_get($orderMeta, 'shipping.county')
                                                        ?? null,
                                    'postcode'     => data_get($orderMeta, 'shipping_postcode')
                                                        ?? data_get($orderMeta, 'shipping.postcode')
                                                        ?? data_get($orderMeta, 'shipping.postCode')
                                                        ?? data_get($orderMeta, 'shipping.postal_code')
                                                        ?? null,
                                    'country_code' => data_get($orderMeta, 'shipping_country_code')
                                                        ?? data_get($orderMeta, 'shipping.country_code')
                                                        ?? data_get($orderMeta, 'shipping.country')
                                                        ?? data_get($orderMeta, 'shipping.countryCode')
                                                        ?? null,
                                ];

                                $nonEmpty = array_filter($guess, fn ($v) => $v !== null && $v !== '');
                                if (! empty($nonEmpty)) {
                                    $addr = $guess;
                                }
                            }
                        }
                    }

                    // 2c) If still empty, try user shipping fields from the owning user
                    if ((! is_array($addr) || empty($addr)) && $order && $order->user) {
                        $u = $order->user;
                        $uShipping = [
                            'address1'     => $u->shipping_address1 ?? null,
                            'address2'     => $u->shipping_address2 ?? null,
                            'city'         => $u->shipping_city ?? null,
                            'postcode'     => $u->shipping_postcode ?? null,
                            'country_code' => $u->shipping_country ?? null,
                            'first_name'   => $u->first_name ?? null,
                            'last_name'    => $u->last_name ?? null,
                            'email'        => $u->email ?? null,
                            'phone'        => $u->phone ?? null,
                        ];
                        $nonEmpty = array_filter($uShipping, fn ($v) => $v !== null && $v !== '');
                        if (! empty($nonEmpty)) {
                            $addr = $uShipping;
                        }
                    }

                    if (is_array($addr) && ! empty($addr)) {
                        $changed = false;

                        $map = [
                            'patient.first_name'   => ['first_name', 'firstName', 'name.first', 'first'],
                            'patient.last_name'    => ['last_name', 'lastName', 'name.last', 'last'],
                            'patient.email'        => ['email'],
                            'patient.phone'        => ['phone', 'mobile'],
                            'patient.address1'     => ['address1', 'line1', 'address.line1', 'addressLine1'],
                            'patient.address2'     => ['address2', 'line2', 'address.line2', 'addressLine2'],
                            'patient.city'         => ['city', 'town', 'address.city'],
                            'patient.county'       => ['county', 'address.county'],
                            'patient.postcode'     => ['postcode', 'postCode', 'address.postcode'],
                            'patient.country_code' => ['country_code', 'country', 'address.country_code', 'countryCode'],
                        ];

                        foreach ($map as $to => $fromPaths) {
                            $cur = data_get($metaArr, $to);
                            $val = null;

                            foreach ($fromPaths as $from) {
                                $val = data_get($addr, $from);
                                if ($val !== null && $val !== '') {
                                    break;
                                }
                            }

                            if (($cur === null || $cur === '') && ($val !== null && $val !== '')) {
                                data_set($metaArr, $to, $val);
                                $changed = true;
                            }
                        }

                        if ($changed) {
                            $session->meta = $metaArr;
                            $session->save();
                            \Log::info('consultation.patient_meta.hydrated_from_order', [
                                'session' => $session->id,
                                'source'  => 'order.shipping_address',
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('consultation.patient_meta.hydrate_failed', [
                        'session' => $session->id,
                        'error'   => $e->getMessage(),
                    ]);
                }

                // Try to also hydrate from a concrete Patient model (session or order)
                // Try to also hydrate from a concrete Patient model (session or order or user)
                
                $patientModel = null;
                try {
                    if (method_exists($session, 'patient') || property_exists($session, 'patient')) {
                        $patientModel = $session->patient;
                    }

                    if (! $patientModel && $order && (method_exists($order, 'patient') || property_exists($order, 'patient'))) {
                        $patientModel = $order->patient;
                    }

                    // Fallback to the owning user if there is no dedicated Patient record
                    if (! $patientModel && (method_exists($session, 'user') || property_exists($session, 'user'))) {
                        $patientModel = $session->user;
                    }

                    if (! $patientModel && $order && (method_exists($order, 'user') || property_exists($order, 'user'))) {
                        $patientModel = $order->user;
                    }
                } catch (\Throwable $e) {
                    \Log::warning('consultation.patient_model.resolve_failed', [
                        'session' => $session->id,
                        'error'   => $e->getMessage(),
                    ]);
                }

                if ($patientModel) {
                    $changed = false;
                    $map = [
                        'patient.first_name'   => ['first_name'],
                        'patient.last_name'    => ['last_name'],
                        'patient.email'        => ['email'],
                        'patient.phone'        => ['phone'],
                        'patient.address1'     => ['address1'],
                        'patient.address2'     => ['address2'],
                        'patient.city'         => ['city'],
                        'patient.county'       => ['county'],
                        'patient.postcode'     => ['postcode'],
                        'patient.country_code' => ['country_code', 'country'],
                    ];

                    foreach ($map as $to => $fromPaths) {
                        $cur = data_get($metaArr, $to);
                        if ($cur !== null && $cur !== '') {
                            continue;
                        }
                        foreach ($fromPaths as $from) {
                            $val = data_get($patientModel, $from);
                            if ($val !== null && $val !== '') {
                                data_set($metaArr, $to, $val);
                                $changed = true;
                                break;
                            }
                        }
                    }

                    if ($changed) {
                        $session->meta = $metaArr;
                        $session->save();

                        \Log::info('consultation.patient_meta.hydrated_from_patient', [
                            'session'    => $session->id,
                            'patient_id' => method_exists($patientModel, 'getKey') ? $patientModel->getKey() : null,
                        ]);
                    }
                }

                // Helper to pull values from any nested meta key that ends with one of the given suffixes
                $findMeta = function (array $meta, array $suffixes) {
                    $flat = \Illuminate\Support\Arr::dot($meta);
                    foreach ($flat as $key => $val) {
                        if ($val === null || $val === '') {
                            continue;
                        }
                        foreach ($suffixes as $suffix) {
                            if (\Illuminate\Support\Str::endsWith($key, $suffix)) {
                                return $val;
                            }
                        }
                    }
                    return null;
                };

                // Build a lightweight patient object using hydrated meta plus deep fallbacks
                // Resolve SHIPPING fields first from meta; these will be passed to Click & Drop
                $shipLine1 = data_get($metaArr, 'shipping.address1')
                    ?? data_get($metaArr, 'shipping.line1')
                    ?? data_get($metaArr, 'patient.shipping_address1')
                    ?? data_get($metaArr, 'patient.shipping.line1')
                    ?? data_get($metaArr, 'patient.shipping_address.line1');
                $shipLine2 = data_get($metaArr, 'shipping.address2')
                    ?? data_get($metaArr, 'shipping.line2')
                    ?? data_get($metaArr, 'patient.shipping_address2')
                    ?? data_get($metaArr, 'patient.shipping.line2')
                    ?? data_get($metaArr, 'patient.shipping_address.line2');
                $shipCity = data_get($metaArr, 'shipping.city')
                    ?? data_get($metaArr, 'shipping.town')
                    ?? data_get($metaArr, 'patient.shipping_city')
                    ?? data_get($metaArr, 'patient.shipping.city');
                $shipPostcode = data_get($metaArr, 'shipping.postcode')
                    ?? data_get($metaArr, 'shipping.postCode')
                    ?? data_get($metaArr, 'shipping.postal_code')
                    ?? data_get($metaArr, 'patient.shipping_postcode')
                    ?? data_get($metaArr, 'patient.shipping.postcode');
                $shipCountry = data_get($metaArr, 'shipping.country_code')
                    ?? data_get($metaArr, 'shipping.country')
                    ?? data_get($metaArr, 'patient.shipping_country_code')
                    ?? data_get($metaArr, 'patient.shipping.country_code');

                $firstName = data_get($metaArr, 'patient.first_name')
                    ?? data_get($metaArr, 'patient.name.first')
                    ?? data_get($metaArr, 'first_name')
                    ?? $findMeta($metaArr, ['.first_name', '.firstName', '.name.first']);
                $lastName  = data_get($metaArr, 'patient.last_name')
                    ?? data_get($metaArr, 'patient.name.last')
                    ?? data_get($metaArr, 'last_name')
                    ?? $findMeta($metaArr, ['.last_name', '.lastName', '.name.last']);

                $email    = data_get($metaArr, 'patient.email')
                    ?? $findMeta($metaArr, ['.email']);
                $phone    = data_get($metaArr, 'patient.phone')
                    ?? $findMeta($metaArr, ['.phone', '.mobile']);
                $address1 = $shipLine1
                    ?? data_get($metaArr, 'patient.address1')
                    ?? data_get($metaArr, 'patient.address.line1')
                    ?? $findMeta($metaArr, ['.address1', '.line1', '.addressLine1']);
                $address2 = $shipLine2
                    ?? data_get($metaArr, 'patient.address2')
                    ?? data_get($metaArr, 'patient.address.line2')
                    ?? $findMeta($metaArr, ['.address2', '.line2', '.addressLine2']);
                $city     = $shipCity
                    ?? data_get($metaArr, 'patient.city')
                    ?? data_get($metaArr, 'patient.address.city')
                    ?? $findMeta($metaArr, ['.city', '.town']);
                $county   = data_get($metaArr, 'patient.county')
                    ?? data_get($metaArr, 'patient.address.county')
                    ?? $findMeta($metaArr, ['.county']);
                $postcode = $shipPostcode
                    ?? data_get($metaArr, 'patient.postcode')
                    ?? data_get($metaArr, 'patient.address.postcode')
                    ?? $findMeta($metaArr, ['.postcode', '.postCode']);
                $country  = $shipCountry
                    ?? data_get($metaArr, 'patient.country_code')
                    ?? data_get($metaArr, 'patient.address.country_code')
                    ?? $findMeta($metaArr, ['.country_code', '.countryCode', '.country'])
                    ?? 'GB';

                $patient = (object) [
                    'first_name'           => $firstName,
                    'last_name'            => $lastName,
                    'email'                => $email,
                    'phone'                => $phone,
                    // Home-address style fields (now prefilled with shipping when present)
                    'address1'             => $address1,
                    'address2'             => $address2,
                    'city'                 => $city,
                    'county'               => $county,
                    'postcode'             => $postcode,
                    'country_code'         => $country,
                    // Explicit SHIPPING fields for Click & Drop
                    'shipping_address1'    => $shipLine1,
                    'shipping_address2'    => $shipLine2,
                    'shipping_city'        => $shipCity,
                    'shipping_postcode'    => $shipPostcode,
                    'shipping_country_code'=> $shipCountry,
                ];

                \Log::info('clickanddrop.patient_built', [
                    'session'  => $session->id,
                    'first'    => $patient->first_name ?? null,
                    'last'     => $patient->last_name ?? null,
                    'address1' => $patient->address1 ?? null,
                    'city'     => $patient->city ?? null,
                    'postcode' => $patient->postcode ?? null,
                ]);

                $service = app(\App\Services\Shipping\ClickAndDrop::class);
                $out = $service->createOrder($order, $patient);

                // Try to pull a tracking number from the response if present
                $response = $out['response'] ?? [];
                $tracking = data_get($response, 'createdOrders.0.trackingNumber')
                    ?? data_get($response, 'createdOrders.0.trackingNumbers.0');

                $clickAndDropCreatedOrder = data_get($response, 'createdOrders.0')
                    ?? data_get($response, 'data.createdOrders.0');

                $clickAndDropCreatedOrderIdentifier = data_get($clickAndDropCreatedOrder, 'orderIdentifier');

                if ($clickAndDropCreatedOrderIdentifier) {
                    $orderMetaAfterClickAndDrop = is_array($order->meta)
                        ? $order->meta
                        : (json_decode($order->meta ?? '[]', true) ?: []);

                    data_set($orderMetaAfterClickAndDrop, 'clickanddrop.created_order_identifier', $clickAndDropCreatedOrderIdentifier);
                    data_set($orderMetaAfterClickAndDrop, 'clickanddrop.order_reference', $order->reference);
                    data_set($orderMetaAfterClickAndDrop, 'clickanddrop.created_at', now()->toIso8601String());
                    data_set($orderMetaAfterClickAndDrop, 'clickanddrop.created_order', $clickAndDropCreatedOrder);

                    $order->meta = $orderMetaAfterClickAndDrop;
                    $order->save();

                    \Log::info('clickanddrop.meta_saved', [
                        'session' => $session->id,
                        'order' => $order->id,
                        'reference' => $order->reference,
                        'clickanddrop_order_identifier' => $clickAndDropCreatedOrderIdentifier,
                    ]);
                }

                \Log::info('clickanddrop.complete.ok', [
                    'session'   => $session->id,
                    'order'     => $order->getKey(),
                    'tracking'  => $tracking,
                    'labels'    => $out['label_paths'] ?? [],
                ]);

                // Persist shipping info back onto the session for viewers and PDFs
                data_set($metaArr, 'shipping.carrier', 'royal_mail_click_and_drop');
                if ($tracking) {
                    data_set($metaArr, 'shipping.tracking', $tracking);
                }
                if (! empty($out['label_paths'])) {
                    data_set($metaArr, 'shipping.label_paths', $out['label_paths']);
                }
                if (! empty($response)) {
                    data_set($metaArr, 'shipping.response', $response);
                }
                if (! empty($out['request'])) {
                    data_set($metaArr, 'shipping.request', $out['request']);
                }

                $session->meta = $metaArr;
                $session->save();

                // Also mirror shipping meta back onto the order for order-based viewers
                $orderMeta = is_array($order->meta)
                    ? $order->meta
                    : (json_decode($order->meta ?? '[]', true) ?: []);

                $orderMeta['shipping'] = array_replace_recursive(
                    $orderMeta['shipping'] ?? [],
                    (array) data_get($metaArr, 'shipping', [])
                );

                $order->meta = $orderMeta;
                $order->save();
            } else {
                \Log::info('clickanddrop.complete.skip_no_order', [
                    'session' => $session->id,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('clickanddrop.complete.failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Prefer redirecting to the order if present, and trigger the PDF download on the destination page
        $order = $session->order ?? null;
        if ($order) {
            $url = url('/admin/orders/completed-orders/' . $order->getKey() . '/details?download=pre');
            return redirect()->to($url)->with('success', 'Consultation completed');
        }

        return redirect()->to(url('/admin/consultations/' . $session->id))->with('success', 'Consultation completed');
    }

    /**
     * Send an approval email to the patient after order approval.
     */
    protected function sendApprovedEmail(ConsultationSession $session): void
    {
        $order = $session->order ?? null;
        if (! $order) return;

        $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

        // Determine if this is a weight management style service once and reuse
        $svc = $session->service_slug ?: \Illuminate\Support\Str::slug((string) $session->service);
        $isWeight = in_array($svc, ['weight-management', 'weight-loss', 'mounjaro', 'wegovy'], true);

        $email = data_get($meta, 'email')
            ?? optional($order->patient)->email
            ?? optional($order->user)->email
            ?? optional($session->user)->email;

        if (! $email) {
            Notification::make()->danger()->title('No patient email on record')->send();
            return;
        }

        $first = data_get($meta, 'firstName')
            ?? data_get($meta, 'first_name')
            ?? optional($order->patient)->first_name
            ?? optional($order->user)->first_name
            ?? optional($session->user)->first_name
            ?? '';

        $name = is_string($first) ? trim($first) : 'there';
        $ref  = $order->reference ?? $order->getKey();

        // Optional Zoom link for weight management consultations
        $zoomUrl = data_get($meta, 'zoom.weight_management_join_url')
            ?? data_get($meta, 'zoom.join_url');

        // Optional PDF attachments (Record of Supply and Invoice)
        // Expecting file paths or public URLs to be stored in order meta under pdfs.record_of_supply and pdfs.invoice
        $attachments = [];

        // Attach static GLP-1 guides for weight management services
        try {
            if ($isWeight) {
                $guideDir = storage_path('app/public/guides/weight-management');
                $files = [
                    $guideDir . '/GLP-1 WEIGHT MANAGEMENT_ CLINICAL LIFESTYLE, NUTRITION & MOVEMENT GUIDE.docx.pdf' => 'GLP-1 Lifestyle Nutrition Movement.pdf',
                    $guideDir . '/YOUR WEIGHT LOSS JOURNEY WITH GLP-1 MEDICATIONS – PATIENT GUIDE ON WHAT TO EXPECT.docx.pdf' => 'GLP-1 What To Expect.pdf',
                    $guideDir . '/GLP-1 WEIGHT LOSS PROGRAMME_ PATIENT STARTER PACK & INFORMATION GUIDE ON HOW THE MEDICATION WORKS.docx.pdf' => 'GLP-1 Starter Pack.pdf',
                ];
                foreach ($files as $abs => $downloadName) {
                    if (is_file($abs)) {
                        $attachments[] = [
                            'path' => $abs,
                            'name' => $downloadName,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('consultation.email.glp1_guides_attach_failed', [
                'session' => $session->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Also look directly in storage/app/public/consultations/{session_id} for generated PDFs
        $sessionId = $session->id ?? null;
        if ($sessionId && $ref) {
            $baseDir = storage_path('app/public/consultations/' . $sessionId);

            $rosPath = $baseDir . '/' . $ref . '_supply.pdf';
            if (is_file($rosPath)) {
                $attachments[] = [
                    'path' => $rosPath,
                    'name' => 'Record-of-supply.pdf',
                ];
            }

            $invPath = $baseDir . '/' . $ref . '_invoice.pdf';
            if (is_file($invPath)) {
                $attachments[] = [
                    'path' => $invPath,
                    'name' => 'Invoice.pdf',
                ];
            }

            // Notification of treatment letter for the GP – weight management services only
            if ($isWeight) {
                $notPaths = [
                    $baseDir . '/' . $ref . '_notification-of-treatment.pdf',
                    $baseDir . '/' . $ref . '_notification-of-treatment-issued.pdf',
                    $baseDir . '/' . $ref . '_notification.pdf',
                ];

                foreach ($notPaths as $np) {
                    if (is_file($np)) {
                        $attachments[] = [
                            'path' => $np,
                            'name' => 'Notification-of-treatment.pdf',
                        ];
                        break;
                    }
                }
            }
        }

        // Helper to resolve a relative or public storage path into an absolute filesystem path
        $resolvePath = function ($path) {
            $path = (string) $path;
            if ($path === '') {
                return null;
            }

            // Skip remote URLs here; you can extend this to download and attach via attachData if required
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return null;
            }

            // Common "public storage" style path like /storage/consultations/...
            if (str_starts_with($path, '/storage/')) {
                $relative = ltrim(substr($path, strlen('/storage/')), '/');

                // 1) Try the public/storage symlink if it exists
                $candidate = public_path('storage/' . $relative);
                if (is_file($candidate)) {
                    return $candidate;
                }

                // 2) Fallback directly to storage/app/public
                $candidate = storage_path('app/public/' . $relative);
                if (is_file($candidate)) {
                    return $candidate;
                }

                return null;
            }

            // Absolute path
            if (str_starts_with($path, '/')) {
                return is_file($path) ? $path : null;
            }

            // Try storage/app/public first (handles paths like consultations/foo.pdf stored on the public disk)
            $candidate = storage_path('app/public/' . ltrim($path, '/'));
            if (is_file($candidate)) {
                return $candidate;
            }

            // Fallback to generic storage/app
            $candidate = storage_path('app/' . ltrim($path, '/'));
            if (is_file($candidate)) {
                return $candidate;
            }

            // Final fallback to public
            $candidate = public_path(ltrim($path, '/'));
            if (is_file($candidate)) {
                return $candidate;
            }

            return null;
        };

        $pdfMeta = is_array($meta['pdfs'] ?? null) ? $meta['pdfs'] : [];

        \Log::info('consultation.email.pdf_meta', [
            'order_id' => $order->getKey(),
            'pdf_meta' => $pdfMeta,
        ]);

        if (!empty($pdfMeta['record_of_supply'])) {
            $abs = $resolvePath($pdfMeta['record_of_supply']);
            if ($abs) {
                $attachments[] = [
                    'path' => $abs,
                    'name' => 'Record-of-supply.pdf',
                ];
            }
        }

        if (!empty($pdfMeta['invoice'])) {
            $abs = $resolvePath($pdfMeta['invoice']);
            if ($abs) {
                $attachments[] = [
                    'path' => $abs,
                    'name' => 'Invoice.pdf',
                ];
            }
        }

        // Optional Notification of Treatment PDF – attach only for weight management services
        if ($isWeight) {
            if (!empty($pdfMeta['notification_of_treatment'])) {
                $abs = $resolvePath($pdfMeta['notification_of_treatment']);
                if ($abs) {
                    $attachments[] = [
                        'path' => $abs,
                        'name' => 'Notification-of-treatment.pdf',
                    ];
                }
            } elseif (!empty($pdfMeta['notification'])) {
                $abs = $resolvePath($pdfMeta['notification']);
                if ($abs) {
                    $attachments[] = [
                        'path' => $abs,
                        'name' => 'Notification-of-treatment.pdf',
                    ];
                }
            }
        }

        $subject = 'Your order has been approved';

        $body = "Dear Patient,\n\n"
            . "Your order has been approved. Please see the attached documents, which include:\n\n";

       if ($isWeight) {
            $body .= "Medication Review:\n\n"
                . "• Dose: once weekly injection, same day each week\n"
                . "• Storage: keep pen in fridge\n\n"
                . "Clinical Consultation:\n"
                . "• Weight management consultation\n\n"
                . "Patient Education:\n"
                . "• Injection technique: once weekly subcutaneous injection, same day each week\n"
                . "• Fluid intake: 2-3 litres daily to prevent constipation/diarrhoea\n"
                . "• Side effects discussed: initial nausea and headache (usually resolves), constipation or diarrhoea\n"
                . "• Rare side effect counselling: pancreatitis symptoms (severe abdominal pain radiating to back, high temperature, vomiting) - seek medical advice immediately\n"
                . "• Dosing schedule: start with current strength, reorder at end of week three via website, next dose. (either go down up or stay same in strengths)\n"
                . "• Can remain on current strength if effective and weight loss achieved\n\n"
                . "Important safety information:\n"
                . "Pancreatitis (inflammation of the pancreas) is a possible side effect with GLP-1 receptor agonists and dual GLP-1/GIP receptor agonists. In rare reports this can have serious or fatal outcomes.\n"
                . "Seek urgent medical attention if you experience severe, persistent abdominal pain that may radiate to your back and may be accompanied by nausea and vomiting, as this may be a sign of pancreatitis.\n"
                . "Do not restart GLP-1 receptor agonist or GLP-1/GIP receptor agonist treatment if pancreatitis is confirmed.\n\n"
                . "Plan:\n"
                . "• Order dispatched today or tomorrow latest\n"
                . "• Reorder via website at end of week three for next dose.\n"
                . "• Continue current strength if effective weight loss achieved\n\n";
        }   else {
            $body .= "Your record of supply\n"
                . "Your invoice\n\n";
        }

        $body .= "If you have any questions or experience any issues, please don’t hesitate to contact us by email or via WhatsApp through our website.\n\n"
            . "Thank you.\n\n"
            . "W M Malik MRPharmS";

        if ($zoomUrl) {
            $body .= "\n\nYour video consultation link\n{$zoomUrl}\n";
        }

        try {
            $fromAddress = config('mail.from.address') ?: 'info@safescript.co.uk';
            $fromName    = config('mail.from.name') ?: 'Safescript Pharmacy';

            Mail::raw($body, function ($m) use ($email, $subject, $fromAddress, $fromName, $attachments, $order) {
                $m->from($fromAddress, $fromName)->to($email)->subject($subject);

                // Attach any resolved PDFs (record of supply, invoice)
                \Log::info('consultation.email.attachments_start', [
                    'email'       => $email,
                    'order_id'    => $order->getKey(),
                    'count'       => count($attachments),
                    'attachments' => $attachments,
                ]);

                foreach ($attachments as $att) {
                    $exists = !empty($att['path']) && is_file($att['path']);

                    \Log::info('consultation.email.attach_try', [
                        'email'    => $email,
                        'order_id' => $order->getKey(),
                        'path'     => $att['path'] ?? null,
                        'exists'   => $exists,
                    ]);

                    if ($exists) {
                        $m->attach($att['path'], [
                            'as'   => $att['name'] ?? basename($att['path']),
                            'mime' => 'application/pdf',
                        ]);
                    }
                }
            });

            Notification::make()->success()->title('Approval email sent to ' . $email)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not send approval email')->body(substr($e->getMessage(), 0, 200))->send();
            report($e);
        }
    }
    
    /**
     * REORDER step viewer
     * Opens the Reorder form for the given consultation session.
     */
    public function reorder(string|int $sessionId, \Illuminate\Http\Request $request)
    {
        $session = \App\Models\ConsultationSession::with('order')->findOrFail($sessionId);

        // Choose the right ClinicForm from the session templates snapshot if available
        $form = null;
        $tpl  = data_get($session->templates, 'reorder');

        if (is_array($tpl) && isset($tpl['id'])) {
            $form = \App\Models\ClinicForm::find($tpl['id']);
        } elseif (is_numeric($tpl)) {
            $form = \App\Models\ClinicForm::find((int) $tpl);
        }

        // Fallback by form_type if no direct template pointer
        if (! $form) {
            $form = \App\Models\ClinicForm::query()
                ->where('form_type', 'reorder')
                ->when($session->service, function ($q) use ($session) {
                    $q->where(function ($qq) use ($session) {
                        $qq->whereNull('service_slug')
                           ->orWhere('service_slug', \Illuminate\Support\Str::slug((string) $session->service));
                    });
                })
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();
        }

        $oldData = $this->gatherAnswersFor($session, 'reorder', 'reorder', $form?->id);
        return view('consultations.reorder', [
            'session' => $session,
            'form'    => $form,
            'step'    => 'reorder',
            'oldData' => $oldData,
        ]);
    }

    /**
     * Map a form (ClinicForm or ConsultationFormResponse) to the consultation page slug.
     */
    protected function slugForForm($form): string
    {
        // Resolve form_type regardless of the concrete class
        $formType = null;
        $nameHint = null;

        if ($form instanceof ConsultationFormResponse) {
            $formType = $form->form_type ?? null;
            if ($form->clinic_form_id) {
                $cf = ClinicForm::find($form->clinic_form_id);
                $nameHint = $cf?->name;
                $formType = $formType ?: $cf?->form_type;
            }
        } elseif ($form instanceof ClinicForm) {
            $formType = $form->form_type ?? null;
            $nameHint = $form->name ?? null;
        }

        $t = strtolower((string) ($formType ?? ''));

        // Fallback: infer from name keywords if form_type is missing
        if ($t === '' && $nameHint) {
            $n = strtolower($nameHint);
            if (str_contains($n, 'declaration')) {
                $t = 'pharmacist_declaration';
            } elseif (str_contains($n, 'advice')) {
                $t = 'pharmacist_advice';
            } elseif (str_contains($n, 'record') && str_contains($n, 'supply')) {
                $t = 'record_of_supply';
            } elseif (str_contains($n, 'risk')) {
                $t = 'risk_assessment';
            }
        }

        return match ($t) {
            'supply', 'record_of_supply', 'record-of-supply'       => 'record-of-supply',
            'advice', 'pharmacist_advice', 'pharmacist-advice'     => 'pharmacist-advice',
            'pharmacist_declaration', 'declaration'                => 'pharmacist-declaration',
            'risk', 'risk_assessment', 'risk-assessment'           => 'risk-assessment',
            'reorder', 're-order'                                  => 'reorder',
            default                                                => Str::slug($t ?: 'form', '-'),
        };
    }

    /**
     * Map the page slug to the tab key expected by the runner UI.
     */
    protected function defaultTabKeyForSlug(string $slug): string
    {
        return match ($slug) {
            'pharmacist-declaration' => 'pharmacist_declaration',
            'pharmacist-advice'      => 'pharmacist_advice',
            'record-of-supply'       => 'record_of_supply',
            'reorder'                => 'reorder',
            default                  => Str::slug($slug, '_'),
        };
    }

    /**
     * VIEW a submitted form inside the consultation runner (read-only).
     * Redirects to the correct consultation tab for this form,
     * or returns a minimal inline HTML if ?inline=1 is present.
     */
    public function view(Request $request, ConsultationSession $session, ConsultationFormResponse $form)
    {
        Log::info('forms.view enter', [
            'session_param_type' => is_object($session) ? get_class($session) : gettype($session),
            'session_id'         => $session instanceof ConsultationSession ? $session->id : $session,
            'form_param_type'    => is_object($form) ? get_class($form) : gettype($form),
            'form_id'            => $form instanceof ConsultationFormResponse ? $form->id : null,
            'form_session_id'    => $form instanceof ConsultationFormResponse ? $form->consultation_session_id : null,
            'inline'             => $request->boolean('inline'),
            'has_inline'         => $request->has('inline'),
        ]);
        if ((int) $form->consultation_session_id !== (int) $session->id) {
            abort(404);
        }
        Log::info('forms.view guard passed', [
            'session_id' => $session->id,
            'form_id'    => $form->id,
        ]);

        // Inline modal content (treat presence of the param as true to be safe)
        if ($request->has('inline')) {
$cf      = ClinicForm::find($form->clinic_form_id);
$title   = ($cf?->name ?: 'Form') . ' – View';
$version = $form->form_version ? ('v' . (int) $form->form_version) : '—';
$updated = optional($form->updated_at)->format('d-m-Y H:i');
$dataArr = (array) ($form->data ?? []);

$rowsHtml = '';
foreach ($dataArr as $k => $v) {
    $keyEsc = e((string) $k);
    if (is_string($v) && str_starts_with($v, 'data:image/')) {
        $valHtml = '<img src="'.e($v).'" alt="image" style="max-height:140px;max-width:100%;border-radius:6px;display:block">';
    } else {
        $str = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $isLong = strlen($str) > 160;
        $display = $isLong ? e(mb_substr($str, 0, 160).'…') : e($str);
        $full    = e($str);
        $valHtml = $isLong ? '<span title="'.$full.'">'.$display.'</span>' : '<span>'.$display.'</span>';
    }
    $rowsHtml .= <<<HTML
        <tr>
          <td class="px-3 py-2 text-xs text-gray-300 align-top whitespace-nowrap">{$keyEsc}</td>
          <td class="px-3 py-2 text-sm text-gray-100">{$valHtml}</td>
        </tr>
    HTML;
}

$style = <<<HTML
<style>
  /* Global admin modal background & reset */
  html, body,
  html.fi, html.fi body {
    margin: 0 !important;
    padding: 0 !important;
    background: #0b0b0b !important;
    overflow-x: hidden !important;
  }
  /* Darken all Filament modal layers */
  .fi-modal-window,
  .fi-modal-content,
  .fi-modal-body {
    background: #0b0b0b !important;
    box-shadow: none !important;
  }
  /* Remove border/glow */
  .fi-modal-window { border: 0 !important; }
  .fi-modal-content,
  .fi-modal-body { padding: 0 !important; }
  .fi-modal-header, .fi-modal-footer { display: none !important; }
</style>
HTML;

$html = new HtmlString(<<<HTML
    {$style}
    <div style="background:#0b0b0b;overflow:hidden;border-radius:8px;min-width:560px">
      <div class="p-4 text-gray-100">
        <div class="mb-3">
          <h2 class="text-base font-semibold">{$title}</h2>
          <div class="text-xs text-gray-400">Version {$version} · Updated {$updated}</div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-700/60">
          <table class="min-w-full text-left" style="border-collapse:separate;border-spacing:0">
            <thead class="bg-gray-800/60 text-gray-300 text-xs uppercase">
              <tr>
                <th class="px-3 py-2">Field</th>
                <th class="px-3 py-2">Value</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/60">
              {$rowsHtml}
            </tbody>
          </table>
        </div>
      </div>
    </div>
HTML);

            return response($html);
        }

        // If not explicitly inline, still render the same read-only HTML directly
$cf      = ClinicForm::find($form->clinic_form_id);
$title   = ($cf?->name ?: 'Form') . ' – View';
$version = $form->form_version ? ('v' . (int) $form->form_version) : '—';
$updated = optional($form->updated_at)->format('d-m-Y H:i');
$dataArr = (array) ($form->data ?? []);

$rowsHtml = '';
foreach ($dataArr as $k => $v) {
    $keyEsc = e((string) $k);
    if (is_string($v) && str_starts_with($v, 'data:image/')) {
        $valHtml = '<img src="'.e($v).'" alt="image" style="max-height:140px;max-width:100%;border-radius:6px;display:block">';
    } else {
        $str = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $isLong = strlen($str) > 160;
        $display = $isLong ? e(mb_substr($str, 0, 160).'…') : e($str);
        $full    = e($str);
        $valHtml = $isLong ? '<span title="'.$full.'">'.$display.'</span>' : '<span>'.$display.'</span>';
    }
    $rowsHtml .= <<<HTML
        <tr>
          <td class="px-3 py-2 text-xs text-gray-300 align-top whitespace-nowrap">{$keyEsc}</td>
          <td class="px-3 py-2 text-sm text-gray-100">{$valHtml}</td>
        </tr>
    HTML;
}

$style = <<<HTML
<style>
  /* Global admin modal background & reset */
  html, body,
  html.fi, html.fi body {
    margin: 0 !important;
    padding: 0 !important;
    background: #0b0b0b !important;
    overflow-x: hidden !important;
  }
  /* Darken all Filament modal layers */
  .fi-modal-window,
  .fi-modal-content,
  .fi-modal-body {
    background: #0b0b0b !important;
    box-shadow: none !important;
  }
  /* Remove border/glow */
  .fi-modal-window { border: 0 !important; }
  .fi-modal-content,
  .fi-modal-body { padding: 0 !important; }
  .fi-modal-header, .fi-modal-footer { display: none !important; }
</style>
HTML;

$html = new HtmlString(<<<HTML
    {$style}
    <div style="background:#0b0b0b;overflow:hidden;border-radius:8px;min-width:560px">
      <div class="p-4 text-gray-100">
        <div class="mb-3">
          <h2 class="text-base font-semibold">{$title}</h2>
          <div class="text-xs text-gray-400">Version {$version} · Updated {$updated}</div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-700/60">
          <table class="min-w-full text-left" style="border-collapse:separate;border-spacing:0">
            <thead class="bg-gray-800/60 text-gray-300 text-xs uppercase">
              <tr>
                <th class="px-3 py-2">Field</th>
                <th class="px-3 py-2">Value</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/60">
              {$rowsHtml}
            </tbody>
          </table>
        </div>
      </div>
    </div>
HTML);

        return response($html);
    }

    /**
     * EDIT a form by sending the user to the correct consultation step in edit mode,
     * or returns a minimal inline modal placeholder if ?inline=1.
     */
    public function edit(Request $request, ConsultationSession $session, ConsultationFormResponse $form)
    {
        if ((int) $form->consultation_session_id !== (int) $session->id) {
            abort(404);
        }

        if ($request->boolean('inline')) {
            $slug = $this->slugForForm($form);
            $tab  = $form->step_slug ?: $this->defaultTabKeyForSlug($slug);
            $runnerUrl = url("/admin/consultations/{$session->id}/{$slug}?tab={$tab}&edit=1");
            $html = new HtmlString(<<<HTML
<style>
  /* === Global dark background + reset ===
     Prevents the browser’s default 8px body margin
     and ensures a consistent black canvas even inside iframes or modals.
  */
  html, body,
  html.fi, html.fi body {
      margin: 0 !important;
      padding: 0 !important;
      background: #0b0b0b !important; /* dark theme base */
      overflow-x: hidden !important;   /* avoid horizontal scrollbars */
  }

  /* === Filament modal layer overrides ===
     Filament modals normally have transparent or light backdrops.
     These force them to match the dark admin background.
  */
  .fi-modal-window,
  .fi-modal-content,
  .fi-modal-body {
      background: #0b0b0b !important;
      box-shadow: none !important; /* remove default light shadow */
  }

  /* Remove any faint white edge or outline around the modal */
  .fi-modal-window { border: 0 !important; }

  /* Strip modal padding to let iframe or inner content take full space */
  .fi-modal-content,
  .fi-modal-body { padding: 0 !important; }

  /* Hide default Filament modal header & footer for a clean, frameless look */
  .fi-modal-header,
  .fi-modal-footer { display: none !important; }
</style>

<div style="background:#0b0b0b; overflow:hidden; border-radius:8px; height:170vh;">
  <iframe id="runnerFrame" src="{$runnerUrl}" style="display:block; width:100%; height:100%; border:0; background:#0b0b0b; color:#fff;" referrerpolicy="no-referrer" loading="eager"></iframe>
</div>

<script>
  (function () {
    var ifr = document.getElementById('runnerFrame');
    if (!ifr) return;

    function paintIframe() {
      try {
        var d = ifr.contentDocument || (ifr.contentWindow &amp;&amp; ifr.contentWindow.document);
        if (!d) return;

        // Ensure dark background and zero margins inside the iframe
        if (d.documentElement) {
          d.documentElement.style.setProperty('background', '#0b0b0b', 'important');
        }
        if (d.body) {
          d.body.style.setProperty('background', '#0b0b0b', 'important');
          d.body.style.setProperty('margin', '0', 'important');
          d.body.style.setProperty('padding', '0', 'important');
          // Force readable text
          d.body.style.setProperty('color', '#fff', 'important');
        }
      } catch (e) {
        // Ignore cross-document timing issues
      }
    }

    // Run when the iframe loads, and again shortly after in case of late hydration
    ifr.addEventListener('load', function () {
      paintIframe();
      setTimeout(paintIframe, 50);
      setTimeout(paintIframe, 300);
    });
  })();
</script>
HTML);
            return response($html);
        }

        // Fallback for non-inline clicks: redirect to the inline wrapper (robust to custom binders)
        $routeParam = $request->route('form');
        $formId = null;

        if ($form instanceof ConsultationFormResponse) {
            $formId = $form->getKey();
        } elseif ($routeParam instanceof ConsultationFormResponse) {
            $formId = $routeParam->getKey();
        } elseif ($routeParam instanceof ClinicForm) {
            $formId = ConsultationFormResponse::where('consultation_session_id', $session->id)
                ->where('clinic_form_id', $routeParam->getKey())
                ->orderByDesc('id')
                ->value('id');
        } elseif (is_numeric($routeParam)) {
            $formId = (int) $routeParam;
        }

        if (!$formId) {
            $formId = ConsultationFormResponse::where('consultation_session_id', $session->id)
                ->orderByDesc('id')
                ->value('id');
        }

        abort_if(!$formId, 404);

        $selfInline = url("/admin/consultations/{$session->id}/forms/{$formId}/edit?inline=1");
        return redirect()->to($selfInline);
    }

    /**
     * HISTORY: lightweight HTML list of previous saves for this form in the session.
     * (Kept simple so it works even without a Blade view.)
     */
    public function history(Request $request, ConsultationSession $session, ConsultationFormResponse $form)
    {
        if ((int) $form->consultation_session_id !== (int) $session->id) {
            abort(404);
        }

        $rows = ConsultationFormResponse::query()
            ->where('consultation_session_id', $session->id)
            ->where('form_type', $form->form_type)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'is_complete', 'created_at', 'updated_at', 'updated_by', 'form_version'])
            ->map(function ($r) use ($session, $form) {
                $viewUrl   = url("/admin/consultations/{$session->id}/forms/{$r->id}/view?inline=1");
                $editUrl   = url("/admin/consultations/{$session->id}/forms/{$r->id}/edit?inline=1");
                $statusBad = $r->is_complete ? '<span class="px-2 py-1 text-xs rounded bg-green-500/15 text-green-400 border border-green-500/30">Complete</span>'
                                             : '<span class="px-2 py-1 text-xs rounded bg-amber-500/15 text-amber-400 border border-amber-500/30">Draft</span>';

                $created = optional($r->created_at)->format('d-m-Y H:i');
                $updated = optional($r->updated_at)->format('d-m-Y H:i');
                $userId  = $r->updated_by ? ('#' . (int) $r->updated_by) : '—';
                $ver     = $r->form_version ? ('v' . (int) $r->form_version) : '—';

                return <<<HTML
                  <tr class="border-b border-gray-700/60">
                    <td class="px-3 py-2 text-xs text-gray-300">{$r->id}</td>
                    <td class="px-3 py-2 text-xs">{$statusBad}</td>
                    <td class="px-3 py-2 text-xs text-gray-300">{$ver}</td>
                    <td class="px-3 py-2 text-xs text-gray-300 whitespace-nowrap">{$created}</td>
                    <td class="px-3 py-2 text-xs text-gray-300 whitespace-nowrap">{$updated}</td>
                    <td class="px-3 py-2 text-xs text-gray-400">{$userId}</td>
                    <td class="px-3 py-2 text-xs text-right">
                      <a href="{$viewUrl}" data-inline-modal="1" class="inline-flex items-center px-2 py-1 rounded bg-gray-700 hover:bg-gray-600 text-gray-100">View</a>
                      <a href="{$editUrl}" data-inline-modal="1" class="inline-flex items-center px-2 py-1 rounded bg-primary-600 hover:bg-primary-500 text-white ml-2">Edit</a>
                    </td>
                  </tr>
                HTML;
            })
            ->implode('');

        $html = new HtmlString(<<<HTML
            <div style="padding:16px">
              <h2 style="font-weight:600;margin-bottom:10px">Submission history</h2>
              <div class="overflow-x-auto rounded-lg border border-gray-700/60">
                <table class="min-w-full text-left text-sm">
                  <thead class="bg-gray-800/60 text-gray-300 text-xs uppercase">
                    <tr>
                      <th class="px-3 py-2">#</th>
                      <th class="px-3 py-2">Status</th>
                      <th class="px-3 py-2">Form Ver</th>
                      <th class="px-3 py-2">Created</th>
                      <th class="px-3 py-2">Updated</th>
                      <th class="px-3 py-2">By</th>
                      <th class="px-3 py-2 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-700/60">
                    {$rows}
                  </tbody>
                </table>
              </div>
            </div>
        HTML);

        return response($html);
    }
}