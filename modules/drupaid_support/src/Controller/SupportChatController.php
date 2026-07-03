<?php

declare(strict_types=1);

namespace Drupal\drupaid_support\Controller;

use Drupal\node\Entity\Node;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\drupaid_core\BrandManager;
use Drupal\drupaid_core\Service\AiBrain;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public support-chat API for the Drup-AID support desk.
 *
 * Routes:
 *   POST /api/support-chat        — main chat endpoint
 *   GET  /api/support-chat/history/{session_id} — conversation history.
 *
 * The conversational brain is drupaid_core's AiBrain: cloud-first (the Drupal AI
 * module's default chat provider) by default, with an optional n8n path. The
 * original module's inline n8n + OpenAI cURL has been removed — AiBrain owns it.
 */
class SupportChatController extends ControllerBase {

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
   * POST /api/support-chat.
   *
   * Accepts a customer message, runs the support turn through the AI brain,
   * stores the exchange, optionally auto-creates a ticket node, and returns
   * the reply.
   */
  public function chat(Request $request): JsonResponse {
    if ($request->getMethod() === 'OPTIONS') {
      return new JsonResponse(NULL, 204, $this->corsHeaders($request));
    }

    $config = $this->config('drupaid_support.settings');
    $now = \Drupal::time()->getRequestTime();

    $data = json_decode($request->getContent(), TRUE) ?? [];
    $message = trim($data['message'] ?? '');
    $session_id = trim($data['session_id'] ?? '');

    if (empty($message)) {
      return new JsonResponse(['error' => 'Message is required.'], 400, $this->corsHeaders($request));
    }
    if (mb_strlen($message) > 2000) {
      return new JsonResponse(['error' => 'Message is too long. Please keep it under 2000 characters.'], 400, $this->corsHeaders($request));
    }

    $ip = $request->getClientIp();
    if ($this->isRateLimited($ip, $config)) {
      return new JsonResponse(['error' => 'Too many requests. Please wait a moment before trying again.'], 429, $this->corsHeaders($request));
    }

    // New session id, or validate the supplied one.
    if (empty($session_id)) {
      $session_id = 'supp_' . $now . '_' . bin2hex(random_bytes(4));
    }
    elseif (!preg_match('/^(supp|voice)_\d+_[a-f0-9]{8}$/', $session_id)) {
      return new JsonResponse(['error' => 'Invalid session.'], 400, $this->corsHeaders($request));
    }

    // Optional demo access-code gate on new sessions.
    $session_exists = $this->sessionExists($session_id);
    if (!$session_exists && $config->get('demo_gate_enabled')) {
      $expected_norm = mb_strtolower((string) $config->get('demo_access_code'));
      $provided_norm = mb_strtolower(trim($data['access_code'] ?? ''));
      if ($expected_norm === '' || !hash_equals($expected_norm, $provided_norm)) {
        return new JsonResponse(
          ['error' => 'access_code_required', 'message' => 'A valid access code is required to start a demo session.'],
          403,
          $this->corsHeaders($request)
        );
      }
    }

    // Load or initialize session state.
    $state = $session_exists ? $this->getSessionState($session_id) : [];
    if (!$session_exists) {
      $this->database->insert('drupaid_sessions')
        ->fields([
          'session_id' => $session_id,
          'state_data' => json_encode([]),
          'ip_address' => $ip,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }

    $history = $this->getHistory($session_id, 20);

    // Run the support turn through the cloud-first AI brain.
    $payload = [
      'system_prompt' => $this->supportSystemPrompt($state),
      'message' => $message,
      'history' => $history,
      'session_state' => $state,
      'session_id' => $session_id,
      'channel' => 'support',
      'ip' => $ip,
    ];

    $t_start = microtime(TRUE);
    $turn = $this->aiBrain->supportTurn($payload);
    $response_time_ms = (int) round((microtime(TRUE) - $t_start) * 1000);

    $ai_text = $turn['response'] !== ''
      ? $turn['response']
      : ($config->get('fallback_response') ?? "I'm having trouble connecting. Please try again or open a ticket.");
    $delta = is_array($turn['state'] ?? NULL) ? $turn['state'] : [];

    // Merge extracted entities and persist state.
    $state = $this->mergeState($state, $delta);

    // AI reasoning trace (best-effort; table is created by this module).
    $this->logAiTrace($session_id, $payload, $turn['source'] ?? 'cloud', $delta, $ai_text, $response_time_ms);

    // Auto-create a ticket when the brain flags it and one is not open yet.
    $ticket_num = $state['ticket_num'] ?? NULL;
    if (!empty($delta['create_ticket']) && $config->get('ticket_auto_create') && empty($state['ticket_nid'])) {
      $ticket_title = $delta['ticket_title'] ?? ('Support request — ' . ($state['device'] ?? 'unknown device'));
      $ticket = $this->createTicketNode($ticket_title, $session_id, $state);
      $state['ticket_nid'] = $ticket['nid'] ?? NULL;
      $state['ticket_num'] = $ticket['num'] ?? NULL;
      $ticket_num = $state['ticket_num'];
    }

    $this->storeMessage($session_id, 'user', $message, $now);
    $this->storeMessage($session_id, 'ai', $ai_text, $now);

    $this->database->merge('drupaid_sessions')
      ->keys(['session_id' => $session_id])
      ->fields([
        'state_data' => json_encode($state),
        'ip_address' => $ip,
        'changed' => $now,
      ])
      ->execute();

    return new JsonResponse([
      'response' => $ai_text,
      'session_id' => $session_id,
      'state' => $state,
      'ticket_num' => $ticket_num,
    ], 200, $this->corsHeaders($request));
  }

  // ---------------------------------------------------------------------------
  // HISTORY ENDPOINT
  // ---------------------------------------------------------------------------

  /**
   * GET /api/support-chat/history/{session_id}.
   */
  public function history(Request $request, string $session_id): JsonResponse {
    if (empty($session_id)) {
      return new JsonResponse(['messages' => [], 'state' => []], 200, $this->corsHeaders($request));
    }
    if (!preg_match('/^(supp|voice)_\d+_[a-f0-9]{8}$/', $session_id)) {
      return new JsonResponse(['error' => 'Invalid session.'], 400, $this->corsHeaders($request));
    }

    // Bind the session to the requesting IP.
    $ip = $request->getClientIp();
    $session_ip = $this->database->select('drupaid_sessions', 's')
      ->fields('s', ['ip_address'])
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchField();

    if (!$session_ip || $session_ip !== $ip) {
      return new JsonResponse(['error' => 'Session not found.'], 404, $this->corsHeaders($request));
    }

    return new JsonResponse([
      'messages' => $this->getHistory($session_id, 50),
      'state' => $this->getSessionState($session_id),
      'session_id' => $session_id,
    ], 200, $this->corsHeaders($request));
  }

  // ---------------------------------------------------------------------------
  // PRIVATE HELPERS
  // ---------------------------------------------------------------------------

  /**
   * Build the support agent's system instructions (brand-aware, tenant-neutral).
   */
  protected function supportSystemPrompt(array $state): string {
    $agent = $this->brand->supportAgentName();
    $captured = [];
    foreach (['customer_name', 'device', 'os', 'issue_category', 'description', 'account_number', 'ticket_num'] as $f) {
      if (!empty($state[$f])) {
        $captured[] = $f . ': ' . $state[$f];
      }
    }
    $captured_block = $captured ? "Already captured this session: " . implode('; ', $captured) . ".\n" : '';

    return <<<TXT
You are {$agent}, a customer support assistant. Help the user diagnose their issue. Ask one clear question at a time, acknowledge what they said, and over a few messages gather: the affected device/equipment, the operating system, what they were trying to do when it broke, and what they have already tried.

Style: calm, technically competent, friendly, brief (1-3 sentences). Never robotic. Never ask for information you already have.

If the user is stuck or wants a human, or you have gathered enough to escalate, set "create_ticket": true and a short "ticket_title" in the state block so a teammate can follow up.

{$captured_block}
TXT;
  }

  /**
   * Log an AI reasoning trace to drupaid_ai_log (best-effort).
   */
  protected function logAiTrace(string $session_id, array $prompt_payload, string $source, array $extracted_state, string $cleaned_response, int $response_time_ms): void {
    try {
      if (!$this->database->schema()->tableExists('drupaid_ai_log')) {
        return;
      }
      $this->database->insert('drupaid_ai_log')
        ->fields([
          'session_id' => $session_id,
          'prompt_payload' => json_encode($prompt_payload),
          'raw_response' => $cleaned_response,
          'extracted_state' => json_encode($extracted_state),
          'cleaned_response' => $cleaned_response,
          'response_time_ms' => $response_time_ms,
          'model' => $source,
          'tokens_used' => NULL,
          'created' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->getLogger('drupaid_support')->warning('Failed to log AI trace: @err', ['@err' => $e->getMessage()]);
    }
  }

  /**
   * Merge AI-extracted entities into session state (never overwrite with empty).
   */
  protected function mergeState(array $state, array $ai_data): array {
    $fields = [
      'tier', 'device', 'os', 'issue_category', 'customer_name', 'phone',
      'address', 'contact', 'account_number', 'equipment', 'description',
      'resolution', 'notes',
    ];
    foreach ($fields as $field) {
      if (!empty($ai_data[$field])) {
        $state[$field] = $ai_data[$field];
      }
    }
    if (isset($ai_data['is_customer'])) {
      $state['is_customer'] = (bool) $ai_data['is_customer'];
    }
    if (isset($ai_data['flow_step']) && is_numeric($ai_data['flow_step'])) {
      $state['flow_step'] = (int) $ai_data['flow_step'];
    }
    if (!empty($ai_data['steps']) && is_array($ai_data['steps'])) {
      $existing = $state['steps_tried'] ?? [];
      $state['steps_tried'] = array_values(array_unique(array_merge($existing, $ai_data['steps'])));
    }
    return $state;
  }

  /**
   * Create a node as the support ticket. Returns ['nid' => int, 'num' => string].
   */
  protected function createTicketNode(string $title, string $session_id, array $state): array {
    try {
      $ticket_num = 'TKT-' . str_pad((string) ((int) \Drupal::time()->getRequestTime() % 100000), 5, '0', STR_PAD_LEFT);

      $body = "**Ticket:** {$ticket_num}\n";
      $body .= "**Session:** {$session_id}\n\n";
      if (!empty($state['device'])) {
        $body .= "**Device:** {$state['device']}\n";
      }
      if (!empty($state['os'])) {
        $body .= "**OS:** {$state['os']}\n";
      }
      if (!empty($state['tier'])) {
        $body .= "**Tier:** {$state['tier']}\n";
      }
      if (!empty($state['customer_name'])) {
        $body .= "**Customer:** {$state['customer_name']}\n";
      }

      $node_type = $this->config('drupaid_support.settings')->get('ticket_node_type') ?: 'page';

      $node = Node::create([
        'type' => $node_type,
        'title' => $ticket_num . ': ' . $title,
        'body' => ['value' => $body, 'format' => 'plain_text'],
        'status' => 0,
        'uid' => 1,
      ]);
      $node->save();

      $this->getLogger('drupaid_support')->info('Auto-created ticket @num (nid @nid) for session @sess.', [
        '@num' => $ticket_num,
        '@nid' => $node->id(),
        '@sess' => $session_id,
      ]);

      return ['nid' => (int) $node->id(), 'num' => $ticket_num];
    }
    catch (\Exception $e) {
      $this->getLogger('drupaid_support')->error('Failed to create ticket node: @err', ['@err' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Whether a session row exists.
   */
  protected function sessionExists(string $session_id): bool {
    return (bool) $this->database->select('drupaid_sessions', 's')
      ->fields('s', ['session_id'])
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchField();
  }

  /**
   * Load session state JSON.
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
   * Store a single message.
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
   * Load conversation history (oldest first), formatted for the brain payload.
   */
  protected function getHistory(string $session_id, int $limit = 20): array {
    $rows = $this->database->select('drupaid_messages', 'm')
      ->fields('m', ['role', 'message', 'created'])
      ->condition('session_id', $session_id)
      ->orderBy('created', 'ASC')
      ->orderBy('id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    return $rows ?: [];
  }

  /**
   * Simple IP-based rate limiter using recent session count.
   */
  protected function isRateLimited(string $ip, $config): bool {
    $max = (int) ($config->get('rate_limit_max') ?? 20);
    $window = (int) ($config->get('rate_limit_window') ?? 3600);
    $cutoff = \Drupal::time()->getRequestTime() - $window;

    $count = $this->database->select('drupaid_sessions', 's')
      ->condition('ip_address', $ip)
      ->condition('created', $cutoff, '>')
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $count >= $max;
  }

  /**
   * Build CORS response headers from the tenant allow-list.
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
