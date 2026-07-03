<?php

namespace Drupal\drupaid_leads\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\drupaid_core\BrandManager;
use Drupal\drupaid_core\Service\AiBrain;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Front-page lead-capture chat for the Drup-AID lead minion.
 *
 * Routes:
 *   POST /api/lead-chat                        — send message
 *   GET  /api/lead-chat/history/{session_id}    — restore session.
 *
 * The brain is drupaid_core's AiBrain (cloud-first, n8n optional). A
 * deterministic builtInFlow() remains as a zero-AI final safety net so leads are
 * still captured even with no provider configured.
 */
class LeadChatController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected AiBrain $aiBrain,
    protected BrandManager $brand,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('drupaid_core.ai_brain'),
      $container->get('drupaid_core.brand'),
    );
  }

  // ---------------------------------------------------------------------------
  // MAIN CHAT ENDPOINT
  // ---------------------------------------------------------------------------

  /**
   * POST /api/lead-chat.
   */
  public function chat(Request $request): JsonResponse {
    if ($request->getMethod() === 'OPTIONS') {
      return new JsonResponse(NULL, 204, $this->corsHeaders($request));
    }

    $config = $this->config('drupaid_leads.settings');
    $now = \Drupal::time()->getRequestTime();

    $data = json_decode($request->getContent(), TRUE) ?? [];
    $message = trim($data['message'] ?? '');
    $session_id = trim($data['session_id'] ?? '');

    if (empty($message)) {
      return new JsonResponse(['error' => 'Message is required.'], 400, $this->corsHeaders($request));
    }

    if (mb_strlen($message) > 2000) {
      return new JsonResponse(['error' => 'Message too long.'], 400, $this->corsHeaders($request));
    }

    $ip = $request->getClientIp();
    if ($this->isRateLimited($ip, $config)) {
      return new JsonResponse(['error' => 'Too many requests.'], 429, $this->corsHeaders($request));
    }

    // Generate or validate session ID (lead_ prefix, 16 hex = 64-bit entropy).
    if (empty($session_id)) {
      $session_id = 'lead_' . $now . '_' . bin2hex(random_bytes(8));
    }
    elseif (!preg_match('/^lead_\d+_[a-f0-9]{16,32}$/', $session_id)) {
      return new JsonResponse(['error' => 'Invalid session.'], 400, $this->corsHeaders($request));
    }

    // Reuse drupaid_sessions table from drupaid_support module.
    $session_exists = $this->sessionExists($session_id);
    $state = $session_exists ? $this->getSessionState($session_id) : [];

    // Validate IP ownership for existing sessions.
    if ($session_exists) {
      $session_ip = $this->database->select('drupaid_sessions', 's')
        ->fields('s', ['ip_address'])
        ->condition('session_id', $session_id)
        ->execute()
        ->fetchField();
      if ($session_ip && $session_ip !== $ip) {
        return new JsonResponse(['error' => 'Invalid session.'], 400, $this->corsHeaders($request));
      }
    }

    if (!$session_exists) {
      $this->database->insert('drupaid_sessions')
        ->fields([
          'session_id' => $session_id,
          'state_data' => json_encode(['channel' => 'lead']),
          'ip_address' => $ip,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
      $state = ['channel' => 'lead'];
    }

    // Load conversation history.
    $history = $this->getHistory($session_id, 20);

    // Run the lead turn through the cloud-first AI brain.
    $turn = $this->aiBrain->supportTurn([
      'system_prompt' => $this->leadSystemPrompt($state),
      'message' => $message,
      'history' => $history,
      'session_state' => $state,
      'session_id' => $session_id,
      'channel' => 'lead',
    ]);

    $ai_response_data = [];
    if (($turn['response'] ?? '') !== '') {
      $ai_response_data = ['response' => $turn['response']] + (is_array($turn['state'] ?? NULL) ? $turn['state'] : []);
    }

    // Final safety net: deterministic state-machine flow with no LLM.
    if (empty($ai_response_data)) {
      $ai_response_data = $this->builtInFlow($message, $state, $history);
    }

    $ai_text = $ai_response_data['response']
      ?? $ai_response_data['output']
      ?? "Thanks for your interest! Could you tell me a bit more about what you're looking for?";

    // Merge any extracted lead fields into state.
    $state = $this->mergeState($state, $ai_response_data);

    // Safety net: extract email directly from the user's message via regex.
    // The AI sometimes drops the JSON state block
    // it's supposed to append (observed 2026-05-15 — lost a real lead). Even
    // when the AI doesn't extract it, the user typed it — capture it here so
    // the lead still lands. lead_name + lead_company stay AI-dependent (no
    // clean regex), but email is the highest-value field.
    if (empty($state['lead_email']) && preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $message, $em)) {
      if (filter_var($em[0], FILTER_VALIDATE_EMAIL)) {
        $state['lead_email'] = $em[0];
        $this->getLogger('drupaid_leads')->info(
          'Email captured via regex safety net (AI did not extract): @em',
          ['@em' => $em[0]]
        );
      }
    }

    // Check if we have enough info to create/update a lead record.
    // Email alone is enough — if the AI failed to extract a name, save the
    // lead with a placeholder name so the email isn't lost. A human can
    // review the session conversation to fill in details. Better to capture
    // an incomplete lead than to lose an email entirely.
    $lead_created = FALSE;
    if (!empty($state['lead_email'])) {
      if (empty($state['lead_name'])) {
        $state['lead_name'] = '(name not extracted — see chat history)';
      }
      $lead_created = $this->upsertLead($session_id, $state, $ip, $now);
    }

    // Store messages.
    $this->storeMessage($session_id, 'user', $message, $now);
    $this->storeMessage($session_id, 'ai', $ai_text, $now);

    // Update session state (do NOT overwrite ip_address on existing sessions).
    $update_fields = [
      'state_data' => json_encode($state),
      'changed' => $now,
    ];
    if (!$session_exists) {
      $update_fields['ip_address'] = $ip;
    }
    $this->database->merge('drupaid_sessions')
      ->keys(['session_id' => $session_id])
      ->fields($update_fields)
      ->execute();

    // Only return what the client needs (not full internal state).
    $response = [
      'response' => $ai_text,
      'session_id' => $session_id,
    ];

    if ($lead_created) {
      $response['lead_captured'] = TRUE;
    }

    return new JsonResponse($response, 200, $this->corsHeaders($request));
  }

  // ---------------------------------------------------------------------------
  // HISTORY ENDPOINT
  // ---------------------------------------------------------------------------

  /**
   * GET /api/lead-chat/history/{session_id}.
   */
  public function history(Request $request, string $session_id): JsonResponse {
    if (empty($session_id)) {
      return new JsonResponse(['messages' => [], 'state' => []], 200, $this->corsHeaders($request));
    }

    if (!preg_match('/^lead_\d+_[a-f0-9]{16,32}$/', $session_id)) {
      return new JsonResponse(['error' => 'Invalid session.'], 400, $this->corsHeaders($request));
    }

    $ip = $request->getClientIp();
    $session_ip = $this->database->select('drupaid_sessions', 's')
      ->fields('s', ['ip_address'])
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchField();

    if (!$session_ip || $session_ip !== $ip) {
      return new JsonResponse(['error' => 'Session not found.'], 404, $this->corsHeaders($request));
    }

    $messages = $this->getHistory($session_id, 50);

    return new JsonResponse([
      'messages' => $messages,
      'session_id' => $session_id,
    ], 200, $this->corsHeaders($request));
  }

  // ---------------------------------------------------------------------------
  // BUILT-IN CONVERSATIONAL FLOW (fallback when n8n not configured)
  // ---------------------------------------------------------------------------

  /**
   * Simple state-machine flow to collect lead info without AI.
   *
   * User input is HTML-encoded to prevent stored XSS.
   */
  protected function builtInFlow(string $message, array $state, array $history): array {
    $step = $state['flow_step'] ?? 0;
    $extracted = [];
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    switch ($step) {
      case 0:
        // First message — user replied to greeting. This is likely their name.
        $extracted['lead_name'] = $message;
        $extracted['flow_step'] = 1;
        return [
          'response' => "Nice to meet you, {$safe}! What company or organization are you with?",
        ] + $extracted;

      case 1:
        // Company name.
        $extracted['lead_company'] = $message;
        $extracted['flow_step'] = 2;
        return [
          'response' => "Great — {$safe} sounds interesting! What's the best email to reach you at? I'll have our team send over demo access.",
        ] + $extracted;

      case 2:
        // Email — validate strictly.
        $email = trim($message);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $extracted['lead_email'] = $email;
          $extracted['flow_step'] = 3;
          return [
            'response' => "Perfect! One last thing — what kind of support does your team handle today? (e.g., tech support, customer service, helpdesk)",
          ] + $extracted;
        }
        return [
          'response' => "Hmm, that doesn't look like an email address. Could you double-check and send your email? We'll use it to send your demo access.",
        ];

      case 3:
        // Needs / use case.
        $extracted['lead_needs'] = $message;
        $extracted['flow_step'] = 4;
        return [
          'response' => "Thank you! I've sent your info to our team. You'll receive a demo access code at your email shortly after review. In the meantime, feel free to check out our <a href='/pricing'>pricing</a> or <a href='/knowledge-base'>knowledge base</a>. Anything else I can help with?",
        ] + $extracted;

      default:
        // Post-capture conversation.
        return [
          'response' => "Your demo request is being reviewed! Our team will email you an access code soon. If you have questions, feel free to <a href='/contact'>contact us</a> or keep chatting here.",
        ];
    }
  }

  // ---------------------------------------------------------------------------
  // LEAD MANAGEMENT
  // ---------------------------------------------------------------------------

  /**
   * Create or update a lead record in drupaid_leads.
   *
   * Returns TRUE if a new lead was created (triggers notification).
   */
  protected function upsertLead(string $session_id, array $state, string $ip, int $now): bool {
    // Sanitize and length-limit values before storage.
    $name = mb_substr(trim($state['lead_name'] ?? ''), 0, 255);
    $company = mb_substr(trim($state['lead_company'] ?? ''), 0, 255);
    $email = mb_substr(trim($state['lead_email'] ?? ''), 0, 255);
    $needs = mb_substr(trim($state['lead_needs'] ?? ''), 0, 5000);

    $existing = $this->database->select('drupaid_leads', 'l')
      ->fields('l', ['id'])
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('drupaid_leads')
        ->fields([
          'name' => $name,
          'company' => $company,
          'email' => $email,
          'needs' => $needs,
          'changed' => $now,
        ])
        ->condition('id', $existing)
        ->execute();
      return FALSE;
    }

    // Insert new lead.
    $this->database->insert('drupaid_leads')
      ->fields([
        'session_id' => $session_id,
        'name' => $name,
        'company' => $company,
        'email' => $email,
        'needs' => $needs,
        'status' => 'pending',
        'ip_address' => $ip,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();

    // Send notification email to owner.
    $this->notifyOwner($state);

    return TRUE;
  }

  /**
   * Send email notification to the site owner about a new lead.
   *
   * Strips newlines from name/company to prevent email header injection.
   */
  protected function notifyOwner(array $state): void {
    $config = $this->config('drupaid_leads.settings');
    $to = $config->get('notification_email');
    if (empty($to)) {
      return;
    }

    // Strip newlines/carriage returns to prevent header injection.
    $name = str_replace(["\r", "\n"], '', $state['lead_name'] ?? 'Unknown');
    $company = str_replace(["\r", "\n"], '', $state['lead_company'] ?? 'Unknown');
    $email = $state['lead_email'] ?? 'Unknown';
    $needs = $state['lead_needs'] ?? 'Not provided yet';

    $site_url = $this->brand->siteUrl();
    $admin_link = ($site_url !== '' ? $site_url : '') . '/admin/leads';

    $subject = "New Lead: " . mb_substr($name, 0, 60) . " from " . mb_substr($company, 0, 60);
    $body = "A new lead has been captured for " . $this->brand->companyName() . ":\n\n";
    $body .= "Name: {$name}\n";
    $body .= "Company: {$company}\n";
    $body .= "Email: {$email}\n";
    $body .= "Needs: {$needs}\n\n";
    $body .= "Review and approve: {$admin_link}\n";

    $params = [
      'subject' => $subject,
      'body' => $body,
    ];

    try {
      \Drupal::service('plugin.manager.mail')->mail(
        'drupaid_leads',
        'lead_notification',
        $to,
        'en',
        $params,
      );
    }
    catch (\Exception $e) {
      $this->getLogger('drupaid_leads')->error('Failed to send lead notification: @err', ['@err' => $e->getMessage()]);
    }
  }

  // ---------------------------------------------------------------------------
  // HELPERS
  // ---------------------------------------------------------------------------

  /**
   * Build the lead-qualification agent's system instructions (brand-aware).
   */
  protected function leadSystemPrompt(array $state): string {
    $agent = $this->brand->leadAgentName();
    $company = $this->brand->companyName();
    $captured = [];
    foreach (['lead_name', 'lead_company', 'lead_email', 'lead_needs'] as $f) {
      if (!empty($state[$f])) {
        $captured[] = $f . ': ' . $state[$f];
      }
    }
    $captured_block = $captured ? "Already captured from this lead: " . implode('; ', $captured) . ".\n" : '';

    return <<<TXT
You are {$agent}, the lead-qualification assistant for {$company}. Have a short, friendly conversation with website visitors and gather (in this rough order, but stay conversational): their name, their company or organization, their work email, and what kind of support their team handles today.

As you learn each fact, record it in the state block using these exact keys: lead_name, lead_company, lead_email, lead_needs.

Style: warm, brief, professional. One question at a time. Acknowledge what the user said before asking the next thing. Never robotic. Never ask for information you already have. If the user goes off-topic, answer briefly and gently steer back to qualifying questions. Never invent product features, prices, or stats — if asked, say a teammate will follow up by email.

{$captured_block}
TXT;
  }

  /**
   * Merge extracted lead fields into state with validation.
   */
  protected function mergeState(array $state, array $data): array {
    // String fields — trim and length-limit.
    foreach (['lead_name', 'lead_company', 'lead_needs'] as $field) {
      if (isset($data[$field]) && $data[$field] !== '') {
        $state[$field] = mb_substr(trim((string) $data[$field]), 0, 500);
      }
    }

    // Email — must be valid.
    if (isset($data['lead_email']) && filter_var(trim($data['lead_email']), FILTER_VALIDATE_EMAIL)) {
      $state['lead_email'] = trim($data['lead_email']);
    }

    // Flow step — must be integer.
    if (isset($data['flow_step'])) {
      $state['flow_step'] = (int) $data['flow_step'];
    }

    return $state;
  }

  /**
   * {@inheritdoc}
   */
  protected function sessionExists(string $session_id): bool {
    return (bool) $this->database->select('drupaid_sessions', 's')
      ->fields('s', ['session_id'])
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  protected function getSessionState(string $session_id): array {
    $raw = $this->database->select('drupaid_sessions', 's')
      ->fields('s', ['state_data'])
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchField();
    return $raw ? (json_decode($raw, TRUE) ?? []) : [];
  }

  /**
   * {@inheritdoc}
   */
  protected function storeMessage(string $session_id, string $role, string $message, int $now): void {
    $this->database->insert('drupaid_messages')
      ->fields([
        'session_id' => $session_id,
        'role' => $role,
        'message' => $message,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  protected function getHistory(string $session_id, int $limit = 20): array {
    return $this->database->select('drupaid_messages', 'm')
      ->fields('m', ['role', 'message', 'created'])
      ->condition('session_id', $session_id)
      ->orderBy('created', 'ASC')
      ->orderBy('id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC) ?? [];
  }

  /**
   * Rate limit by message count per IP (not session count).
   */
  protected function isRateLimited(string $ip, $config): bool {
    $max = (int) ($config->get('rate_limit_max') ?? 30);
    $window = (int) ($config->get('rate_limit_window') ?? 3600);
    $cutoff = \Drupal::time()->getRequestTime() - $window;

    $query = $this->database->select('drupaid_messages', 'm');
    $query->join('drupaid_sessions', 's', 's.session_id = m.session_id');
    $count = $query
      ->condition('s.ip_address', $ip)
      ->condition('m.created', $cutoff, '>')
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $count >= $max;
  }

  /**
   * {@inheritdoc}
   */
  protected function corsHeaders(Request $request): array {
    $headers = [
      'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS',
      'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
      'Content-Type' => 'application/json',
    ];
    $origin = $request->headers->get('Origin', '');
    if ($origin !== '' && in_array($origin, $this->brand->corsAllowedOrigins(), TRUE)) {
      $headers['Access-Control-Allow-Origin'] = $origin;
      $headers['Vary'] = 'Origin';
    }
    return $headers;
  }

}
