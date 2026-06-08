<?php

/**
 * @file
 * Drup-AID — point the site at your cloud LLM ("the one config step").
 *
 * Run after applying the recipe:
 *   ANTHROPIC_API_KEY=sk-ant-... drush php:script scripts/setup-cloud-provider.php
 *
 * It (1) stores the key in a Key entity, (2) points ai_provider_anthropic at it,
 * (3) sets the default chat + chat_with_tools providers so the minions just work.
 * The key is read from the ENVIRONMENT and never written to committed config.
 *
 * Optional env overrides:
 *   DRUPAID_MODEL  (default: claude-haiku-4-5-20251001 — cheap + strong tool-use)
 */

$key = getenv('ANTHROPIC_API_KEY');
if (!$key) {
  echo "ERROR: set ANTHROPIC_API_KEY in the environment before running this script.\n";
  echo "Example: ANTHROPIC_API_KEY=sk-ant-... drush php:script scripts/setup-cloud-provider.php\n";
  return;
}
$model = getenv('DRUPAID_MODEL') ?: 'claude-haiku-4-5-20251001';

// 1. Store the key in a Key entity (config provider).
$storage = \Drupal::entityTypeManager()->getStorage('key');
if (!$storage->load('anthropic_api')) {
  $storage->create([
    'id' => 'anthropic_api',
    'label' => 'Anthropic API Key',
    'key_type' => 'authentication',
    'key_provider' => 'config',
    'key_provider_settings' => ['key_value' => trim($key)],
    'key_input' => 'text_field',
  ])->save();
  echo "✓ Key entity 'anthropic_api' created (length=" . strlen(trim($key)) . ").\n";
}
else {
  echo "✓ Key entity 'anthropic_api' already exists.\n";
}

// 2. Point the Anthropic provider at the key (and set a valid API version).
\Drupal::configFactory()->getEditable('ai_provider_anthropic.settings')
  ->set('api_key', 'anthropic_api')
  ->set('version', '2023-06-01')
  ->save();
echo "✓ ai_provider_anthropic configured (version 2023-06-01).\n";

// 3. Set the default providers the minions use.
$cfg = \Drupal::configFactory()->getEditable('ai.settings');
foreach (['chat', 'chat_with_tools'] as $op) {
  $cfg->set("default_providers.$op", [
    'provider_id' => 'anthropic',
    'model_id' => $model,
  ]);
}
$cfg->save();
echo "✓ Default chat + chat_with_tools => anthropic / $model\n";
echo "\nDone. Drup-AID is wired to the cloud brain — start chatting to build/edit your site.\n";
