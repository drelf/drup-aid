<?php

namespace Drupal\drupaid_leads\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\drupaid_core\BrandManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin controller for viewing and managing lead submissions.
 */
class LeadAdminController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected BrandManager $brand,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('drupaid_core.brand'),
    );
  }

  /**
   * GET /admin/leads — list all leads.
   */
  public function list(): array {
    $header = [
      ['data' => 'ID', 'field' => 'id', 'sort' => 'desc'],
      ['data' => 'Name'],
      ['data' => 'Company'],
      ['data' => 'Email'],
      ['data' => 'Needs'],
      ['data' => 'Status'],
      ['data' => 'Access Code'],
      ['data' => 'Date', 'field' => 'created'],
      ['data' => 'Actions'],
    ];

    $query = $this->database->select('drupaid_leads', 'l')
      ->fields('l')
      ->extend('\Drupal\Core\Database\Query\TableSortExtender')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(25)
      ->orderByHeader($header);

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($results as $lead) {
      $actions = [];

      if ($lead['status'] === 'pending') {
        $actions[] = [
          '#type' => 'link',
          '#title' => 'Approve',
          '#url' => Url::fromRoute('drupaid_leads.approve', ['lead_id' => $lead['id']]),
          '#attributes' => ['class' => ['button', 'button--small', 'button--primary']],
        ];
        $actions[] = ['#markup' => ' '];
        $actions[] = [
          '#type' => 'link',
          '#title' => 'Reject',
          '#url' => Url::fromRoute('drupaid_leads.reject', ['lead_id' => $lead['id']]),
          '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
        ];
      }
      else {
        $actions[] = [
          '#markup' => '<span class="drupaid-lead-status drupaid-lead-status--' . htmlspecialchars($lead['status']) . '">' . htmlspecialchars(ucfirst($lead['status'])) . '</span>',
        ];
      }

      $rows[] = [
        $lead['id'],
        htmlspecialchars($lead['name'] ?? ''),
        htmlspecialchars($lead['company'] ?? ''),
        htmlspecialchars($lead['email'] ?? ''),
        htmlspecialchars(mb_strimwidth($lead['needs'] ?? '', 0, 80, '...')),
        [
          'data' => [
            '#markup' => '<span class="drupaid-lead-status drupaid-lead-status--' . htmlspecialchars($lead['status']) . '">' . htmlspecialchars(ucfirst($lead['status'])) . '</span>',
          ],
        ],
        htmlspecialchars($lead['access_code'] ?? '—'),
        date('M j, Y g:ia', (int) $lead['created']),
        ['data' => $actions],
      ];
    }

    return [
      '#attached' => ['library' => ['drupaid_leads/admin']],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => 'No leads yet. The front-page chat agent will capture leads here.',
        '#attributes' => ['class' => ['admin-leads-table']],
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * GET /admin/leads/approve/{lead_id}.
   *
   * Generates an access code, stores it, emails it to the lead, updates demo gate.
   */
  public function approve(Request $request, int $lead_id): RedirectResponse {
    $lead = $this->database->select('drupaid_leads', 'l')
      ->fields('l')
      ->condition('id', $lead_id)
      ->execute()
      ->fetchAssoc();

    if (!$lead) {
      $this->messenger()->addError('Lead not found.');
      return new RedirectResponse(Url::fromRoute('drupaid_leads.admin')->toString());
    }

    if ($lead['status'] === 'approved') {
      $this->messenger()->addWarning('This lead has already been approved.');
      return new RedirectResponse(Url::fromRoute('drupaid_leads.admin')->toString());
    }

    // Use the global demo access code from support config; if none is set,
    // mint a random one rather than a brand-specific literal.
    $chat_config = $this->config('drupaid_support.settings');
    $code = $chat_config->get('demo_access_code') ?: ('demo' . bin2hex(random_bytes(3)));

    $now = \Drupal::time()->getRequestTime();

    // Update lead record.
    $this->database->update('drupaid_leads')
      ->fields([
        'status' => 'approved',
        'access_code' => $code,
        'changed' => $now,
      ])
      ->condition('id', $lead_id)
      ->execute();

    // Build the direct demo link with the code embedded.
    $site_url = $this->brand->siteUrl();
    $company = $this->brand->companyName();
    $direct_link = $site_url . '/support?code=' . urlencode($code);

    // Email the access code to the lead.
    if (!empty($lead['email']) && filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
      // Strip newlines to prevent header injection.
      $name = str_replace(["\r", "\n"], '', $lead['name'] ?? 'there');
      $subject = 'Your ' . $company . ' Demo Is Ready';
      $body = "Hi {$name},\n\n";
      $body .= "Thanks for your interest in {$company}! Your demo has been approved.\n\n";
      $body .= "Click the link below to jump straight into our live AI support demo:\n\n";
      $body .= "{$direct_link}\n\n";
      $body .= "Or visit {$site_url}/support and enter access code: {$code}\n\n";
      $body .= "If you have any questions, just reply to this email.\n\n";
      $body .= "— The {$company} Team";

      try {
        \Drupal::service('plugin.manager.mail')->mail(
          'drupaid_leads',
          'lead_approved',
          $lead['email'],
          'en',
          ['subject' => $subject, 'body' => $body],
        );
        $this->messenger()->addStatus("Approved! Demo link emailed to {$lead['email']}.");
      }
      catch (\Exception $e) {
        $this->getLogger('drupaid_leads')->error('Failed to email lead: @err', ['@err' => $e->getMessage()]);
        $this->messenger()->addWarning("Approved, but email failed. Send this link manually to {$lead['email']}: {$direct_link}");
      }
    }
    else {
      $this->messenger()->addStatus("Approved (no email on file). Direct link: {$direct_link}");
    }

    return new RedirectResponse(Url::fromRoute('drupaid_leads.admin')->toString());
  }

  /**
   * GET /admin/leads/reject/{lead_id}.
   */
  public function reject(Request $request, int $lead_id): RedirectResponse {
    $lead = $this->database->select('drupaid_leads', 'l')
      ->fields('l', ['id', 'status', 'name'])
      ->condition('id', $lead_id)
      ->execute()
      ->fetchAssoc();

    if (!$lead) {
      $this->messenger()->addError('Lead not found.');
      return new RedirectResponse(Url::fromRoute('drupaid_leads.admin')->toString());
    }

    $now = \Drupal::time()->getRequestTime();

    $this->database->update('drupaid_leads')
      ->fields([
        'status' => 'rejected',
        'changed' => $now,
      ])
      ->condition('id', $lead_id)
      ->execute();

    $this->messenger()->addStatus("Lead #{$lead_id} ({$lead['name']}) has been rejected.");
    return new RedirectResponse(Url::fromRoute('drupaid_leads.admin')->toString());
  }

}
