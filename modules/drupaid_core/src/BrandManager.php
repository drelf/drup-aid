<?php

declare(strict_types=1);

namespace Drupal\drupaid_core;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Single source of truth for a tenant's brand identity.
 *
 * Every Drup-AID support minion reads its company name, support email/phone,
 * site URL, and agent display names from here instead of hardcoding them. This
 * is what makes one codebase serve any tenant: branding lives in
 * drupaid_core.settings (populated by setup-branding.php / tenant.yml), never in
 * a controller or a prompt string.
 */
class BrandManager {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Reads a brand setting, falling back to a default when unset.
   */
  protected function get(string $key, string $default = ''): string {
    $value = $this->configFactory->get('drupaid_core.settings')->get($key);
    return is_string($value) && $value !== '' ? $value : $default;
  }

  /**
   * The display brand name (e.g. "Acme Broadband").
   */
  public function brandName(): string {
    return $this->get('brand_name', 'this site');
  }

  /**
   * The legal company name, for prompts/signatures. Falls back to brand name.
   */
  public function companyName(): string {
    return $this->get('company_name', $this->brandName());
  }

  /**
   * The public support email address, or '' when none is configured.
   */
  public function supportEmail(): string {
    return $this->get('support_email');
  }

  /**
   * The public support phone number, or '' when none is configured.
   */
  public function supportPhone(): string {
    return $this->get('support_phone');
  }

  /**
   * The canonical public site URL (no trailing slash), or '' when unset.
   */
  public function siteUrl(): string {
    return rtrim($this->get('site_url'), '/');
  }

  /**
   * Display name for the conversational support agent.
   */
  public function supportAgentName(): string {
    return $this->get('support_agent_name', 'the support assistant');
  }

  /**
   * Display name for the conversational lead-qualification agent.
   */
  public function leadAgentName(): string {
    return $this->get('lead_agent_name', 'the assistant');
  }

  /**
   * Origins permitted to call public chat/API endpoints (CORS allow-list).
   *
   * @return string[]
   *   The list of allowed origins.
   */
  public function corsAllowedOrigins(): array {
    $origins = $this->configFactory->get('drupaid_core.settings')->get('cors_allowed_origins');
    return is_array($origins) ? array_values(array_filter($origins)) : [];
  }

  /**
   * A short, ready-to-embed brand context line for AI system prompts.
   *
   * Built only from non-empty fields so prompts never contain dangling
   * "email: " fragments on a freshly-installed, unbranded box.
   */
  public function promptContext(): string {
    $parts = ['You work for ' . $this->companyName() . '.'];
    if ($this->supportEmail() !== '') {
      $parts[] = 'Support email: ' . $this->supportEmail() . '.';
    }
    if ($this->supportPhone() !== '') {
      $parts[] = 'Support phone: ' . $this->supportPhone() . '.';
    }
    return implode(' ', $parts);
  }

}
