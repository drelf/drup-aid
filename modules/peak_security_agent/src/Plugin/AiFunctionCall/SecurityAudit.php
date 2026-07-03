<?php

declare(strict_types=1);

namespace Drupal\peak_security_agent\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\system\SystemManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executable tool: Drupal security-posture audit — assimilate, don't reinvent.
 *
 * Instead of re-implementing checks, this reads Drupal's OWN security surfaces
 * and re-presents them for a non-technical owner:
 *  - the Update Manager's security-release data (core + every module),
 *  - the Status Report (hook_requirements) warnings and errors,
 *  - and it defers the deep permission/text-format/file audit to the
 *    community-standard Security Review module (recommending it if absent).
 * For every item it adds a two-tier PRESCRIPTION: a plain-English fix for the
 * owner plus a technical drill-down for an admin. Read-only.
 */
#[FunctionCall(
  id: 'peak_security_agent:security_audit',
  function_name: 'peak_security_agent_security_audit',
  name: 'Security posture audit (Drupal)',
  description: 'Reads Drupal\'s own security surfaces — Available Updates (security releases for core + every module), the Status Report warnings/errors, and the Security Review module if present — and re-presents each item with a plain-English fix for the owner plus a technical drill-down for an admin. Read-only; it surfaces and prescribes, it does not change anything. Host-level checks (ports, TLS, headers, DNS auth) remain out of scope.',
  group: 'information_tools',
  context_definitions: [],
)]
final class SecurityAudit extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Status Report requirement keys handled in detail elsewhere (skip here).
   */
  protected const UPDATE_KEYS = ['update', 'update_core', 'update_contrib', 'update status'];

  /**
   * Plain-English lead-ins for well-known Status Report requirement keys.
   */
  protected const PLAIN_MAP = [
    'error_level' => 'Your site may be showing technical error details to visitors, which leaks information attackers can use. Set errors to hidden.',
    'trusted_host_patterns' => 'The list of web addresses your site trusts is not set, which can allow "host header" attacks. Set it in settings.php.',
    'php' => 'Your PHP version may be outdated — old PHP stops getting security fixes. Move to a supported version.',
    'configuration_files' => 'Your site\'s settings file can currently be modified. That is a serious risk — anyone who changes it can take over the site. Lock it to read-only.',
    'https' => 'Your site may not be enforcing a secure (HTTPS) connection. Serve everything over HTTPS.',
  ];

  /**
   * The system manager (provides the Status Report requirements).
   *
   * @var \Drupal\system\SystemManager
   */
  protected SystemManager $systemManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
    $instance->systemManager = $container->get('system.manager');
    $instance->moduleHandler = $container->get('module_handler');
    $instance->renderer = $container->get('renderer');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $findings = array_merge(
      $this->checkSecurityUpdates(),
      $this->checkStatusReport(),
      $this->checkAccountAndFormHardening(),
      $this->checkSecurityReview(),
    );

    $out = "Security posture audit — reads Drupal's own security surfaces (Available Updates, Status Report, Security Review).\n"
      . "(Host-level checks — ports, TLS, headers, SPF/DKIM/DMARC — run separately via the security-scan tool.)\n\n";

    if (!$findings) {
      $out .= "✓ Nothing flagged on Drupal's security surfaces. Good posture.";
      $this->setOutput($out);
      return;
    }

    $out .= count($findings) . " item(s) to review. Each has a plain-English fix and a technical drill-down.\n";
    foreach ($findings as $f) {
      $out .= "\n  • [" . $f['severity'] . '] ' . $f['issue']
        . "\n      Fix (plain English): " . $f['plain']
        . "\n      Drill down (technical): " . $f['tech'];
    }

    $this->setOutput($out);
  }

  /**
   * Known security releases on drupal.org for core + every installed module.
   *
   * Uses Drupal's Update Manager data (the /admin/reports/updates source).
   */
  protected function checkSecurityUpdates(): array {
    if (!$this->moduleHandler->moduleExists('update')) {
      return [$this->rx(
        'high',
        'The site is not watching drupal.org for security updates',
        'Your site is not checking for security updates, so you would not be warned about a known vulnerability. Turn update checking on.',
        'The core "Update Manager" (update) module is disabled. Enable it: `drush en update -y`.',
      )];
    }

    $this->moduleHandler->loadInclude('update', 'inc', 'update.compare');
    $available = function_exists('update_get_available') ? update_get_available(FALSE) : [];
    if (empty($available)) {
      return [$this->rx(
        'low',
        'Update status has not been fetched yet',
        'The site has not checked drupal.org for updates recently, so security status is unknown. Run the update check.',
        'No cached release data. Run `drush ups` (or let cron run), then re-audit. See /admin/reports/updates.',
      )];
    }

    $findings = [];
    foreach (update_calculate_project_data($available) as $name => $p) {
      $status = $p['status'] ?? NULL;
      $existing = (string) ($p['existing_version'] ?? '?');
      $label = (string) ($p['title'] ?? $name);
      $releases = 'https://www.drupal.org/project/' . $name . '/releases';

      if (defined('UPDATE_NOT_SECURE') && $status === UPDATE_NOT_SECURE) {
        $rec = (string) ($p['recommended'] ?? ($p['security updates'][0]['version'] ?? 'the latest secure release'));
        $findings[] = $this->rx(
          'critical',
          "{$label} {$existing} has a known security vulnerability",
          "A part of your site ({$label}) has a security update that fixes a known vulnerability. Update it as soon as possible — this is the highest-priority fix.",
          "{$name}: {$existing} → {$rec}. `composer require drupal/{$name}:{$rec}` then `drush updb -y`. Releases: {$releases}.",
        );
      }
      elseif (defined('UPDATE_REVOKED') && $status === UPDATE_REVOKED) {
        $findings[] = $this->rx(
          'critical',
          "{$label} {$existing} was revoked by its maintainers",
          "The version of {$label} you're running was pulled by its maintainers — often for a security problem. Move off it.",
          "{$name} {$existing} is REVOKED. Upgrade to a current release: {$releases}.",
        );
      }
      elseif (defined('UPDATE_NOT_SUPPORTED') && $status === UPDATE_NOT_SUPPORTED) {
        $findings[] = $this->rx(
          'high',
          "{$label} {$existing} is on an unsupported version",
          "{$label} is on a version that no longer receives security fixes. Move to a supported version.",
          "{$name} {$existing} is NOT SUPPORTED (no security coverage). Upgrade: {$releases}.",
        );
      }
    }
    return $findings;
  }

  /**
   * Read Drupal's Status Report (hook_requirements) — surface warnings/errors.
   *
   * Update items are skipped here (handled in detail by checkSecurityUpdates).
   */
  protected function checkStatusReport(): array {
    $findings = [];
    foreach ($this->systemManager->listRequirements() as $key => $req) {
      $severity = $this->severityInt($req['severity'] ?? 0);
      // Only WARNING (1) and ERROR (2).
      if ($severity < 1) {
        continue;
      }
      // Updates are handled in detail by checkSecurityUpdates().
      if (in_array(strtolower((string) $key), self::UPDATE_KEYS, TRUE)) {
        continue;
      }
      $title = trim($this->stringify($req['title'] ?? $key));
      $value = trim($this->stringify($req['value'] ?? ''));
      $desc = trim($this->stringify($req['description'] ?? ''));

      // Security-focused: keep ERRORs (real problems) always; keep WARNINGs only
      // when the item looks security-relevant. Skips ops/advisory noise like
      // transaction isolation level or HTML5-validation deprecation notes.
      $isError = $severity >= 2;
      if (!$isError && !$this->looksSecurityRelevant($key, $title, $desc)) {
        continue;
      }

      $plain = self::PLAIN_MAP[$key]
        ?? 'Drupal flagged this in your Status Report as needing attention: ' . $title . ($value !== '' ? ' (' . $value . ').' : '.');
      $tech = ($value !== '' ? $title . ': ' . $value . '. ' : $title . '. ')
        . ($desc !== '' ? $desc . ' ' : '')
        . 'See /admin/reports/status.';

      $findings[] = $this->rx(
        $isError ? 'high' : 'medium',
        $title . ($value !== '' ? ' — ' . $value : ''),
        $plain,
        $tech,
      );
    }
    return $findings;
  }

  /**
   * User-account and form hardening that Drupal does NOT surface itself.
   *
   * These are additive recommendations (not a duplicate of any Drupal report):
   * form-spam protection, brute-force/login protection, and a password policy.
   * Also flags when an excessive number of accounts hold full admin access.
   */
  protected function checkAccountAndFormHardening(): array {
    $findings = [];

    // Forms: spam protection.
    if (!$this->anyModule(['honeypot', 'antibot', 'captcha'])) {
      $findings[] = $this->rx(
        'medium',
        'No spam protection on your forms',
        'Your public forms (contact / lead form) have no spam protection, so expect bot submissions. Add a spam filter.',
        'No honeypot/antibot/captcha. `composer require drupal/honeypot` then `drush en honeypot -y`; configure at /admin/config/content/honeypot.',
      );
    }

    // Accounts: brute-force / login protection.
    if (!$this->anyModule(['flood_control', 'login_security', 'tfa'])) {
      $findings[] = $this->rx(
        'medium',
        'No brute-force / login protection on accounts',
        'There is no added defense against password-guessing attacks on your login. Add brute-force protection (and ideally two-factor login).',
        'No flood_control/login_security/tfa. `composer require drupal/login_security drupal/tfa` then enable them.',
      );
    }

    // Accounts: password policy.
    if (!$this->anyModule(['password_policy'])) {
      $findings[] = $this->rx(
        'low',
        'No password-strength policy on accounts',
        'Users can set weak passwords. Enforce a minimum strength so accounts are harder to break into.',
        'No password_policy. `composer require drupal/password_policy` then enable + configure a policy at /admin/config/security/password-policy.',
      );
    }

    // Accounts: too many full admins.
    $admins = $this->countAdminAccounts();
    if ($admins > 3) {
      $findings[] = $this->rx(
        'medium',
        "{$admins} accounts have full administrator access",
        "You have {$admins} accounts with full control of the site — every one is a way in if compromised. Keep admins to the few people who truly need it.",
        "{$admins} active users hold a role with 'is_admin'. Review at /admin/people (filter by the administrator role) and downgrade any that do not need it.",
      );
    }

    return $findings;
  }

  /**
   * TRUE if any of the given modules is installed.
   */
  protected function anyModule(array $modules): bool {
    foreach ($modules as $m) {
      if ($this->moduleHandler->moduleExists($m)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Count active user accounts that hold an admin (is_admin) role.
   */
  protected function countAdminAccounts(): int {
    try {
      $roleStorage = $this->entityTypeManager->getStorage('user_role');
      $adminRoles = [];
      foreach ($roleStorage->loadMultiple() as $rid => $role) {
        if ($role->isAdmin()) {
          $adminRoles[] = $rid;
        }
      }
      if (!$adminRoles) {
        return 0;
      }
      $query = $this->entityTypeManager->getStorage('user')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('roles', $adminRoles, 'IN');
      return (int) $query->count()->execute();
    }
    catch (\Throwable $e) {
      return 0;
    }
  }

  /**
   * Defer the deep audit to the community-standard Security Review module.
   */
  protected function checkSecurityReview(): array {
    if ($this->moduleHandler->moduleExists('security_review')) {
      return [$this->rx(
        'info',
        'Deep security audit available (Security Review is installed)',
        'The Security Review tool is installed — run it for a deep audit of file access, risky permissions, and unsafe content settings.',
        'Run it at /admin/reports/security-review (or `drush security-review`).',
      )];
    }
    return [$this->rx(
      'medium',
      'No deep security-audit tool installed',
      'For a thorough audit of file permissions, risky user permissions, and unsafe text formats, install the community-standard Security Review tool — it maintains those checks so we don\'t have to.',
      'Install it: `composer require drupal/security_review` then `drush en security_review -y`; review at /admin/reports/security-review.',
    )];
  }

  /**
   * Normalize a requirement severity to an int (Drupal 11.4 uses an enum).
   */
  protected function severityInt(mixed $severity): int {
    if ($severity instanceof \BackedEnum) {
      return (int) $severity->value;
    }
    return (int) $severity;
  }

  /**
   * Whether a Status Report warning looks security-relevant (vs ops/advisory).
   */
  protected function looksSecurityRelevant(string $key, string $title, string $desc): bool {
    $hay = strtolower($key . ' ' . $title . ' ' . $desc);
    $terms = [
      'secur', 'permission', 'writable', 'not protected', 'trusted host',
      'https', 'ssl', 'password hash', 'private file', 'expose', 'vulnerab',
      'phpinfo', 'error_level', 'error messages',
    ];
    foreach ($terms as $term) {
      if (str_contains($hay, $term)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Coerces a requirement value into a plain string.
   *
   * Accepts a plain string, TranslatableMarkup, or a render array.
   */
  protected function stringify(mixed $value): string {
    if ($value === NULL || $value === '') {
      return '';
    }
    if (is_array($value)) {
      try {
        $value = (string) $this->renderer->renderInIsolation($value);
      }
      catch (\Throwable $e) {
        return '';
      }
    }
    return trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));
  }

  /**
   * Build a two-tier prescription with a severity label.
   *
   * @return array{severity: string, issue: string, plain: string, tech: string}
   *   The prescription: severity, issue title, plain fix, technical fix.
   */
  protected function rx(string $severity, string $issue, string $plain, string $tech): array {
    return ['severity' => $severity, 'issue' => $issue, 'plain' => $plain, 'tech' => $tech];
  }

}
