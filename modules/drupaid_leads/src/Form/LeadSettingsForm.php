<?php

namespace Drupal\drupaid_leads\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the Lead Agent.
 */
class LeadSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupaid_leads.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupaid_leads_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupaid_leads.settings');

    $form['brain_note'] = [
      '#type' => 'item',
      '#markup' => $this->t('The AI brain and the lead agent name come from the central <a href=":url">Administer Drup-AID</a> settings. This page only configures lead handling.', [':url' => '/admin/config/drupaid']),
    ];

    $form['notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Lead notification email'),
      '#description' => $this->t('Email address to receive new lead notifications.'),
      '#default_value' => $config->get('notification_email'),
    ];

    $form['agent_greeting'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Agent greeting message'),
      '#description' => $this->t('First message shown when the chat widget opens.'),
      '#default_value' => $config->get('agent_greeting'),
      '#rows' => 3,
    ];

    $form['rate_limit_max'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit — max sessions per window'),
      '#default_value' => $config->get('rate_limit_max') ?? 30,
      '#min' => 1,
    ];

    $form['rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit — window (seconds)'),
      '#default_value' => $config->get('rate_limit_window') ?? 3600,
      '#min' => 60,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('drupaid_leads.settings')
      ->set('notification_email', $form_state->getValue('notification_email'))
      ->set('agent_greeting', $form_state->getValue('agent_greeting'))
      ->set('rate_limit_max', (int) $form_state->getValue('rate_limit_max'))
      ->set('rate_limit_window', (int) $form_state->getValue('rate_limit_window'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
