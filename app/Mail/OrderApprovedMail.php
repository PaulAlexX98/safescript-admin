<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderApprovedMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function build()
    {
        $meta = is_array($this->order->meta)
            ? $this->order->meta
            : (json_decode($this->order->meta ?? '[]', true) ?: []);

        $first = data_get($meta, 'firstName')
            ?? data_get($meta, 'first_name')
            ?? data_get($meta, 'patient.first_name')
            ?? data_get($meta, 'patient.firstName')
            ?? optional($this->order->patient)->first_name
            ?? optional($this->order->user)->first_name
            ?? '';

        $name = is_string($first) && trim($first) !== '' ? trim($first) : 'there';
        $ref = $this->order->reference ?? $this->order->getKey();

        $subject = 'Your Pharmacy Express order has been approved';
        $safeName = e($name);
        $safeRef = e((string) $ref);

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
              <h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:34px;line-height:38px;color:#ffffff;font-weight:800;letter-spacing:-.05em;">Order approved</h1>
              <p style="margin:14px 0 0 0;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:24px;color:rgba(255,255,255,.72);">Your request has been reviewed and approved by our pharmacy team.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:34px 34px 10px 34px;">
              <p style="margin:0 0 18px 0;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:25px;color:#111827;">Hi ' . $safeName . ',</p>
              <p style="margin:0 0 22px 0;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:25px;color:#111827;">Your Pharmacy Express request <strong style="color:#123f40;">' . $safeRef . '</strong> has been approved by our pharmacy team.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f6f4;border:1px solid rgba(18,63,64,.14);margin:0 0 24px 0;">
                <tr>
                  <td style="padding:22px 24px;">
                    <p style="margin:0 0 14px 0;font-family:Outfit,Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#123f40;font-weight:700;">What happens next</p>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                      <tr>
                        <td style="width:30px;vertical-align:top;padding:3px 12px 12px 0;font-family:Outfit,Arial,Helvetica,sans-serif;color:#10a88a;font-size:15px;font-weight:800;">1</td>
                        <td style="padding:0 0 12px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;">Your treatment or service is now approved and ready for the next step.</td>
                      </tr>
                      <tr>
                        <td style="width:30px;vertical-align:top;padding:3px 12px 12px 0;font-family:Outfit,Arial,Helvetica,sans-serif;color:#10a88a;font-size:15px;font-weight:800;">2</td>
                        <td style="padding:0 0 12px 0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;">If you attended the pharmacy for a service, this confirms it has been reviewed and approved by our pharmacy team.</td>
                      </tr>
                      <tr>
                        <td style="width:30px;vertical-align:top;padding:3px 12px 0 0;font-family:Outfit,Arial,Helvetica,sans-serif;color:#10a88a;font-size:15px;font-weight:800;">3</td>
                        <td style="padding:0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;">For delivery orders, we will prepare your treatment and share any delivery updates when available.</td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 34px 26px 34px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#123f40;">
                <tr>
                  <td style="padding:22px 24px;">
                    <p style="margin:0 0 10px 0;font-family:Outfit,Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#10c7a4;font-weight:700;">Order reference</p>
                    <p style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:25px;line-height:31px;color:#ffffff;font-weight:800;letter-spacing:.02em;">' . $safeRef . '</p>
                    <p style="margin:12px 0 0 0;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:21px;color:rgba(255,255,255,.72);">Please keep this reference safe in case you need to contact us about your request.</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 34px 32px 34px;">
              <p style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#64748b;">This email confirms that your request or pharmacy service has been approved following pharmacy review.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

        return $this->subject($subject)
            ->bcc('pharmacy-express.co.uk+567109c4b1@invite.trustpilot.com')
            ->html($body);
    }
}