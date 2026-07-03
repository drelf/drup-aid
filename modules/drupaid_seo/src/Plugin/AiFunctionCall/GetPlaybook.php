<?php

declare(strict_types=1);

namespace Drupal\drupaid_seo\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executable tool: return a step-by-step playbook for a manual SEO task.
 *
 * Some SEO work cannot be automated from inside Drupal (it needs a Google
 * account, a third-party tool, or human judgment). This tool returns the
 * playbook so the agent can instruct the user through it.
 */
#[FunctionCall(
  id: 'drupaid_seo:get_playbook',
  function_name: 'drupaid_seo_get_playbook',
  name: 'Get an SEO playbook',
  description: 'Returns a step-by-step playbook the agent can use to guide the user through a MANUAL SEO task that cannot be automated. Topics: gtm_event_tracking, gsc_ga4_oauth, looker_dashboard, geo_rewrite, keyword_research.',
  group: 'information_tools',
  context_definitions: [
    'topic' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Topic'),
      description: new TranslatableMarkup('Which playbook: gtm_event_tracking, gsc_ga4_oauth, looker_dashboard, geo_rewrite, or keyword_research.'),
      required: TRUE,
    ),
  ],
)]
final class GetPlaybook extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The extension path resolver.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected ExtensionPathResolver $pathResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->pathResolver = $container->get('extension.path.resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $topic = strtolower(trim((string) $this->getContextValue('topic')));
    $topic = preg_replace('/[^a-z0-9_]/', '', $topic);

    $dir = $this->pathResolver->getPath('module', 'drupaid_seo') . '/playbooks';
    $available = [];
    foreach (glob($dir . '/*.md') ?: [] as $file) {
      $available[] = basename($file, '.md');
    }

    if ($topic === '' || !in_array($topic, $available, TRUE)) {
      $this->setOutput('No playbook named "' . $topic . '". Available playbooks: ' . implode(', ', $available) . '.');
      return;
    }

    $contents = @file_get_contents($dir . '/' . $topic . '.md');
    if ($contents === FALSE) {
      $this->setOutput('Playbook "' . $topic . '" could not be read.');
      return;
    }
    $this->setOutput($contents);
  }

}
