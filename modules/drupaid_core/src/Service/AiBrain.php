<?php

declare(strict_types=1);

namespace Drupal\drupaid_core\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\drupaid_core\BrandManager;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The shared AI brain for every Drup-AID support minion.
 *
 * Cloud-first by design: by default every completion is answered by the Drupal
 * AI module's configured default chat provider, so the box works the moment a
 * cloud LLM key is set — no n8n, no GPU, no local model. A tenant who wants the
 * visual-workflow path can flip drupaid_core.settings:n8n_enabled and supply a
 * webhook; conversational turns then route through n8n instead, with the cloud
 * provider remaining as the automatic fallback if n8n is unreachable.
 *
 * This replaces the per-controller cURL-to-OpenAI + cURL-to-n8n logic that the
 * original Peak AI Support modules each carried, so brand strings, model choice,
 * and endpoint wiring live in exactly one place.
 */
class AiBrain {

  /**
   * The drupaid_core logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AiProviderPluginManager $aiProvider,
    protected BrandManager $brand,
    protected ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('drupaid_core');
  }

  /**
   * One-shot completion: a system instruction + a single user prompt.
   *
   * Used by content-generation minions (e.g. the KB writer). Returns the model's
   * text, or '' on failure (caller decides how to handle an empty result).
   */
  public function complete(string $systemPrompt, string $userPrompt): string {
    $messages = [];
    if ($systemPrompt !== '') {
      $messages[] = new ChatMessage('system', $systemPrompt);
    }
    $messages[] = new ChatMessage('user', $userPrompt);
    return $this->cloudChat($messages);
  }

  /**
   * A conversational support turn — the cloud-first replacement for the n8n call.
   *
   * @param array $payload
   *   Keys:
   *   - system_prompt: (string) caller-supplied agent instructions. Brand context
   *     is appended automatically.
   *   - message: (string) the current user/caller utterance.
   *   - history: (array) prior turns as ['role' => 'user'|'ai', 'message' => str].
   *   - session_state: (array) current accumulated state.
   *   - Any additional keys (session_id, channel, ip, kb_articles, …) are passed
   *     through verbatim to n8n when the n8n path is active.
   *
   * @return array
   *   ['response' => string, 'state' => array of state deltas, 'source' => string].
   */
  public function supportTurn(array $payload): array {
    $config = $this->configFactory->get('drupaid_core.settings');

    if ($config->get('n8n_enabled') && !empty($config->get('n8n_webhook_url'))) {
      $result = $this->n8nTurn($payload, (string) $config->get('n8n_webhook_url'), (string) $config->get('n8n_api_key'));
      if ($result !== NULL) {
        return $result + ['source' => 'n8n'];
      }
      // n8n configured but unreachable/errored — fall through to the cloud brain.
      $this->logger->warning('n8n support turn failed; falling back to cloud provider.');
    }

    return $this->cloudTurn($payload) + ['source' => 'cloud'];
  }

  /**
   * Cloud path for a support turn.
   *
   * Drives the conversation with the default chat provider and asks it to emit
   * an optional [STATE]{…}[/STATE] JSON block that we parse back into structured
   * session state.
   */
  protected function cloudTurn(array $payload): array {
    $system = trim(($payload['system_prompt'] ?? '') . "\n\n" . $this->brand->promptContext());
    $system .= "\n\nWhen you learn or update any structured fact about the user or their issue "
      . "(such as name, tier, device, os, issue_category, phone, address, account_number, "
      . "equipment, description, resolution, or flow_step), append a single line at the very "
      . "end of your reply in EXACTLY this form and nothing after it: "
      . "[STATE]{\"key\":\"value\"}[/STATE]. Never show that line's braces to the user as prose.";

    $messages = [new ChatMessage('system', $system)];
    foreach (($payload['history'] ?? []) as $turn) {
      $role = ($turn['role'] ?? 'user') === 'ai' ? 'assistant' : 'user';
      $text = (string) ($turn['message'] ?? '');
      if ($text !== '') {
        $messages[] = new ChatMessage($role, $text);
      }
    }
    $messages[] = new ChatMessage('user', (string) ($payload['message'] ?? ''));

    $raw = $this->cloudChat($messages);
    if ($raw === '') {
      return [
        'response' => "I'm having trouble reaching the assistant right now. Please try again in a moment.",
        'state' => [],
      ];
    }

    [$reply, $state] = $this->extractState($raw);
    return ['response' => $reply, 'state' => $state];
  }

  /**
   * Optional n8n path for a support turn.
   *
   * POSTs the payload to the workflow webhook and parses its reply. Returns NULL
   * on any failure so the caller can fall back to cloud.
   */
  protected function n8nTurn(array $payload, string $url, string $apiKey): ?array {
    $forward = $payload;
    unset($forward['system_prompt']);

    try {
      $headers = ['Content-Type' => 'application/json'];
      if ($apiKey !== '') {
        $headers['X-API-Key'] = $apiKey;
      }
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'json' => $forward,
        'timeout' => 8,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error('n8n request failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }

    if (!is_array($body)) {
      return NULL;
    }
    $reply = $body['response'] ?? $body['output'] ?? '';
    if (!is_string($reply) || $reply === '') {
      return NULL;
    }
    [$clean, $state] = $this->extractState($reply);
    // n8n workflows may also return flat state keys alongside the text.
    $passthrough = [
      'tier', 'device', 'os', 'issue_category', 'customer_name', 'phone',
      'address', 'account_number', 'equipment', 'description', 'resolution',
      'notes', 'flow_step', 'is_customer',
    ];
    foreach ($passthrough as $key) {
      if (array_key_exists($key, $body)) {
        $state[$key] = $body[$key];
      }
    }
    return ['response' => $clean, 'state' => $state];
  }

  /**
   * Run a chat completion against the AI module's default chat provider.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $messages
   *   The ordered chat messages (system/user/assistant).
   *
   * @return string
   *   The model's text reply, or '' if no provider is configured or it errors.
   */
  protected function cloudChat(array $messages): string {
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      $this->logger->error('No default chat provider/model configured for the AI module.');
      return '';
    }

    try {
      $provider = $this->aiProvider->createInstance($defaults['provider_id']);
      $result = $provider->chat(new ChatInput($messages), $defaults['model_id']);
      return trim($result->getNormalized()->getText());
    }
    catch (\Throwable $e) {
      $this->logger->error('Cloud chat completion failed: @msg', ['@msg' => $e->getMessage()]);
      return '';
    }
  }

  /**
   * Split a model reply into clean user-facing text and a parsed state delta.
   *
   * Mirrors the extraction the original support controllers did inline: it
   * recognises a [STATE]{…}[/STATE] marker, a fenced ```json block, or a bare
   * trailing JSON object, strips it from the visible reply, and returns the
   * decoded array.
   *
   * @return array{0:string,1:array}
   *   [clean_reply, state_array].
   */
  protected function extractState(string $raw): array {
    $state = [];

    if (preg_match('/\[STATE\]\s*(\{.*?\})\s*\[\/STATE\]/s', $raw, $m)) {
      $decoded = json_decode($m[1], TRUE);
      if (is_array($decoded)) {
        $state = $decoded;
      }
      $raw = trim(str_replace($m[0], '', $raw));
      return [$raw, $state];
    }

    if (preg_match('/```json\s*(\{.*?\})\s*```/s', $raw, $m)) {
      $decoded = json_decode($m[1], TRUE);
      if (is_array($decoded)) {
        $state = $decoded;
      }
      $raw = trim(str_replace($m[0], '', $raw));
      return [$raw, $state];
    }

    return [trim($raw), $state];
  }

}
