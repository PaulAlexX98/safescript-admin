<?php

namespace App\Http\Controllers;

use Log;
use Illuminate\Http\Request;
use App\Models\ClinicForm;
use App\Models\ConsultationFormResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use App\Models\ConsultationSession;

class ConsultationFormController extends Controller
{
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
        $userId       = auth()->id();

        // Ensure the consultation session exists before using it
        $session = ConsultationSession::query()->findOrFail($sessionId);

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

        // Prefer session slugs if available, else fallback to the clinic form slugs or slugified names
        $serviceSlugForForm = (string) ($session->service_slug ?: $form->service_slug ?: '');
        $treatmentSlugForForm = (string) ($session->treatment_slug ?: $form->treatment_slug ?: '');
        if ($serviceSlugForForm === '' && ($form->service_name ?? null)) {
            $serviceSlugForForm = Str::slug((string) $form->service_name);
        }
        if ($treatmentSlugForForm === '' && ($form->treatment_name ?? null)) {
            $treatmentSlugForForm = Str::slug((string) $form->treatment_name);
        }

        // 3) Verify posted form matches the session's service/treatment to avoid cross‑posting
        // Normalize everything to slugs before comparing, and only enforce if the session has values
        $sessionService    = Str::slug((string) ($session->service_slug ?? ''));
        $sessionTreatment  = Str::slug((string) ($session->treatment_slug ?? ''));
        $formService       = Str::slug((string) ($form->service_slug ?? ''));
        $formTreatment     = Str::slug((string) ($form->treatment_slug ?? ''));
        
        $serviceMismatch   = $sessionService   !== '' && $formService   !== '' && $formService   !== $sessionService;
        $treatmentMismatch = $sessionTreatment !== '' && $formTreatment !== '' && $formTreatment !== $sessionTreatment;
        
        if ($serviceMismatch || $treatmentMismatch) {
            abort(422, 'Form does not match the current consultation session.');
        }

        // 3b) Schema-driven validation for required fields
        $rawSchema = is_array($form->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);
        $rules = [];
        foreach ($rawSchema as $idx => $fld) {
            $type = $fld['type'] ?? 'text_input';
            $cfg  = (array)($fld['data'] ?? []);

            // Skip non-input blocks
            if ($type === 'text_block') {
                continue;
            }

            // Resolve the posted field name. Different renderers may derive the name
            // from schema->name, from a slugged label, or fall back to field_{idx}.
            $labelRaw   = $cfg['label'] ?? ($fld['label'] ?? null);
            $slugLabel  = $labelRaw ? Str::slug($labelRaw, '_') : null;
            // honour explicit keys from builder or importer
            $schemaKey  = $cfg['key'] ?? ($fld['key'] ?? null);

            $candidates = array_values(array_filter([
                $fld['name'] ?? null,
                $schemaKey,
                $slugLabel,
                ($type === 'text_block' ? ('block_'.$idx) : ('field_'.$idx)),
            ]));

            $name = null;
            foreach ($candidates as $cand) {
                if ($request->has($cand)) { $name = $cand; break; }
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
            $slugLabel  = $labelRaw ? Str::slug($labelRaw, '_') : null;
            $schemaKey  = $cfg['key'] ?? ($fld['key'] ?? null);

            // Posted name candidates as they may differ between renderers
            $candidates = array_values(array_filter([
                $fld['name'] ?? null,
                $schemaKey,
                $slugLabel,
                ($type === 'text_block' ? ('block_'.$idx) : ('field_'.$idx)),
            ]));

            // Stable storage key prioritises explicit key, then name, then slug(label), then field_{n}
            $storeKey = $schemaKey ?: ($fld['name'] ?? ($slugLabel ?: ('field_'.$idx)));

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
                $stored = null;
                if (is_array($file)) {
                    $stored = array_values(array_filter(array_map(function ($f) {
                        return $f ? $f->store('consultations', ['disk' => 'public']) : null;
                    }, $file)));
                } else {
                    $stored = $file ? $file->store('consultations', ['disk' => 'public']) : null;
                }

                // Map the posted upload field name to its schema "store" key
                foreach ($fields as $f) {
                    if (in_array($key, $f['candidates'], true)) {
                        $submitted[$f['store']] = $stored;
                        break;
                    }
                }
            }
        }

        // 4b) Final payload for persistence
        $payload = $submitted;

        // 5) Upsert by unique scope (with robust fallbacks)
        $scope = [
            'consultation_session_id' => $session->id,
            'form_type'               => $derivedFormType,
            'service_slug'            => $serviceSlugForForm,
            'treatment_slug'          => $treatmentSlugForForm,
        ];

        /** @var ConsultationFormResponse|null $existing */
        $existing = ConsultationFormResponse::query()->where($scope)->first();

        // Preserve completion timestamp unless transitioning to complete
        $isComplete  = $existing ? (bool)$existing->is_complete : false;
        $completedAt = $existing ? $existing->completed_at : null;
        if ($markComplete && !$isComplete) {
            $isComplete  = true;
            $completedAt = now();
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
            $val = $data[$field] ?? null;

            if (array_key_exists('equals', $cond)) {
                return (string)$val === (string)$cond['equals'];
            }
            if (!empty($cond['in']) && is_array($cond['in'])) {
                return in_array((string)$val, array_map('strval', $cond['in']), true);
            }
            if (array_key_exists('notEquals', $cond)) {
                return (string)$val !== (string)$cond['notEquals'];
            }
            if (!empty($cond['truthy'])) {
                return !empty($val);
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
            'session_id' => $session->id,
            'form_id'    => $form->id,
            'step'       => $stepSlug,
            'saved_keys' => array_keys($persistData),
            'count'      => count($persistData),
        ]);

        $values = [
            'clinic_form_id' => $form->id,
            'form_type'      => $derivedFormType,
            'service_slug'   => $serviceSlugForForm,
            'treatment_slug' => $treatmentSlugForForm,
            'step_slug'      => $stepSlug,
            'form_version'   => $formVersion,
            'data'           => $persistData,
            'is_complete'    => $isComplete,
            'completed_at'   => $completedAt,
            'updated_by'     => $userId,
            'created_by'     => $existing?->created_by ?? $userId,
        ];

        ConsultationFormResponse::query()->updateOrCreate($scope, $values);

        // 7) Smart redirect: keep user on the same tab
        $backUrl = url()->previous();
        // append or replace the "tab" query with the current step slug
        $target  = $backUrl ? preg_replace('/([?&])tab=[^&]*/', '$1tab='.$stepSlug, $backUrl) : null;
        if ($target && !str_contains($target, 'tab=')) {
            $target .= (str_contains($target, '?') ? '&' : '?').'tab='.$stepSlug;
        }

        // Support JSON consumers (e.g., Livewire/HTMX) gracefully
        if ($request->wantsJson()) {
            return response()->json([
                'ok'            => true,
                'message'       => 'Form saved successfully',
                'step'          => $stepSlug,
                'is_complete'   => $isComplete,
                'completed_at'  => optional($completedAt)->toISOString(),
            ]);
        }

        return $target
            ? redirect()->to($target)->with('success', 'Form saved successfully!')
            : back()->with('success', 'Form saved successfully!');
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