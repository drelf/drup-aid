<?php

declare(strict_types=1);

namespace Drupal\drupaid_leads\Plugin\AiFunctionCall;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executable tool: list recent captured leads.
 *
 * Lets the Master Agent answer "show me this week's leads" / "how many new
 * leads do we have?" Read-only.
 */
#[FunctionCall(
  id: 'drupaid_leads:list_recent',
  function_name: 'drupaid_leads_list_recent',
  name: 'List Recent Leads',
  description: 'Returns the most recent captured leads (name, phone, email, status). Read-only.',
  group: 'information_tools',
  context_definitions: [
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('How many recent leads to return (default 10, max 50).'),
      required: FALSE,
    ),
    'status' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Status'),
      description: new TranslatableMarkup('Optional status filter: pending, approved, or rejected.'),
      required: FALSE,
    ),
  ],
)]
final class ListRecentLeads extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

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
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $limit = (int) ($this->getContextValue('limit') ?: 10);
    $limit = max(1, min($limit, 50));
    $status = (string) ($this->getContextValue('status') ?: '');

    $query = $this->database->select('drupaid_leads', 'l')
      ->fields('l', ['name', 'phone', 'email', 'status', 'created'])
      ->orderBy('created', 'DESC')
      ->range(0, $limit);
    if (in_array($status, ['pending', 'approved', 'rejected'], TRUE)) {
      $query->condition('status', $status);
    }
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
      $this->setOutput('No leads found' . ($status !== '' ? ' with status "' . $status . '"' : '') . '.');
      return;
    }

    $lines = [];
    foreach ($rows as $r) {
      $lines[] = sprintf(
        '- %s | phone: %s | email: %s — %s, %s',
        $r['name'] ?: 'Unknown',
        $r['phone'] ?: '—',
        $r['email'] ?: '—',
        $r['status'],
        date('Y-m-d', (int) $r['created']),
      );
    }
    $this->setOutput(count($rows) . " recent lead(s):\n" . implode("\n", $lines));
  }

}
