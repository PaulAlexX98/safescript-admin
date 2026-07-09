<?php

namespace App\Filament\Resources\WalkIns\Schemas;

use App\Models\Patient;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;
use App\Models\Order;
use App\Models\PendingOrder;

class WalkInForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Patient details')
                    ->columnSpanFull()
                    ->headerActions([])
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                Select::make('patient_id')
                                    ->label('Search patient')
                                    ->placeholder('Search by name, email, or phone')
                                    ->searchable()
                                    ->getSearchResultsUsing(function (string $search): array {
                                        $search = trim($search);

                                        if (mb_strlen($search) < 3) {
                                            return [];
                                        }

                                        return Patient::query()
                                            ->with('user')
                                            ->where(function ($query) use ($search) {
                                                $query
                                                    ->where('internal_id', 'like', "%{$search}%")
                                                    ->orWhere('first_name', 'like', "%{$search}%")
                                                    ->orWhere('last_name', 'like', "%{$search}%")
                                                    ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", ["%{$search}%"])
                                                    ->orWhere('email', 'like', "%{$search}%")
                                                    ->orWhere('phone', 'like', "%{$search}%")
                                                    ->orWhereHas('user', function ($userQuery) use ($search) {
                                                        $userQuery
                                                            ->where('first_name', 'like', "%{$search}%")
                                                            ->orWhere('last_name', 'like', "%{$search}%")
                                                            ->orWhereRaw("concat_ws(' ', first_name, last_name) like ?", ["%{$search}%"])
                                                            ->orWhere('email', 'like', "%{$search}%")
                                                            ->orWhere('phone', 'like', "%{$search}%");
                                                    });
                                            })
                                            ->limit(25)
                                            ->get()
                                            ->mapWithKeys(function ($patient) {
                                                $fullName = trim((string) (($patient->first_name ?: $patient->user?->first_name ?: '') . ' ' . ($patient->last_name ?: $patient->user?->last_name ?: '')));
                                                $email = $patient->email ?: $patient->user?->email ?: 'No email';
                                                $phone = $patient->phone ?: $patient->user?->phone ?: null;
                                                $label = $fullName !== '' ? $fullName : ('Patient #' . $patient->id);
                                                $meta = $email;
                                                if ($phone) {
                                                    $meta .= ' • ' . $phone;
                                                }
                                                return [$patient->id => $label . ' — ' . $meta];
                                            })
                                            ->toArray();
                                    })
                                    ->getOptionLabelUsing(function ($value): ?string {
                                        if (! $value) {
                                            return null;
                                        }

                                        $patient = Patient::query()->with('user')->find($value);
                                        if (! $patient) {
                                            return null;
                                        }

                                        $fullName = trim((string) (($patient->first_name ?: $patient->user?->first_name ?: '') . ' ' . ($patient->last_name ?: $patient->user?->last_name ?: '')));
                                        $email = $patient->email ?: $patient->user?->email ?: 'No email';
                                        $phone = $patient->phone ?: $patient->user?->phone ?: null;
                                        $label = $fullName !== '' ? $fullName : ('Patient #' . $patient->id);
                                        $meta = $email;
                                        if ($phone) {
                                            $meta .= ' • ' . $phone;
                                        }

                                        return $label . ' — ' . $meta;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $patient = Patient::query()->with('user')->find($state);
                                        if (! $patient) {
                                            return;
                                        }

                                        $user = $patient->user;
                                        $set('user_id', $user?->id ?: null);

                                        $set('first_name', $patient->first_name ?: $user?->first_name ?: null);
                                        $set('last_name', $patient->last_name ?: $user?->last_name ?: null);
                                        $set('dob', $patient->dob ?: $user?->dob ?: null);
                                        $rawGender = $patient->gender ?: $user?->gender ?: null;
                                        $normalisedGender = null;
                                        if (is_string($rawGender) && trim($rawGender) !== '') {
                                            $g = strtolower(trim($rawGender));
                                            $normalisedGender = match ($g) {
                                                'male', 'm' => 'male',
                                                'female', 'f' => 'female',
                                                'other' => 'other',
                                                'prefer_not_to_say', 'prefer not to say', 'prefer-not-to-say' => 'prefer_not_to_say',
                                                default => null,
                                            };
                                        }
                                        $set('gender', $normalisedGender);
                                        $set('email', $patient->email ?: $user?->email ?: null);
                                        $set('phone', $patient->phone ?: $user?->phone ?: null);
                                        $set('address_line_1', $patient->address_line_1 ?: $user?->address_line_1 ?: $user?->address1 ?: $user?->shipping_address1 ?: null);
                                        $set('address_line_2', $patient->address_line_2 ?: $user?->address_line_2 ?: $user?->address2 ?: $user?->shipping_address2 ?: null);
                                        $set('city', $patient->city ?: $user?->city ?: $user?->shipping_city ?: null);
                                        $set('county', $patient->county ?: $user?->county ?: null);
                                        $set('postcode', $patient->postcode ?: $user?->postcode ?: $user?->shipping_postcode ?: null);
                                        $set('country', $patient->country ?: $user?->country ?: $user?->shipping_country ?: 'United Kingdom');
                                    })
                                    ->columnSpan(10),
                                                                  Placeholder::make('order_history')
                                  ->label('Order history')
                                  ->content(function (callable $get): HtmlString {
                                      $patientId = $get('patient_id');

                                      if (! $patientId) {
                                          return new HtmlString('<div style="font-size:14px;color:#9ca3af;">Search for and select a patient to view their order history.</div>');
                                      }

                                      $patient = Patient::query()->with('user')->find($patientId);

                                      if (! $patient) {
                                          return new HtmlString('<div style="font-size:14px;color:#9ca3af;">Patient record not found.</div>');
                                      }

                                      $userId = $patient->user_id ?: $patient->user?->id;

                                      $query = Order::query()
                                          ->where('status', 'completed')
                                          ->where(function ($where) use ($userId, $patientId): void {
                                              if ($userId) {
                                                  $where
                                                      ->orWhere('user_id', $userId)
                                                      ->orWhereRaw("JSON_EXTRACT(meta, '$.user_id') = ?", [$userId])
                                                      ->orWhereRaw("JSON_EXTRACT(meta, '$.user.id') = ?", [$userId]);
                                              }

                                              if ($patientId && SchemaFacade::hasColumn('orders', 'patient_id')) {
                                                  $where
                                                      ->orWhere('patient_id', $patientId)
                                                      ->orWhereRaw("JSON_EXTRACT(meta, '$.patient_id') = ?", [$patientId])
                                                      ->orWhereRaw("JSON_EXTRACT(meta, '$.patient.id') = ?", [$patientId]);
                                              }
                                          });

                                      $orders = $query->latest('id')->limit(25)->get();

                                      if ($orders->isEmpty()) {
                                          return new HtmlString('<div style="font-size:14px;color:#9ca3af;">No completed orders were found for this patient.</div>');
                                      }

                                      $money = function (Order $order): string {
                                          if (isset($order->products_total_minor) && is_numeric($order->products_total_minor)) {
                                              return '£' . number_format(((int) $order->products_total_minor) / 100, 2);
                                          }

                                          $meta = is_array($order->meta)
                                              ? $order->meta
                                              : (json_decode($order->meta ?? '[]', true) ?: []);

                                          $value = data_get($meta, 'products_total_minor')
                                              ?? data_get($meta, 'totalMinor')
                                              ?? data_get($meta, 'amountMinor');

                                          if (is_numeric($value)) {
                                              return '£' . number_format(((int) $value) / 100, 2);
                                          }

                                          $sum = 0;

                                          foreach ((array) (data_get($meta, 'items') ?? data_get($meta, 'lines') ?? []) as $item) {
                                              if (! is_array($item)) {
                                                  continue;
                                              }

                                              $quantity = (int) ($item['qty'] ?? $item['quantity'] ?? 1) ?: 1;
                                              $minor = $item['totalMinor'] ?? $item['lineTotalMinor'] ?? $item['amountMinor'] ?? null;

                                              if ($minor === null && isset($item['unitMinor'])) {
                                                  $minor = (int) $item['unitMinor'] * $quantity;
                                              }

                                              if (is_numeric($minor)) {
                                                  $sum += (int) $minor;
                                              }
                                          }

                                          return $sum > 0 ? '£' . number_format($sum / 100, 2) : '—';
                                      };

                                      $itemsSummary = function (Order $order): string {
                                          $meta = is_array($order->meta)
                                              ? $order->meta
                                              : (json_decode($order->meta ?? '[]', true) ?: []);

                                          $candidates = [
                                              data_get($meta, 'items'),
                                              data_get($meta, 'lines'),
                                              data_get($meta, 'products'),
                                              data_get($meta, 'line_items'),
                                          ];

                                          $items = collect($candidates)
                                              ->first(fn ($candidate) => is_array($candidate) && count($candidate)) ?? [];

                                          if (empty($items)) {
                                              $name = data_get($meta, 'product_name') ?? data_get($meta, 'selectedProduct.name');

                                              if (! $name) {
                                                  return '—';
                                              }

                                              $quantity = (int) (data_get($meta, 'qty') ?? data_get($meta, 'quantity') ?? 1) ?: 1;
                                              $option = data_get($meta, 'selectedProduct.variations')
                                                  ?? data_get($meta, 'selectedProduct.optionLabel')
                                                  ?? data_get($meta, 'variant')
                                                  ?? data_get($meta, 'dose')
                                                  ?? data_get($meta, 'strength');

                                              if (is_array($option)) {
                                                  $option = $option['label'] ?? $option['value'] ?? '';
                                              }

                                              return e(trim("{$quantity} × {$name}" . ($option ? " {$option}" : '')));
                                          }

                                          $labels = [];

                                          foreach ($items as $item) {
                                              if (! is_array($item)) {
                                                  continue;
                                              }

                                              $quantity = (int) ($item['qty'] ?? $item['quantity'] ?? 1) ?: 1;
                                              $name = $item['name'] ?? $item['title'] ?? $item['product_name'] ?? 'Item';
                                              $option = data_get($item, 'variations')
                                                  ?? data_get($item, 'variation')
                                                  ?? data_get($item, 'optionLabel')
                                                  ?? data_get($item, 'variant')
                                                  ?? data_get($item, 'dose')
                                                  ?? data_get($item, 'strength')
                                                  ?? data_get($item, 'option');

                                              if (is_array($option)) {
                                                  $option = $option['label']
                                                      ?? $option['value']
                                                      ?? implode(' ', array_filter(array_map('strval', $option)));
                                              }

                                              $labels[] = e(trim("{$quantity} × {$name}" . ($option ? " {$option}" : '')));

                                              if (count($labels) >= 2) {
                                                  break;
                                              }
                                          }

                                          $html = implode('<br>', $labels);
                                          $remaining = max(0, count($items) - 2);

                                          if ($remaining) {
                                              $html .= '<br><span class="oh-more">+' . $remaining . ' more</span>';
                                          }

                                          return $html ?: '—';
                                      };

                                      // Helper to extract a measurement value from answer containers
                                      $extractMeasurementFromAnswers = function ($answers, $keys) {
                                          if (!is_array($answers)) return null;
                                          foreach ($keys as $key) {
                                              $val = data_get($answers, $key);
                                              if ($val !== null && trim((string)$val) !== '') {
                                                  return trim((string)$val);
                                              }
                                          }
                                          // Also check for direct key in root if answers is associative
                                          if (array_values($answers) !== $answers) {
                                              foreach ($keys as $key) {
                                                  if (isset($answers[$key]) && trim((string)$answers[$key]) !== '') {
                                                      return trim((string)$answers[$key]);
                                                  }
                                              }
                                          }
                                          return null;
                                      };

                                      // Helper to extract from ConsultationFormResponse fallback
                                      $extractFromFormResponses = function ($sessionId, $keys, $types) use ($extractMeasurementFromAnswers) {
                                          if (!$sessionId) return null;
                                          $responses = \App\Models\ConsultationFormResponse::where('consultation_session_id', $sessionId)
                                              ->whereIn('form_type', $types)
                                              ->orderByDesc('updated_at')
                                              ->get();
                                          foreach ($responses as $resp) {
                                              $data = is_array($resp->data) ? $resp->data : (json_decode($resp->data ?? '[]', true) ?: []);
                                              $val = $extractMeasurementFromAnswers($data, $keys);
                                              if ($val !== null && $val !== '') {
                                                  return $val;
                                              }
                                          }
                                          return null;
                                      };

                                      $weightFromOrder = function (Order $order) use ($extractMeasurementFromAnswers, $extractFromFormResponses) {
                                          $meta = is_array($order->meta)
                                              ? $order->meta
                                              : (json_decode($order->meta ?? '[]', true) ?: []);
                                          // Direct meta keys (unchanged, always first priority)
                                          $directValues = [
                                              data_get($meta, 'weight'),
                                              data_get($meta, 'weight_kg'),
                                              data_get($meta, 'current_weight'),
                                              data_get($meta, 'body_weight'),
                                              data_get($meta, 'patient_weight'),
                                              data_get($meta, 'raf.weight'),
                                              data_get($meta, 'raf.weight_kg'),
                                              data_get($meta, 'riskAssessment.weight'),
                                              data_get($meta, 'riskAssessment.weight_kg'),
                                          ];
                                          foreach ($directValues as $value) {
                                              if ($value === null) continue;
                                              $output = trim((string)$value);
                                              if ($output !== '') return e($output);
                                          }
                                          // QA fallback (unchanged)
                                          $questions = data_get($meta, 'formsQA.raf.qa');
                                          if (is_array($questions)) {
                                              foreach ($questions as $row) {
                                                  $key = strtolower(trim((string) ($row['key'] ?? '')));
                                                  $question = strtolower(trim((string) ($row['question'] ?? '')));
                                                  $isWeight = (str_contains($key, 'weight') || str_contains($question, 'weight'))
                                                      && ! str_contains($key, 'target')
                                                      && ! str_contains($key, 'goal')
                                                      && ! str_contains($question, 'target')
                                                      && ! str_contains($question, 'goal');
                                                  if (! $isWeight) continue;
                                                  $answer = $row['answer'] ?? $row['raw'] ?? null;
                                                  if ($answer === null) continue;
                                                  $output = is_array($answer)
                                                      ? trim(implode(', ', array_filter(array_map('strval', $answer))))
                                                      : trim((string) $answer);
                                                  if ($output !== '') return e($output);
                                              }
                                          }
                                          // Begin new: consultation session fallback
                                          $orderMeta = $meta;
                                          $sessionId = data_get($orderMeta, 'consultation_session_id');
                                          if ($sessionId) {
                                              $session = \App\Models\ConsultationSession::find($sessionId);
                                              if ($session && $session->meta) {
                                                  $sessionMeta = is_array($session->meta) ? $session->meta : (json_decode($session->meta ?? '[]', true) ?: []);
                                                  $answerContainers = [
                                                      data_get($sessionMeta, 'forms.reorder.answers'),
                                                      data_get($sessionMeta, 'formsQA.reorder'),
                                                      data_get($sessionMeta, 'forms_qa.reorder'),
                                                      data_get($sessionMeta, 'forms.risk_assessment.answers'),
                                                      data_get($sessionMeta, 'forms.risk-assessment.answers'),
                                                      data_get($sessionMeta, 'forms.raf.answers'),
                                                      data_get($sessionMeta, 'formsQA.risk_assessment'),
                                                      data_get($sessionMeta, 'formsQA.risk-assessment'),
                                                      data_get($sessionMeta, 'formsQA.raf'),
                                                      data_get($sessionMeta, 'forms_qa.risk_assessment'),
                                                      data_get($sessionMeta, 'forms_qa.risk-assessment'),
                                                      data_get($sessionMeta, 'forms_qa.raf'),
                                                  ];
                                                  $weightKeys = ['weight', 'weight_kg', 'current_weight', 'weightkg', 'body_weight', 'your_weight'];
                                                  foreach ($answerContainers as $answers) {
                                                      $val = $extractMeasurementFromAnswers($answers, $weightKeys);
                                                      if ($val !== null && $val !== '') return e($val);
                                                  }
                                              }
                                              // Final fallback: ConsultationFormResponse
                                              $weightKeys = ['weight', 'weight_kg', 'current_weight', 'weightkg', 'body_weight', 'your_weight'];
                                              $formTypes = ['reorder', 'raf', 'risk_assessment', 'risk-assessment', 'risk'];
                                              $val = $extractFromFormResponses($sessionId, $weightKeys, $formTypes);
                                              if ($val !== null && $val !== '') return e($val);
                                          }
                                          return '—';
                                      };

                                      $heightFromOrder = function (Order $order) use ($extractMeasurementFromAnswers, $extractFromFormResponses) {
                                          $meta = is_array($order->meta)
                                              ? $order->meta
                                              : (json_decode($order->meta ?? '[]', true) ?: []);
                                          // Direct meta keys (unchanged, always first priority)
                                          $directValues = [
                                              data_get($meta, 'height'),
                                              data_get($meta, 'height_cm'),
                                              data_get($meta, 'current_height'),
                                              data_get($meta, 'body_height'),
                                              data_get($meta, 'patient_height'),
                                              data_get($meta, 'raf.height'),
                                              data_get($meta, 'raf.height_cm'),
                                              data_get($meta, 'riskAssessment.height'),
                                              data_get($meta, 'riskAssessment.height_cm'),
                                          ];
                                          foreach ($directValues as $value) {
                                              if ($value === null) continue;
                                              $output = trim((string)$value);
                                              if ($output !== '') return e($output);
                                          }
                                          // QA fallback (unchanged)
                                          $questions = data_get($meta, 'formsQA.raf.qa');
                                          if (is_array($questions)) {
                                              foreach ($questions as $row) {
                                                  $key = strtolower(trim((string) ($row['key'] ?? '')));
                                                  $question = strtolower(trim((string) ($row['question'] ?? '')));
                                                  $isHeight = (str_contains($key, 'height') || str_contains($question, 'height'))
                                                      && ! str_contains($key, 'target')
                                                      && ! str_contains($key, 'goal')
                                                      && ! str_contains($question, 'target')
                                                      && ! str_contains($question, 'goal');
                                                  if (! $isHeight) continue;
                                                  $answer = $row['answer'] ?? $row['raw'] ?? null;
                                                  if ($answer === null) continue;
                                                  $output = is_array($answer)
                                                      ? trim(implode(', ', array_filter(array_map('strval', $answer))))
                                                      : trim((string) $answer);
                                                  if ($output !== '') return e($output);
                                              }
                                          }
                                          // Begin new: consultation session fallback
                                          $orderMeta = $meta;
                                          $sessionId = data_get($orderMeta, 'consultation_session_id');
                                          if ($sessionId) {
                                              $session = \App\Models\ConsultationSession::find($sessionId);
                                              if ($session && $session->meta) {
                                                  $sessionMeta = is_array($session->meta) ? $session->meta : (json_decode($session->meta ?? '[]', true) ?: []);
                                                  $answerContainers = [
                                                      data_get($sessionMeta, 'forms.reorder.answers'),
                                                      data_get($sessionMeta, 'formsQA.reorder'),
                                                      data_get($sessionMeta, 'forms_qa.reorder'),
                                                      data_get($sessionMeta, 'forms.risk_assessment.answers'),
                                                      data_get($sessionMeta, 'forms.risk-assessment.answers'),
                                                      data_get($sessionMeta, 'forms.raf.answers'),
                                                      data_get($sessionMeta, 'formsQA.risk_assessment'),
                                                      data_get($sessionMeta, 'formsQA.risk-assessment'),
                                                      data_get($sessionMeta, 'formsQA.raf'),
                                                      data_get($sessionMeta, 'forms_qa.risk_assessment'),
                                                      data_get($sessionMeta, 'forms_qa.risk-assessment'),
                                                      data_get($sessionMeta, 'forms_qa.raf'),
                                                  ];
                                                  $heightKeys = ['height', 'height_cm', 'current_height', 'heightcm', 'body_height', 'your_height'];
                                                  foreach ($answerContainers as $answers) {
                                                      $val = $extractMeasurementFromAnswers($answers, $heightKeys);
                                                      if ($val !== null && $val !== '') return e($val);
                                                  }
                                              }
                                              // Final fallback: ConsultationFormResponse
                                              $heightKeys = ['height', 'height_cm', 'current_height', 'heightcm', 'body_height', 'your_height'];
                                              $formTypes = ['reorder', 'raf', 'risk_assessment', 'risk-assessment', 'risk'];
                                              $val = $extractFromFormResponses($sessionId, $heightKeys, $formTypes);
                                              if ($val !== null && $val !== '') return e($val);
                                          }
                                          return '—';
                                      };

                                      $bmiFromOrder = function (Order $order) use ($extractMeasurementFromAnswers, $extractFromFormResponses) {
                                          $meta = is_array($order->meta)
                                              ? $order->meta
                                              : (json_decode($order->meta ?? '[]', true) ?: []);
                                          // Direct meta keys (unchanged, always first priority)
                                          $directValues = [
                                              data_get($meta, 'bmi'),
                                              data_get($meta, 'BMI'),
                                              data_get($meta, 'body_mass_index'),
                                              data_get($meta, 'body-mass-index'),
                                              data_get($meta, 'current_bmi'),
                                              data_get($meta, 'patient_bmi'),
                                              data_get($meta, 'raf.bmi'),
                                              data_get($meta, 'riskAssessment.bmi'),
                                          ];
                                          foreach ($directValues as $value) {
                                              if ($value === null) continue;
                                              $output = trim((string)$value);
                                              if ($output !== '') return e($output);
                                          }
                                          // QA fallback (unchanged)
                                          $questions = data_get($meta, 'formsQA.raf.qa');
                                          if (is_array($questions)) {
                                              foreach ($questions as $row) {
                                                  $key = strtolower(trim((string) ($row['key'] ?? '')));
                                                  $question = strtolower(trim((string) ($row['question'] ?? '')));
                                                  $isBmi = $key === 'bmi'
                                                      || str_contains($key, 'body_mass_index')
                                                      || str_contains($key, 'body-mass-index')
                                                      || preg_match('/\bbmi\b/', $question)
                                                      || str_contains($question, 'body mass index');
                                                  if (! $isBmi) continue;
                                                  $answer = $row['answer'] ?? $row['raw'] ?? null;
                                                  if ($answer === null) continue;
                                                  $output = is_array($answer)
                                                      ? trim(implode(', ', array_filter(array_map('strval', $answer))))
                                                      : trim((string) $answer);
                                                  if ($output !== '') return e($output);
                                              }
                                          }
                                          // Begin new: consultation session fallback
                                          $orderMeta = $meta;
                                          $sessionId = data_get($orderMeta, 'consultation_session_id');
                                          if ($sessionId) {
                                              $session = \App\Models\ConsultationSession::find($sessionId);
                                              if ($session && $session->meta) {
                                                  $sessionMeta = is_array($session->meta) ? $session->meta : (json_decode($session->meta ?? '[]', true) ?: []);
                                                  $answerContainers = [
                                                      data_get($sessionMeta, 'forms.reorder.answers'),
                                                      data_get($sessionMeta, 'formsQA.reorder'),
                                                      data_get($sessionMeta, 'forms_qa.reorder'),
                                                      data_get($sessionMeta, 'forms.risk_assessment.answers'),
                                                      data_get($sessionMeta, 'forms.risk-assessment.answers'),
                                                      data_get($sessionMeta, 'forms.raf.answers'),
                                                      data_get($sessionMeta, 'formsQA.risk_assessment'),
                                                      data_get($sessionMeta, 'formsQA.risk-assessment'),
                                                      data_get($sessionMeta, 'formsQA.raf'),
                                                      data_get($sessionMeta, 'forms_qa.risk_assessment'),
                                                      data_get($sessionMeta, 'forms_qa.risk-assessment'),
                                                      data_get($sessionMeta, 'forms_qa.raf'),
                                                  ];
                                                  $bmiKeys = ['bmi', 'body_mass_index'];
                                                  foreach ($answerContainers as $answers) {
                                                      $val = $extractMeasurementFromAnswers($answers, $bmiKeys);
                                                      if ($val !== null && $val !== '') return e($val);
                                                  }
                                              }
                                              // Final fallback: ConsultationFormResponse
                                              $bmiKeys = ['bmi', 'body_mass_index'];
                                              $formTypes = ['reorder', 'raf', 'risk_assessment', 'risk-assessment', 'risk'];
                                              $val = $extractFromFormResponses($sessionId, $bmiKeys, $formTypes);
                                              if ($val !== null && $val !== '') return e($val);
                                          }
                                          return '—';
                                      };

                                      $rows = $orders->map(function (Order $order) use ($money, $itemsSummary, $weightFromOrder, $bmiFromOrder, $heightFromOrder): array {
                                          return [
                                              'ref' => e($order->reference ?? ('#' . $order->id)),
                                              'created' => optional($order->created_at)->format('d-m-Y H:i') ?? '',
                                              'items' => $itemsSummary($order),
                                              'weight' => $weightFromOrder($order),
                                              // Optionally add height here if your table supports it:
                                              // 'height' => $heightFromOrder($order),
                                              'bmi' => $bmiFromOrder($order),
                                              'total' => $money($order),
                                              'url' => "/admin/orders/completed-orders/{$order->id}/details",
                                          ];
                                      })->values()->all();

                                      return new HtmlString(
                                          view('filament.partials.order-history-table', ['rows' => $rows])->render()
                                      );
                                  })
                                  ->columnSpan(12),
                                    
                                Hidden::make('user_id'),



                               

                                TextInput::make('first_name')
                                    ->label('First name')
                                    ->required()
                                    ->columnSpan(6),

                                TextInput::make('last_name')
                                    ->label('Last name')
                                    ->required()
                                    ->columnSpan(6),

                                DatePicker::make('dob')
                                    ->label('Date of birth')
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->columnSpan(6),

                                Select::make('gender')
                                    ->label('Gender')
                                    ->placeholder('Select')
                                    ->options([
                                        'male' => 'Male',
                                        'female' => 'Female',
                                        'other' => 'Other',
                                        'prefer_not_to_say' => 'Prefer not to say',
                                    ])
                                    ->columnSpan(6),

                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->columnSpan(6),

                                TextInput::make('phone')
                                    ->label('Phone')
                                    ->tel()
                                    ->columnSpan(6),

                                TextInput::make('address_line_1')
                                    ->label('Address line 1')
                                    ->columnSpan(12),

                                TextInput::make('address_line_2')
                                    ->label('Address line 2')
                                    ->columnSpan(12),

                                TextInput::make('city')
                                    ->label('City')
                                    ->columnSpan(6),

                                TextInput::make('county')
                                    ->label('County')
                                    ->columnSpan(6),

                                TextInput::make('postcode')
                                    ->label('Postcode')
                                    ->columnSpan(6),

                                TextInput::make('country')
                                    ->label('Country')
                                    ->default('United Kingdom')
                                    ->columnSpan(6),
                            ]),
                    ]),


                Section::make('Appointment')
                  ->columnSpanFull()
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                DateTimePicker::make('appointment_at')
                                    ->label('Appointment date/time')
                                    ->native(false)
                                    ->seconds(false)
                                    ->columnSpan(12),
                            ]),
                    ]),

                Section::make('Order Details')
                   ->columnSpanFull()
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                Select::make('service_id')
                                    ->label('Search service')
                                    ->placeholder('Search by service name')
                                    ->searchable()
                                    ->getSearchResultsUsing(function (string $search): array {
                                        $search = trim($search);

                                        if (mb_strlen($search) < 2) {
                                            return [];
                                        }

                                        if (! SchemaFacade::hasTable('services')) {
                                            return [];
                                        }

                                        return DB::table('services')
                                            ->when(SchemaFacade::hasColumn('services', 'name'), function ($query) use ($search) {
                                                $query->where('name', 'like', "%{$search}%");
                                            })
                                            ->limit(25)
                                            ->get()
                                            ->mapWithKeys(function ($service) {
                                                $name = $service->name ?? ('Service #' . $service->id);
                                                return [$service->id => $name];
                                            })
                                            ->toArray();
                                    })
                                    ->getOptionLabelUsing(function ($value): ?string {
                                        if (! $value || ! SchemaFacade::hasTable('services')) {
                                            return null;
                                        }

                                        $service = DB::table('services')->where('id', $value)->first();

                                        return $service->name ?? null;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        if (! $state || ! SchemaFacade::hasTable('services')) {
                                            $set('service_name', null);
                                            $set('service_slug', null);
                                            return;
                                        }

                                        $service = DB::table('services')->where('id', $state)->first();
                                        if (! $service) {
                                            $set('service_name', null);
                                            $set('service_slug', null);
                                            return;
                                        }

                                        $set('service_name', $service->name ?? null);
                                        $set('service_slug', isset($service->slug) && is_string($service->slug) && trim($service->slug) !== ''
                                            ? trim((string) $service->slug)
                                            : Str::slug((string) ($service->name ?? '')));
                                        $set('items', [[
                                            'name' => null,
                                            'variation' => null,
                                            'qty' => 1,
                                            'unit_price' => null,
                                        ]]);
                                    })
                                    ->columnSpan(6),

                                TextInput::make('service_name')
                                
                                    ->label('Service name')
                                    ->readOnly()
                                    ->columnSpan(6),
                                Hidden::make('service_slug'),
                            ]),
                        Repeater::make('items')
                            ->label('')
                            ->defaultItems(1)
                            ->addActionLabel('Add item')
                            ->reorderable(false)
                            ->collapsible(false)
                            ->schema([
                                Grid::make(12)
                                    ->schema([
                                        Select::make('name')
                                            ->label('Item name')
                                            ->placeholder('Choose item')
                                            ->options(function (callable $get): array {
                                                $serviceId = $get('../../service_id') ?: $get('service_id');

                                                if (! $serviceId) {
                                                    return [];
                                                }

                                                if (SchemaFacade::hasTable('products')) {
                                                    $nameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    if ($nameColumn) {
                                                        if (SchemaFacade::hasTable('service_product')) {
                                                            return DB::table('products')
                                                                ->join('service_product', 'products.id', '=', 'service_product.product_id')
                                                                ->where('service_product.service_id', $serviceId)
                                                                ->orderBy("products.{$nameColumn}")
                                                                ->select(['products.id', DB::raw("products.{$nameColumn} as product_name")])
                                                                ->limit(500)
                                                                ->get()
                                                                ->mapWithKeys(fn ($row) => [(string) $row->product_name => (string) $row->product_name])
                                                                ->toArray();
                                                        }

                                                        if (SchemaFacade::hasTable('product_service')) {
                                                            return DB::table('products')
                                                                ->join('product_service', 'products.id', '=', 'product_service.product_id')
                                                                ->where('product_service.service_id', $serviceId)
                                                                ->orderBy("products.{$nameColumn}")
                                                                ->select(['products.id', DB::raw("products.{$nameColumn} as product_name")])
                                                                ->limit(500)
                                                                ->get()
                                                                ->mapWithKeys(fn ($row) => [(string) $row->product_name => (string) $row->product_name])
                                                                ->toArray();
                                                        }
                                                    }
                                                }

                                                if (SchemaFacade::hasTable('service_medicines')) {
                                                    $nameColumn = SchemaFacade::hasColumn('service_medicines', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('service_medicines', 'title') ? 'title' : null);

                                                    if ($nameColumn && SchemaFacade::hasColumn('service_medicines', 'service_id')) {
                                                        return DB::table('service_medicines')
                                                            ->where('service_id', $serviceId)
                                                            ->orderBy($nameColumn)
                                                            ->limit(500)
                                                            ->get()
                                                            ->mapWithKeys(function ($row) use ($nameColumn) {
                                                                $name = (string) ($row->{$nameColumn} ?? ('Item #' . $row->id));
                                                                return [$name => $name];
                                                            })
                                                            ->toArray();
                                                    }
                                                }

                                                return [];
                                            })
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->disabled(fn (callable $get): bool => blank($get('../../service_id')) && blank($get('service_id')))
                                            ->helperText(fn (callable $get): ?string => blank($get('../../service_id')) && blank($get('service_id')) ? 'Choose service first' : null)
                                            ->live()
                                            ->afterStateUpdated(function ($state, callable $set): void {
                                                $set('variation', null);
                                                $set('unit_price', null);
                                            })
                                            ->columnSpan(6),

                                        Select::make('variation')
                                            ->label('Variation')
                                            ->placeholder('Choose variation')
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->disabled(fn (callable $get): bool => blank($get('name')))
                                            ->helperText(fn (callable $get): ?string => blank($get('name')) ? 'Choose item name first' : null)
                                            ->options(function (callable $get): array {
                                                $itemName = trim((string) ($get('name') ?? ''));

                                                if ($itemName === '') {
                                                    return [];
                                                }

                                                $options = [];

                                                if (SchemaFacade::hasTable('product_variations') && SchemaFacade::hasTable('products')) {
                                                    $productNameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    $variationLabelColumn = SchemaFacade::hasColumn('product_variations', 'title')
                                                        ? 'title'
                                                        : (SchemaFacade::hasColumn('product_variations', 'label')
                                                            ? 'label'
                                                            : (SchemaFacade::hasColumn('product_variations', 'name')
                                                                ? 'name'
                                                                : (SchemaFacade::hasColumn('product_variations', 'variation')
                                                                    ? 'variation'
                                                                    : (SchemaFacade::hasColumn('product_variations', 'strength') ? 'strength' : null))));

                                                    $productIdColumn = SchemaFacade::hasColumn('product_variations', 'product_id')
                                                        ? 'product_id'
                                                        : null;

                                                    if ($productNameColumn && $variationLabelColumn && $productIdColumn) {
                                                        $rows = DB::table('product_variations')
                                                            ->join('products', "product_variations.{$productIdColumn}", '=', 'products.id')
                                                            ->where("products.{$productNameColumn}", $itemName)
                                                            ->select([
                                                                "product_variations.{$variationLabelColumn} as variation_label",
                                                                SchemaFacade::hasColumn('product_variations', 'price') ? 'product_variations.price as variation_price' : DB::raw('null as variation_price'),
                                                                SchemaFacade::hasColumn('product_variations', 'price_minor') ? 'product_variations.price_minor as variation_price_minor' : DB::raw('null as variation_price_minor'),
                                                            ])
                                                            ->orderBy("product_variations.{$variationLabelColumn}")
                                                            ->limit(100)
                                                            ->get();

                                                        foreach ($rows as $row) {
                                                            $label = trim((string) ($row->variation_label ?? ''));
                                                            if ($label !== '') {
                                                                $options[$label] = $label;
                                                            }
                                                        }
                                                    }
                                                }

                                                if (empty($options) && SchemaFacade::hasTable('products')) {
                                                    $nameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    if ($nameColumn) {
                                                        $product = DB::table('products')->where($nameColumn, $itemName)->first();

                                                        if ($product) {
                                                            foreach (['title', 'variation', 'variations', 'strength', 'dose', 'option', 'options', 'variant', 'variants'] as $key) {
                                                                $value = $product->{$key} ?? null;

                                                                if (is_string($value) && trim($value) !== '') {
                                                                    $decoded = json_decode($value, true);

                                                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                                        foreach ($decoded as $entry) {
                                                                            if (is_array($entry)) {
                                                                                $label = trim((string) ($entry['title'] ?? $entry['label'] ?? $entry['name'] ?? $entry['value'] ?? ''));
                                                                            } else {
                                                                                $label = trim((string) $entry);
                                                                            }

                                                                            if ($label !== '') {
                                                                                $options[$label] = $label;
                                                                            }
                                                                        }
                                                                    } else {
                                                                        $label = trim($value);
                                                                        if ($label !== '') {
                                                                            $options[$label] = $label;
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                return $options;
                                            })
                                            ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                                                $itemName = trim((string) ($get('name') ?? ''));
                                                $variation = trim((string) ($state ?? ''));

                                                if ($itemName === '' || $variation === '') {
                                                    return;
                                                }

                                                if (SchemaFacade::hasTable('product_variations') && SchemaFacade::hasTable('products')) {
                                                    $productNameColumn = SchemaFacade::hasColumn('products', 'name')
                                                        ? 'name'
                                                        : (SchemaFacade::hasColumn('products', 'title') ? 'title' : null);

                                                    $variationLabelColumn = SchemaFacade::hasColumn('product_variations', 'title')
                                                        ? 'title'
                                                        : (SchemaFacade::hasColumn('product_variations', 'label')
                                                            ? 'label'
                                                            : (SchemaFacade::hasColumn('product_variations', 'name')
                                                                ? 'name'
                                                                : (SchemaFacade::hasColumn('product_variations', 'variation')
                                                                    ? 'variation'
                                                                    : (SchemaFacade::hasColumn('product_variations', 'strength') ? 'strength' : null))));

                                                    $productIdColumn = SchemaFacade::hasColumn('product_variations', 'product_id')
                                                        ? 'product_id'
                                                        : null;

                                                    if ($productNameColumn && $variationLabelColumn && $productIdColumn) {
                                                        $row = DB::table('product_variations')
                                                            ->join('products', "product_variations.{$productIdColumn}", '=', 'products.id')
                                                            ->where("products.{$productNameColumn}", $itemName)
                                                            ->where("product_variations.{$variationLabelColumn}", $variation)
                                                            ->select([
                                                                SchemaFacade::hasColumn('product_variations', 'price') ? 'product_variations.price as variation_price' : DB::raw('null as variation_price'),
                                                                SchemaFacade::hasColumn('product_variations', 'price_minor') ? 'product_variations.price_minor as variation_price_minor' : DB::raw('null as variation_price_minor'),
                                                            ])
                                                            ->first();

                                                        if ($row) {
                                                            if (isset($row->variation_price) && is_numeric($row->variation_price)) {
                                                                $set('unit_price', (float) $row->variation_price);
                                                                return;
                                                            }

                                                            if (isset($row->variation_price_minor) && is_numeric($row->variation_price_minor)) {
                                                                $set('unit_price', ((float) $row->variation_price_minor) / 100);
                                                                return;
                                                            }
                                                        }
                                                    }
                                                }

                                                $set('unit_price', null);
                                            })
                                            ->columnSpan(6),

                                        TextInput::make('qty')
                                            ->label('Qty')
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(1)
                                            ->live()
                                            ->columnSpan(6),

                                        TextInput::make('unit_price')
                                            ->label('Unit price (£)')
                                            ->numeric()
                                            ->prefix('£')
                                            ->minValue(0)
                                            ->live()
                                            ->columnSpan(6),

                                        Placeholder::make('line_total')
                                            ->label(' ')
                                            ->content(function ($get) {
                                                $qty = (float) ($get('qty') ?: 0);
                                                $unit = (float) ($get('unit_price') ?: 0);
                                                return 'Line total: £' . number_format($qty * $unit, 2);
                                            })
                                            ->columnSpan(12),
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
