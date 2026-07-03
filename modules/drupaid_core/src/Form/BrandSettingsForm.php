<?php

declare(strict_types=1);

namespace Drupal\drupaid_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Tenant identity + AI-brain settings for the Drup-AID support suite.
 */
class BrandSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupaid_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupaid_core_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupaid_core.settings');

    $form['brand'] = [
      '#type' => 'details',
      '#title' => $this->t('Brand identity'),
      '#open' => TRUE,
      '#description' => $this->t('Every Drup-AID support minion reads these values. Set them once here (or via the install branding script / tenant.yml) and no module hardcodes them.'),
    ];
    $form['brand']['brand_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Brand name'),
      '#default_value' => $config->get('brand_name'),
      '#required' => TRUE,
    ];
    $form['brand']['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Legal company name'),
      '#default_value' => $config->get('company_name'),
      '#description' => $this->t('Used in AI prompts and email signatures. Defaults to the brand name when empty.'),
    ];
    $form['brand']['support_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Support email address'),
      '#default_value' => $config->get('support_email'),
    ];
    $form['brand']['support_phone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Support phone number'),
      '#default_value' => $config->get('support_phone'),
    ];
    $form['brand']['site_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public site URL'),
      '#default_value' => $config->get('site_url'),
      '#description' => $this->t('No trailing slash, e.g. https://example.com'),
    ];
    $form['brand']['support_agent_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Support agent display name'),
      '#default_value' => $config->get('support_agent_name'),
    ];
    $form['brand']['lead_agent_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Lead agent display name'),
      '#default_value' => $config->get('lead_agent_name'),
    ];

    $form['brain'] = [
      '#type' => 'details',
      '#title' => $this->t('AI brain'),
      '#open' => FALSE,
      '#description' => $this->t('By default, conversational turns are answered by the Drupal AI module default chat provider (cloud-first — no n8n required). Enable n8n only if you want support reasoning to run through an n8n workflow; the cloud provider stays as the automatic fallback.'),
    ];
    $form['brain']['n8n_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Route AI reasoning through an n8n workflow'),
      '#default_value' => (bool) $config->get('n8n_enabled'),
    ];
    $form['brain']['n8n_webhook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('n8n webhook URL'),
      '#default_value' => $config->get('n8n_webhook_url'),
      '#states' => [
        'visible' => [':input[name="n8n_enabled"]' => ['checked' => TRUE]],
      ],
    ];
    $form['brain']['n8n_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('n8n API key'),
      '#default_value' => $config->get('n8n_api_key'),
      '#states' => [
        'visible' => [':input[name="n8n_enabled"]' => ['checked' => TRUE]],
      ],
    ];

    $form['cors'] = [
      '#type' => 'details',
      '#title' => $this->t('CORS allowed origins'),
      '#open' => FALSE,
    ];
    $form['cors']['cors_allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed origins'),
      '#default_value' => implode("\n", $config->get('cors_allowed_origins') ?: []),
      '#description' => $this->t('One origin per line, e.g. https://example.com. Leave empty for same-origin only.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $origins = array_values(array_filter(array_map(
      'trim',
      preg_split('/\r\n|\r|\n/', (string) $form_state->getValue('cors_allowed_origins'))
    )));

    $this->config('drupaid_core.settings')
      ->set('brand_name', $form_state->getValue('brand_name'))
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('support_email', $form_state->getValue('support_email'))
      ->set('support_phone', $form_state->getValue('support_phone'))
      ->set('site_url', rtrim((string) $form_state->getValue('site_url'), '/'))
      ->set('support_agent_name', $form_state->getValue('support_agent_name'))
      ->set('lead_agent_name', $form_state->getValue('lead_agent_name'))
      ->set('n8n_enabled', (bool) $form_state->getValue('n8n_enabled'))
      ->set('n8n_webhook_url', $form_state->getValue('n8n_webhook_url'))
      ->set('n8n_api_key', $form_state->getValue('n8n_api_key'))
      ->set('cors_allowed_origins', $origins)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
