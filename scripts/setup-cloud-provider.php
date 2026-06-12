<?php

/**
 * @file
 * Drup-AID — point the site at your cloud LLM ("the one config step").
 *
 * Run after applying the recipe:
 *   ANTHROPIC_API_KEY=sk-ant-... drush php:script scripts/setup-cloud-provider.php
 *
 * It (1) registers the key via the Key module ENV provider, (2) points
 * ai_provider_anthropic at it, (3) sets the default chat + chat_with_tools
 * providers so the minions just work. The secret value is read from the
 * environment at runtime and is NEVER stored in the database or a config export
 * — only the environment variable NAME is stored.
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

// 1. Register the key via the Key module ENVIRONMENT provider. Only the
//    variable NAME is stored in config; the secret value is read from the
//    environment at runtime and never lands in the database or a config export.
//    Load-or-create so re-running converts any prior config-provider key.
$storage = \Drupal::entityTypeManager()->getStorage('key');
$entity = $storage->load('anthropic_api') ?: $storage->create(['id' => 'anthropic_api']);
$entity->set('label', 'Anthropic API Key');
$entity->set('key_type', 'authentication');
$entity->set('key_provider', 'env');
$entity->set('key_provider_settings', [
  'env_variable' => 'ANTHROPIC_API_KEY',
  'base64_encoded' => FALSE,
]);
$entity->set('key_input', 'none');
$entity->set('key_input_settings', []);
$entity->save();
echo "✓ Key 'anthropic_api' registered via the ENV provider (ANTHROPIC_API_KEY).\n";
echo "  The secret value is NOT stored in config or the database.\n";
echo "  IMPORTANT: ANTHROPIC_API_KEY must be set in the WEB SERVER environment at\n";
echo "  runtime (php-fpm pool / vhost / container env), not just this CLI shell, or\n";
echo "  the key resolves empty for web requests. On hosts where runtime env vars are\n";
echo "  awkward, use the Key 'file' provider (value in a file outside the web root).\n";

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
// 4. Open the door: the moment the key lands, the user wants to TALK.
// Make sure the explorer surface exists, then hand them a one-time login
// link that drops them straight into a conversation with the master agent.
if (!\Drupal::moduleHandler()->moduleExists('ai_agents_explorer')) {
  \Drupal::service('module_installer')->install(['ai_agents_explorer']);
  echo "+ Enabled the AI Agent Explorer (your instant chat surface).\n";
}
$account = \Drupal\user\Entity\User::load(1);
$door = '';
if ($account) {
  $door = user_pass_reset_url($account) . '/login?destination=' . rawurlencode('/admin/config/ai/agents/explore');
}
echo "\n==============================================================\n";
echo "Done. Drup-AID is wired to the cloud brain.\n\n";
if ($door) {
  echo "TALK TO YOUR AGENTS RIGHT NOW - one-time login link:\n\n  $door\n\n";
  echo "Pick an agent (try the master), type what you want done, run it,\n";
  echo "and watch the decision log as your team works.\n\n";
}
echo "Also: open any page in Canvas and use the AI panel to build by chat.\n";
echo "All the ways to talk to your agents: USING.md\n";
echo "==============================================================\n";
