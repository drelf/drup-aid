<?php

declare(strict_types=1);

namespace Drupal\drup_aid_cockpit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\ai\AiProviderPluginManager;
use Drupal\drup_aid_cockpit\Service\AgentRoster;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the Drup-AID agent cockpit.
 */
final class CockpitController extends ControllerBase {

  public function __construct(
    private readonly AgentRoster $roster,
    private readonly AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drup_aid_cockpit.roster'),
      $container->get('ai.provider'),
    );
  }

  /**
   * The cockpit page: roster + conversation theatre + payload pane.
   *
   * @return array
   *   The cockpit render array.
   */
  public function page(): array {
    $master = $this->roster->getMaster();
    $agents = $this->roster->getSubAgents();

    return [
      '#theme' => 'drup_aid_cockpit',
      '#master' => $master,
      '#agents' => $agents,
      '#attached' => [
        'library' => ['drup_aid_cockpit/cockpit'],
        'drupalSettings' => [
          'drupAidCockpit' => [
            'master' => $master,
            'agents' => $agents,
            'live' => $this->liveSettings(),
          ],
        ],
      ],
      // The roster is derived from agent config, so vary the cache on it.
      '#cache' => [
        'tags' => ['config:ai_agent_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Live-run wiring: ride the AI Agents Explorer start/poll endpoints.
   *
   * Returns NULL (demo mode) when the explorer is missing, the user lacks the
   * permission, or no default chat provider is configured — live runs call
   * the real provider, so going live stays an explicit user opt-in.
   *
   * @return array|null
   *   Endpoint URLs, agent id and model for the live transport, or NULL.
   */
  private function liveSettings(): ?array {
    if (!$this->moduleHandler()->moduleExists('ai_agents_explorer')
      || !$this->currentUser()->hasPermission('use the agent explorer')) {
      return NULL;
    }
    try {
      $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat_with_tools')
        ?: $this->aiProvider->getDefaultProviderForOperationType('chat');
      if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
        return NULL;
      }
    }
    catch (\Exception $e) {
      return NULL;
    }
    return [
      'start' => Url::fromRoute('ai_agent_explore.start_exploring')->toString(),
      // JS substitutes the runner uuid for the placeholder.
      'poll' => Url::fromRoute('ai_agent_explore.poll', ['uuid' => 'RUNNER_UUID'])->toString(),
      'agent' => 'drupaid_master',
      'model' => $defaults['provider_id'] . '__' . $defaults['model_id'],
    ];
  }

  /**
   * One agent's profile: Identity, Model, Skills, MCP, Team.
   *
   * @return array
   *   The profile render array.
   */
  public function profile(string $agent): array {
    $profile = $this->roster->getProfile($agent);
    if (!$profile) {
      throw new NotFoundHttpException();
    }

    return [
      '#theme' => 'drup_aid_cockpit_profile',
      '#profile' => $profile,
      '#attached' => [
        'library' => ['drup_aid_cockpit/profile'],
      ],
      '#cache' => [
        'tags' => ['config:ai_agent_list'],
      ],
    ];
  }

}
