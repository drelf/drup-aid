<?php

declare(strict_types=1);

namespace Drupal\drupaid_leads\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Minimal, no-AI lead capture.
 *
 * Collects name + phone + email (+ optional message), stores the lead, and
 * emails the administrator. Deliberately says nothing about the business — it
 * just captures the contact and hands off.
 */
final class LeadCaptureController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('plugin.manager.mail'),
      $container->get('datetime.time'),
    );
  }

  /**
   * POST /api/lead-capture — save a lead and notify the administrator.
   */
  public function capture(Request $request): JsonResponse {
    if ($request->getMethod() === 'OPTIONS') {
      return new JsonResponse(NULL, 204, $this->corsHeaders());
    }

    $data = json_decode($request->getContent(), TRUE) ?? [];
    $name = trim((string) ($data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));

    // Require a name and at least one way to reach them.
    if ($name === '' || ($phone === '' && $email === '')) {
      return new JsonResponse(['error' => 'Please give your name and a phone or email.'], 400, $this->corsHeaders());
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse(['error' => 'That email address looks invalid.'], 400, $this->corsHeaders());
    }
    // Light length guards.
    foreach (['name' => $name, 'phone' => $phone, 'email' => $email] as $val) {
      if (mb_strlen($val) > 255) {
        return new JsonResponse(['error' => 'One of the fields is too long.'], 400, $this->corsHeaders());
      }
    }

    $now = $this->time->getRequestTime();
    $this->database->insert('drupaid_leads')
      ->fields([
        'session_id' => 'capture_' . $now . '_' . bin2hex(random_bytes(6)),
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'needs' => $message !== '' ? mb_substr($message, 0, 2000) : NULL,
        'status' => 'pending',
        'ip_address' => $request->getClientIp(),
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();

    $this->notifyAdministrator($name, $phone, $email, $message);

    return new JsonResponse([
      'ok' => TRUE,
      'message' => 'Thanks! We\'ve got your details and will be in touch shortly.',
    ], 200, $this->corsHeaders());
  }

  /**
   * Email the administrator that a new lead came in.
   */
  protected function notifyAdministrator(string $name, string $phone, string $email, string $message): void {
    $leadsConfig = $this->config('drupaid_leads.settings');
    $to = (string) ($leadsConfig->get('notification_email')
      ?? $this->config('drupaid_core.settings')->get('support_email')
      ?? $this->config('system.site')->get('mail'));
    if ($to === '') {
      return;
    }

    $siteUrl = (string) ($this->config('drupaid_core.settings')->get('site_url') ?? '');
    $body = "New lead captured from the website chat.\n\n"
      . "Name:    {$name}\n"
      . "Phone:   " . ($phone !== '' ? $phone : '(not given)') . "\n"
      . "Email:   " . ($email !== '' ? $email : '(not given)') . "\n"
      . "Message: " . ($message !== '' ? $message : '(none)') . "\n\n"
      . 'Review leads: ' . ($siteUrl !== '' ? $siteUrl : '') . "/admin/leads\n";

    try {
      $this->mailManager->mail(
        'drupaid_leads',
        'lead_notification',
        $to,
        $this->languageManager()->getDefaultLanguage()->getId(),
        ['subject' => 'New website lead: ' . $name, 'body' => $body],
      );
    }
    catch (\Throwable $e) {
      $this->getLogger('drupaid_leads')->error('Lead capture notification failed: @err', ['@err' => $e->getMessage()]);
    }
  }

  /**
   * Same-origin-friendly CORS headers for the public endpoint.
   */
  protected function corsHeaders(): array {
    return [
      'Access-Control-Allow-Origin' => '*',
      'Access-Control-Allow-Methods' => 'POST, OPTIONS',
      'Access-Control-Allow-Headers' => 'Content-Type',
    ];
  }

}
