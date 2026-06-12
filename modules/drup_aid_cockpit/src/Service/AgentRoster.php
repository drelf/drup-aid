<?php

declare(strict_types=1);

namespace Drupal\drup_aid_cockpit\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Builds the cockpit roster from the master agent's configured sub-agents.
 *
 * The roster is derived, not hard-coded: it reads the master agent's `tools`
 * and keeps the ones that delegate to another agent (the
 * `ai_agents::ai_agent::<id>` tool plugins). Install or uninstall a sub-agent
 * and the cockpit roster follows automatically.
 */
final class AgentRoster {

  /**
   * The orchestration agent every request is routed through.
   */
  private const MASTER_ID = 'drupaid_master';

  /**
   * Tool-key prefix that marks a delegation to another agent.
   */
  private const SUBAGENT_TOOL_PREFIX = 'ai_agents::ai_agent::';

  /**
   * Maps an agent id to a droplet avatar key + brand accent colour.
   *
   * Avatars not yet drawn fall back to the closest existing droplet (noted);
   * dedicated Leads / Security / Editor droplets are a follow-up.
   *
   * @var array<string, array{0:string,1:string}>
   */
  private const AVATAR_MAP = [
    'drupaid_master' => ['master', '#00D4FF'],
    'drupaid_content' => ['content', '#2D7DD2'],
    'peak_page_builder' => ['builder', '#F59E0B'],
    'drupaid_kb' => ['knowledge', '#6366F1'],
    'drupaid_analytics' => ['analytics', '#06B6D4'],
    'drupaid_leads' => ['support', '#14B8A6'],
    'peak_security_monitor' => ['seo', '#22C55E'],
    'peak_web_editor' => ['image', '#8B5CF6'],
  ];

  /**
   * Droplet used when an agent id has no explicit mapping.
   */
  private const FALLBACK = ['master', '#0678BE'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * The master agent descriptor.
   *
   * @return array|null
   *   The master agent descriptor, or NULL when it is not installed.
   */
  public function getMaster(): ?array {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $master */
    $master = $this->entityTypeManager->getStorage('ai_agent')->load(self::MASTER_ID);
    return $master ? $this->describe(self::MASTER_ID, $master->label(), (string) $master->get('description')) : NULL;
  }

  /**
   * The sub-agents the master can delegate to, in config order.
   *
   * @return array
   *   A list of agent descriptors.
   */
  public function getSubAgents(): array {
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $master */
    $master = $storage->load(self::MASTER_ID);
    if (!$master) {
      return [];
    }
    $tools = $master->get('tools') ?: [];
    $agents = [];
    foreach ($tools as $tool => $enabled) {
      if (!$enabled || !str_starts_with($tool, self::SUBAGENT_TOOL_PREFIX)) {
        continue;
      }
      $id = substr($tool, strlen(self::SUBAGENT_TOOL_PREFIX));
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity */
      $entity = $storage->load($id);
      if (!$entity) {
        continue;
      }
      $agents[] = $this->describe($id, $entity->label(), (string) $entity->get('description'));
    }
    return $agents;
  }

  /**
   * The full profile for one agent — Identity, Model, Skills, MCP, Team.
   *
   * Adopts the Nous Hermes "agent profile" composition model (identity + model
   * + skills + MCP servers) as the cockpit's agent-detail surface.
   *
   * @return array|null
   *   The profile, or NULL for an unknown agent id.
   */
  public function getProfile(string $id): ?array {
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity */
    $entity = $storage->load($id);
    if (!$entity) {
      return NULL;
    }
    [$key, $color] = self::AVATAR_MAP[$id] ?? self::FALLBACK;
    $config = $entity->toArray();
    $tools = $entity->get('tools') ?: [];

    $skills = [];
    $team = [];
    $mcp = [];
    foreach ($tools as $tool => $enabled) {
      if (!$enabled) {
        continue;
      }
      if (str_starts_with($tool, self::SUBAGENT_TOOL_PREFIX)) {
        $subId = substr($tool, strlen(self::SUBAGENT_TOOL_PREFIX));
        $sub = $storage->load($subId);
        $team[] = ['id' => $subId, 'label' => $sub ? $sub->label() : $subId];
      }
      elseif (stripos($tool, 'mcp') !== FALSE) {
        $mcp[] = $this->prettyTool($tool);
      }
      else {
        $skills[] = $this->prettyTool($tool);
      }
    }

    // Model is best-effort: an ai_agent may bind a provider/model, else it
    // inherits the platform's default AI provider for the operation type.
    $model = 'Platform default AI provider';
    foreach (['llm_model', 'model', 'llm_provider', 'provider', 'default_llm'] as $k) {
      if (!empty($config[$k]) && is_string($config[$k])) {
        $model = $config[$k];
        break;
      }
    }

    return [
      'id' => $id,
      'label' => $entity->label(),
      'description' => trim(strip_tags((string) $entity->get('description'))),
      'avatar' => $this->avatarUrl($key),
      'initial' => mb_strtoupper(mb_substr((string) $entity->label(), 0, 1)),
      'color' => $color,
      'model' => $model,
      'skills' => $skills,
      'mcp' => $mcp,
      'team' => $team,
      'is_master' => $id === self::MASTER_ID,
    ];
  }

  /**
   * Prettifies a tool/plugin key into a human-readable label.
   */
  private function prettyTool(string $tool): string {
    $name = $tool;
    if (str_contains($name, '::')) {
      $parts = explode('::', $name);
      $name = (string) end($parts);
    }
    // Drop redundant plugin-family prefixes (ai_agent:…, action_plugin:…)
    // so the skill reads as the action itself.
    $name = preg_replace('/^(ai_agent|action_plugin|tool)[:.]/i', '', $name);
    $name = str_replace(['_', '-', '.', ':'], ' ', $name);
    $pretty = ucwords(trim($name));
    return $pretty !== '' ? $pretty : $tool;
  }

  /**
   * Builds a single agent descriptor (id, label, blurb, avatar URL, colour).
   *
   * @return array
   *   The agent descriptor.
   */
  private function describe(string $id, string $label, string $description): array {
    [$key, $color] = self::AVATAR_MAP[$id] ?? self::FALLBACK;
    return [
      'id' => $id,
      'label' => $label,
      // First sentence of the description is enough for the roster tooltip.
      'blurb' => $this->firstSentence($description),
      'avatar' => $this->avatarUrl($key),
      // Used by the CSS initials-badge fallback when no artwork is installed.
      'initial' => mb_strtoupper(mb_substr($label, 0, 1)),
      'color' => $color,
    ];
  }

  /**
   * Avatar image URL, or NULL when no artwork is installed.
   *
   * Artwork is an optional, site-local asset: the module ships without images
   * (publishing droplet-style art would need Drupal trademark licensing), and
   * the UI falls back to colored initial badges. Drop PNGs into
   * images/agents/agent-<key>.png to skin the team.
   */
  private function avatarUrl(string $key): ?string {
    $path = $this->moduleList->getPath('drup_aid_cockpit') . '/images/agents/agent-' . $key . '.png';
    return file_exists(DRUPAL_ROOT . '/' . $path) ? '/' . $path : NULL;
  }

  /**
   * Returns the first sentence of a description, for compact UI.
   */
  private function firstSentence(string $text): string {
    $text = trim(strip_tags($text));
    if ($text === '') {
      return '';
    }
    $cut = preg_split('/(?<=[.!?])\s/', $text, 2);
    return $cut[0] ?? $text;
  }

}
