<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Order;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return AppointmentResource::form($schema);
    }

    protected function appointmentHasColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('appointments', $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function firstAppointmentColumn(array $columns): ?string
    {
        foreach ($columns as $column) {
            if ($this->appointmentHasColumn($column)) {
                return $column;
            }
        }

        return null;
    }

    protected function normaliseItemLabel(mixed $value): string
    {
        if (is_array($value)) {
            $value = Arr::get($value, 'name')
                ?? Arr::get($value, 'label')
                ?? Arr::get($value, 'title')
                ?? Arr::get($value, 'product_name')
                ?? Arr::get($value, 'medicine_name')
                ?? Arr::get($value, 'item')
                ?? Arr::get($value, 'value')
                ?? '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function getProductNameById(mixed $productId): string
    {
        if (empty($productId) || ! Schema::hasTable('products')) {
            return '';
        }

        $nameColumn = Schema::hasColumn('products', 'name')
            ? 'name'
            : (Schema::hasColumn('products', 'title') ? 'title' : null);

        if (! $nameColumn) {
            return '';
        }

        try {
            return trim((string) (DB::table('products')->where('id', $productId)->value($nameColumn) ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function getServiceMedicineNameById(mixed $medicineId): string
    {
        if (empty($medicineId) || ! Schema::hasTable('service_medicines')) {
            return '';
        }

        $nameColumn = Schema::hasColumn('service_medicines', 'name')
            ? 'name'
            : (Schema::hasColumn('service_medicines', 'title') ? 'title' : null);

        if (! $nameColumn) {
            return '';
        }

        try {
            return trim((string) (DB::table('service_medicines')->where('id', $medicineId)->value($nameColumn) ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function getVariationLabelAndPrice(mixed $variationId): array
    {
        if (empty($variationId) || ! Schema::hasTable('product_variations')) {
            return ['', null];
        }

        $labelColumn = null;
        foreach (['title', 'label', 'name', 'variation', 'strength'] as $column) {
            if (Schema::hasColumn('product_variations', $column)) {
                $labelColumn = $column;
                break;
            }
        }

        try {
            $row = DB::table('product_variations')->where('id', $variationId)->first();
        } catch (\Throwable $e) {
            $row = null;
        }

        if (! $row) {
            return ['', null];
        }

        $label = $labelColumn ? trim((string) ($row->{$labelColumn} ?? '')) : '';
        $price = null;

        if (isset($row->price) && is_numeric($row->price)) {
            $price = (float) $row->price;
        } elseif (isset($row->price_minor) && is_numeric($row->price_minor)) {
            $price = ((float) $row->price_minor) / 100;
        }

        return [$label, $price];
    }

    protected function firstOrderItemFromMeta(?Order $order): array
    {
        if (! $order) {
            return ['', '', null];
        }

        $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

        foreach (['items', 'lines', 'products', 'cart'] as $path) {
            $items = Arr::get($meta, $path);
            if (! is_array($items) || empty($items)) {
                continue;
            }

            $first = array_values($items)[0] ?? null;
            if (! is_array($first)) {
                continue;
            }

            $name = $this->normaliseItemLabel($first);
            if ($name === '') {
                $name = $this->normaliseItemLabel(Arr::get($first, 'product'));
            }

            $variation = $this->normaliseItemLabel(
                Arr::get($first, 'variation')
                ?? Arr::get($first, 'variant')
                ?? Arr::get($first, 'option')
                ?? Arr::get($first, 'strength')
                ?? Arr::get($first, 'dose')
                ?? Arr::get($first, 'variations')
            );

            $unitPrice = Arr::get($first, 'unit_price')
                ?? Arr::get($first, 'unitPrice')
                ?? Arr::get($first, 'price')
                ?? null;

            if ($unitPrice === null && is_numeric(Arr::get($first, 'unitMinor'))) {
                $unitPrice = ((float) Arr::get($first, 'unitMinor')) / 100;
            }

            return [$name, $variation, is_numeric($unitPrice) ? (float) $unitPrice : null];
        }

        $selectedProduct = Arr::get($meta, 'selectedProduct');
        if (is_array($selectedProduct)) {
            return [
                $this->normaliseItemLabel($selectedProduct),
                $this->normaliseItemLabel(Arr::get($selectedProduct, 'variation') ?? Arr::get($selectedProduct, 'strength')),
                is_numeric(Arr::get($selectedProduct, 'price')) ? (float) Arr::get($selectedProduct, 'price') : null,
            ];
        }

        return ['', '', null];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Default new appointments to waiting when the column exists
        if (Schema::hasColumn('appointments', 'status') && empty($data['status'])) {
            $data['status'] = 'waiting';
        }

        // Generate a stable reference when admin creates without one
        $ref = '';
        if (isset($data['order_reference']) && is_string($data['order_reference'])) {
            $ref = trim($data['order_reference']);
        }
        if ($ref === '' && isset($data['reference']) && is_string($data['reference'])) {
            $ref = trim($data['reference']);
        }
        if ($ref === '') {
            $ref = method_exists(AppointmentResource::class, 'generatePcaoRef')
                ? AppointmentResource::generatePcaoRef()
                : ('PCAO-' . strtoupper(bin2hex(random_bytes(4))));
        }

        if (Schema::hasColumn('appointments', 'reference') && (empty($data['reference']) || ! is_string($data['reference']))) {
            $data['reference'] = $ref;
        }

        if (Schema::hasColumn('appointments', 'order_reference') && (empty($data['order_reference']) || ! is_string($data['order_reference']))) {
            $data['order_reference'] = $ref;
        }

        // Mirror the Walk-In form behaviour: service_id is the source of truth, while
        // service_name/service_slug are filled for the appointment list, emails and meta displays.
        try {
            $serviceId = $data['service_id'] ?? null;
            if ($serviceId && Schema::hasTable('services')) {
                $service = DB::table('services')->where('id', $serviceId)->first();

                if ($service) {
                    $serviceName = trim((string) ($service->name ?? ''));
                    $serviceSlug = trim((string) ($service->slug ?? ''));
                    if ($serviceSlug === '' && $serviceName !== '') {
                        $serviceSlug = Str::slug($serviceName);
                    }

                    foreach (['service_name', 'service'] as $column) {
                        if ($serviceName !== '' && $this->appointmentHasColumn($column) && empty($data[$column])) {
                            $data[$column] = $serviceName;
                        }
                    }

                    if ($serviceSlug !== '' && $this->appointmentHasColumn('service_slug') && empty($data['service_slug'])) {
                        $data['service_slug'] = $serviceSlug;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Do not block appointment creation because service mirroring failed.
        }

        $order = null;
        try {
            if (! empty($data['order_id'])) {
                $order = Order::find($data['order_id']);
            }

            if (! $order && $ref !== '') {
                $order = Order::query()->where('reference', $ref)->orderByDesc('id')->first();
            }
        } catch (\Throwable $e) {
            $order = null;
        }

        [$orderItemName, $orderItemVariation, $orderUnitPrice] = $this->firstOrderItemFromMeta($order);

        $itemName = $this->normaliseItemLabel(
            $data['item']
            ?? $data['item_name']
            ?? $data['order_item']
            ?? $data['product_name']
            ?? $data['medicine_name']
            ?? $data['name']
            ?? ''
        );

        if ($itemName === '') {
            $itemName = $this->getProductNameById($data['product_id'] ?? null);
        }

        if ($itemName === '') {
            $itemName = $this->getServiceMedicineNameById($data['service_medicine_id'] ?? null);
        }

        if ($itemName === '') {
            $itemName = $orderItemName;
        }

        [$variationFromId, $priceFromVariation] = $this->getVariationLabelAndPrice(
            $data['variation_id'] ?? $data['product_variation_id'] ?? null
        );

        $variation = $this->normaliseItemLabel(
            $data['variation']
            ?? $data['variant']
            ?? $data['strength']
            ?? $data['dose']
            ?? ''
        );

        if ($variation === '') {
            $variation = $variationFromId ?: $orderItemVariation;
        }

        $unitPrice = $data['unit_price'] ?? $data['price'] ?? $priceFromVariation ?? $orderUnitPrice ?? null;

        if ($itemName !== '') {
            foreach (['order_item', 'item', 'item_name', 'product_name', 'medicine_name'] as $column) {
                if ($this->appointmentHasColumn($column) && empty($data[$column])) {
                    $data[$column] = $itemName;
                }
            }
        }

        if ($variation !== '') {
            foreach (['variation', 'variant', 'strength', 'dose'] as $column) {
                if ($this->appointmentHasColumn($column) && empty($data[$column])) {
                    $data[$column] = $variation;
                }
            }
        }

        if ($unitPrice !== null && is_numeric($unitPrice)) {
    foreach (['unit_price', 'price'] as $column) {
        if ($this->appointmentHasColumn($column) && empty($data[$column])) {
            $data[$column] = (float) $unitPrice;
        }
    }
}

// Admin-created appointments intentionally allow manual booking between 09:00 and 18:00,
// regardless of the service schedule configured for patient-facing bookings.

return $data;
    }

    protected function afterCreate(): void
    {
        $state = method_exists($this->form, 'getRawState')
            ? $this->form->getRawState()
            : $this->form->getState();

        $isOnline = (bool) (data_get($state, 'online_consultation') ?? false);

        \Log::info('appointment.created.online_toggle', [
            'appointment' => $this->record?->id ?? null,
            'online_consultation' => $isOnline,
            'state_has_key' => array_key_exists('online_consultation', is_array($state) ? $state : []),
        ]);

        $record = $this->record;
        if (! $record) return;
        AppointmentResource::cancelOtherActiveAppointmentsFor($record);

        // Prefer form state / raw DB value to avoid accessors
        $email = '';
        try {
            $email = data_get($state, 'email')
                ?? data_get($state, 'patient.email')
                ?? data_get($state, 'patient_email')
                ?? data_get($state, 'customer.email')
                ?? '';
            $email = is_string($email) ? trim((string) $email) : '';
        } catch (\Throwable $e) {
            $email = '';
        }

        if ($email === '') {
            $rawEmail = $record->getRawOriginal('email');
            $email = is_string($rawEmail) ? trim((string) $rawEmail) : '';
        }

        $order = null;
        $pending = null;

        try {
            if (Schema::hasColumn('appointments', 'order_id') && ! empty($record->order_id)) {
                $order = Order::find($record->order_id);
            }
        } catch (\Throwable $e) {}

        try {
            if (! $order) {
                $ref = $record->getRawOriginal('order_reference');
                $ref = is_string($ref) ? trim((string) $ref) : '';
                if ($ref === '') {
                    $ref2 = $record->getRawOriginal('reference');
                    $ref = is_string($ref2) ? trim((string) $ref2) : '';
                }

                if ($ref !== '') {
                    $order = Order::query()->where('reference', $ref)->orderByDesc('id')->first();
                }
            }
        } catch (\Throwable $e) {}

        try {
            if (! $order && method_exists(AppointmentResource::class, 'findRelatedOrder')) {
                $order = AppointmentResource::findRelatedOrder($record);
            }
        } catch (\Throwable $e) {}

        // If email was not entered on the appointment, try to derive it from the related order
        if ($email === '' && $order) {
            try {
                if ($order) {
                    // Prefer structured meta where available
                    $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                    $email = Arr::get($meta, 'patient.email')
                        ?? Arr::get($meta, 'customer.email')
                        ?? ($order->email ?? null)
                        ?? optional($order->user)->email;
                }

                $email = is_string($email) ? trim((string) $email) : '';
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($email === '') {
            Notification::make()
                ->warning()
                ->title('Appointment created but no email was found')
                ->body('Please enter the patient email on the appointment form (or ensure the appointment is linked to an order).')
                ->send();
            return;
        }

        \Log::info('appointment.created.email_attempt', [
            'appointment' => $record->id ?? null,
            'email' => $email,
            'order' => $order?->id ?? null,
        ]);

        $joinUrl = null;
        $startUrl = null;

        if ($isOnline && class_exists(\App\Services\ZoomMeetingService::class)) {
            try {
                $zoom = app(\App\Services\ZoomMeetingService::class);

                if (method_exists($zoom, 'createForAppointmentOnly')) {
                    $zoomInfo = $zoom->createForAppointmentOnly($record);
                } else {
                    $zoomInfo = $zoom->createForAppointment($record, $order);
                }

                if (is_array($zoomInfo)) {
                    if (! empty($zoomInfo['join_url'])) {
                        $joinUrl = (string) $zoomInfo['join_url'];
                    }

                    if (! empty($zoomInfo['start_url'])) {
                        $startUrl = (string) $zoomInfo['start_url'];
                    }

                    // If we found an order but the appointment is not linked, link it now so the table can read meta.
                    try {
                        if ($order && Schema::hasColumn('appointments', 'order_id') && empty($record->order_id)) {
                            $record->order_id = $order->id;
                        }

                        if ($order && Schema::hasColumn('appointments', 'order_reference')) {
                            $refNow = is_string($record->order_reference ?? null) ? trim((string) $record->order_reference) : '';
                            if ($refNow === '' && is_string($order->reference ?? null) && trim((string) $order->reference) !== '') {
                                $record->order_reference = trim((string) $order->reference);
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    // Save the host start URL onto the appointment if a compatible column exists.
                    if ($startUrl) {
                        foreach (['zoom_start_url', 'zoom_host_url', 'host_zoom_url', 'zoom_url'] as $col) {
                            if (Schema::hasColumn('appointments', $col)) {
                                $record->{$col} = $startUrl;
                                break;
                            }
                        }
                    }

                    // Save the join URL onto the appointment if a compatible column exists.
                    if ($joinUrl) {
                        foreach (['zoom_join_url', 'zoom_patient_url', 'join_zoom_url'] as $col) {
                            if (Schema::hasColumn('appointments', $col)) {
                                $record->{$col} = $joinUrl;
                                break;
                            }
                        }
                    }

                    // Always persist Zoom data on the appointment itself so the table can render Host link
                    try {
                        if (Schema::hasColumn('appointments', 'meta')) {
                            $ameta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                            $ameta['zoom'] = array_replace($ameta['zoom'] ?? [], [
                                'meeting_id' => $zoomInfo['id'] ?? null,
                                'join_url'   => $joinUrl,
                                'start_url'  => $startUrl,
                            ]);
                            $record->meta = $ameta;
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    // Persist appointment changes.
                    if (($startUrl || $joinUrl) && method_exists($record, 'save')) {
                        try {
                            $record->save();
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }

                    // Persist into linked order meta too, so the Start Zoom column can read from order meta.
                    if ($order && ($startUrl || $joinUrl)) {
                        try {
                            $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                            $meta['zoom'] = array_replace($meta['zoom'] ?? [], [
                                'meeting_id' => $zoomInfo['id'] ?? null,
                                'join_url'   => $joinUrl,
                                'start_url'  => $startUrl,
                            ]);
                            $order->meta = $meta;
                            $order->save();
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }

                    \Log::info('appointment.zoom.created_from_admin', [
                        'appointment' => $record->id ?? null,
                        'order' => $order?->id ?? null,
                        'has_join_url' => (bool) $joinUrl,
                        'has_start_url' => (bool) $startUrl,
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('appointment.zoom.create_failed', [
                    'appointment' => $record->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $when = null;
        try {
            $startAtRaw = $record->getRawOriginal('start_at');
            $when = $startAtRaw
                ? Carbon::parse($startAtRaw, 'UTC')->tz('Europe/London')->format('d M Y, H:i')
                : null;
        } catch (\Throwable $e) {}

       $service = '';
        $serviceSlug = '';

        try {
            $serviceId = data_get($state, 'service_id') ?: $record->getRawOriginal('service_id');

            if ($serviceId && Schema::hasTable('services')) {
                $serviceRow = DB::table('services')->where('id', $serviceId)->first();

                if ($serviceRow) {
                    foreach (['name', 'title', 'label'] as $column) {
                        if ($service === '' && isset($serviceRow->{$column})) {
                            $service = trim((string) $serviceRow->{$column});
                        }
                    }

                    if (isset($serviceRow->slug)) {
                        $serviceSlug = trim((string) $serviceRow->slug);
                    }
                }
            }

            if ($serviceSlug === '') {
                $ss = $record->getRawOriginal('service_slug');
                $serviceSlug = is_string($ss) ? trim((string) $ss) : '';
            }

            if ($service === '' && $serviceSlug !== '') {
                $service = Str::headline(str_replace('-', ' ', $serviceSlug));
            }
        } catch (\Throwable $e) {
            $service = '';
            $serviceSlug = '';
        }
        $serviceKey = Str::slug($serviceSlug !== '' ? $serviceSlug : $service);
        $isWeightManagementAppointment = in_array($serviceKey, ['weight-management', 'weight-loss', 'mounjaro', 'wegovy'], true);

        $subject = 'Your Pharmacy Express appointment confirmation';

        $safeService = e($service !== '' ? $service : 'your service');
        $safeWhen = e($when ?: 'To be confirmed');
        $safeJoinUrl = $joinUrl ? e($joinUrl) : '';

        $zoomHtml = ($isOnline && $joinUrl)
            ? '<tr>
                <td style="padding:0 34px 26px 34px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#123f40;">
                        <tr>
                            <td style="padding:22px 24px;">
                                <p style="margin:0 0 10px 0;font-family:Outfit,Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#10c7a4;font-weight:700;">Video consultation</p>
                                <p style="margin:0 0 14px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:rgba(255,255,255,.78);">Use the secure Zoom link below to join your appointment.</p>
                                <a href="' . $safeJoinUrl . '" style="display:inline-block;background:#10c7a4;color:#123f40;text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:800;padding:13px 18px;">Join Zoom call</a>
                                <p style="margin:14px 0 0 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:rgba(255,255,255,.62);word-break:break-all;">' . $safeJoinUrl . '</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>'
            : '';

        $scalesHtml = $isWeightManagementAppointment
            ? '<tr>
                <td style="padding:0 34px 26px 34px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef8f3;border:1px solid rgba(18,63,64,.14);">
                        <tr>
                            <td style="padding:20px 24px;">
                                <p style="margin:0 0 8px 0;font-family:Outfit,Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#123f40;font-weight:700;">Weight management appointment</p>
                                <p style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;"><strong>Please have weighing scales during the call.</strong></p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>'
            : '';

        $body = '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <title>' . e($subject) . '</title>
</head>
<body style="margin:0;padding:0;background:#f6f6f4;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f6f4;margin:0;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid rgba(18,63,64,.14);">
          <tr>
            <td style="background:#123f40;padding:34px 34px 30px 34px;border-bottom:4px solid #10c7a4;">
              <p style="margin:0 0 14px 0;font-family:Outfit,Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.20em;text-transform:uppercase;color:#10c7a4;font-weight:700;">Pharmacy Express</p>
              <h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:34px;line-height:38px;color:#ffffff;font-weight:800;letter-spacing:-.05em;">Appointment booked</h1>
              <p style="margin:14px 0 0 0;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:24px;color:rgba(255,255,255,.72);">Your appointment has been confirmed by our pharmacy team.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:34px 34px 10px 34px;">
              <p style="margin:0 0 18px 0;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:25px;color:#111827;">Hello,</p>
              <p style="margin:0 0 22px 0;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:25px;color:#111827;">Your Pharmacy Express appointment for <strong style="color:#123f40;">' . $safeService . '</strong> has been booked.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f6f4;border:1px solid rgba(18,63,64,.14);margin:0 0 24px 0;">
                <tr>
                  <td style="padding:22px 24px;">
                    <p style="margin:0 0 14px 0;font-family:Outfit,Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#123f40;font-weight:700;">Appointment details</p>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                      <tr>
                        <td style="width:30px;vertical-align:top;padding:3px 12px 12px 0;font-family:Outfit,Arial,Helvetica,sans-serif;color:#10a88a;font-size:15px;font-weight:800;">1</td>
                        <td style="padding:0 0 12px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;">Service: <strong>' . $safeService . '</strong></td>
                      </tr>
                      <tr>
                        <td style="width:30px;vertical-align:top;padding:3px 12px 12px 0;font-family:Outfit,Arial,Helvetica,sans-serif;color:#10a88a;font-size:15px;font-weight:800;">2</td>
                        <td style="padding:0 0 12px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;">When: <strong>' . $safeWhen . '</strong></td>
                      </tr>
                      <tr>
                        <td style="width:30px;vertical-align:top;padding:3px 12px 0 0;font-family:Outfit,Arial,Helvetica,sans-serif;color:#10a88a;font-size:15px;font-weight:800;">3</td>
                        <td style="padding:0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;">If this is an online consultation, please join using the Zoom link provided below.</td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          ' . $zoomHtml . '
          ' . $scalesHtml . '
          <tr>
            <td style="padding:0 34px 32px 34px;">
              <p style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#64748b;">If you need to rearrange, please contact the pharmacy.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

        try {
            $fromAddress = config('mail.from.address') ?: 'info@pharmacy-express.co.uk';
            $fromName = config('mail.from.name') ?: 'Pharmacy Express';

            Mail::html($body, function ($m) use ($email, $subject, $fromAddress, $fromName) {
                $m->from($fromAddress, $fromName)->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Appointment created but email could not be sent')
                ->body(substr($e->getMessage(), 0, 200))
                ->send();
            return;
        }

        Notification::make()
            ->success()
            ->title('Appointment created')
            ->body('Email sent to ' . $email . (($isOnline && $joinUrl) ? ' with Zoom link.' : '.') . (($isOnline && $startUrl) ? ' Zoom host link saved.' : ''))
            ->send();
    }
}