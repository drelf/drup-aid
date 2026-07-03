<?php

namespace Drupal\drupaid_support\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the Drup-AID support desk.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupaid_support.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupaid_support_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupaid_support.settings');

    $form['brain_note'] = [
      '#type' => 'item',
      '#markup' => $this->t('The AI brain (cloud provider or optional n8n workflow), the support agent name, and brand identity are configured centrally at <a href=":url">Administer Drup-AID</a>. This page only sets support-desk behaviour.', [':url' => '/admin/config/drupaid']),
    ];

    $form['ticket_auto_create'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-create support ticket nodes'),
      '#description' => $this->t('When the AI determines a ticket should be created, automatically create a Drupal node.'),
      '#default_value' => $config->get('ticket_auto_create') ?? TRUE,
    ];

    $form['rate_limit_max'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit — max sessions per window'),
      '#default_value' => $config->get('rate_limit_max') ?? 20,
      '#min' => 1,
    ];

    $form['rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit — window (seconds)'),
      '#default_value' => $config->get('rate_limit_window') ?? 3600,
      '#min' => 60,
    ];

    $form['fallback_response'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Fallback response (when the AI brain is unreachable)'),
      '#default_value' => $config->get('fallback_response'),
      '#rows' => 3,
    ];

    $form['session_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Session retention (days)'),
      '#description' => $this->t('Chat sessions, messages, and AI traces older than this are removed on cron.'),
      '#default_value' => $config->get('session_retention_days') ?? 30,
      '#min' => 1,
    ];

    $form['demo_gate'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Demo Access Gate'),
    ];

    $form['demo_gate']['demo_gate_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable demo access code'),
      '#description' => $this->t('When enabled, visitors must enter an access code before using the live chat.'),
      '#default_value' => $config->get('demo_gate_enabled') ?? FALSE,
    ];

    $form['demo_gate']['demo_access_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Demo access code'),
      '#description' => $this->t('The code visitors must enter. Share this with prospects for demo access.'),
      '#default_value' => $config->get('demo_access_code'),
      '#maxlength' => 64,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('drupaid_support.settings')
      ->set('ticket_auto_create', (bool) $form_state->getValue('ticket_auto_create'))
      ->set('rate_limit_max', (int) $form_state->getValue('rate_limit_max'))
      ->set('rate_limit_window', (int) $form_state->getValue('rate_limit_window'))
      ->set('fallback_response', $form_state->getValue('fallback_response'))
      ->set('session_retention_days', (int) $form_state->getValue('session_retention_days'))
      ->set('demo_gate_enabled', (bool) $form_state->getValue('demo_gate_enabled'))
      ->set('demo_access_code', trim($form_state->getValue('demo_access_code') ?? ''))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
