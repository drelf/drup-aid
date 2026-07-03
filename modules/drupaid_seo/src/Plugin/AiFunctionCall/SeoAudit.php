<?php

declare(strict_types=1);

namespace Drupal\drupaid_seo\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executable tool: audit one URL for technical SEO + AI-search (GEO) signals.
 *
 * Deterministic (no LLM). Each issue found is returned as a two-tier
 * PRESCRIPTION: a plain-English fix for the site owner, plus a technical
 * drill-down (exact code / config path / drush command) for an admin. It
 * diagnoses AND prescribes the fix — it does not change anything (read-only).
 */
#[FunctionCall(
  id: 'drupaid_seo:audit_url',
  function_name: 'drupaid_seo_audit_url',
  name: 'Audit a URL (technical SEO + GEO)',
  description: 'Fetches a single URL and reports technical SEO (title, meta description, H1, canonical, indexability, structured data, HTTP status/redirects) plus AI-search / GEO signals. For every issue it returns a plain-English fix for the owner AND a technical drill-down (exact code, config path, or drush command) for an admin. Read-only — it prescribes fixes, it does not apply them.',
  group: 'information_tools',
  context_definitions: [
    'url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('URL'),
      description: new TranslatableMarkup('The full URL to audit, e.g. https://example.com/page.'),
      required: TRUE,
    ),
  ],
)]
final class SeoAudit extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

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
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $url = trim((string) $this->getContextValue('url'));
    if ($url === '') {
      $this->setOutput('Error: no URL provided.');
      return;
    }
    if (!preg_match('#^https?://#i', $url)) {
      $url = 'https://' . $url;
    }

    try {
      $res = $this->httpClient->request('GET', $url, [
        'http_errors' => FALSE,
        'allow_redirects' => ['track_redirects' => TRUE, 'max' => 6],
        'timeout' => 15,
        'headers' => ['User-Agent' => 'PeakAI-SEO-Agent/1.0 (+seo-audit)'],
      ]);
    }
    catch (\Throwable $e) {
      $this->setOutput('Could not fetch ' . $url . ': ' . $e->getMessage());
      return;
    }

    $status = $res->getStatusCode();
    $history = $res->getHeader('X-Guzzle-Redirect-History');
    $finalUrl = $history ? (string) end($history) : $url;
    $xRobots = strtolower(implode(' ', $res->getHeader('X-Robots-Tag')));
    $html = (string) $res->getBody();

    // --- Technical / on-page parse ---
    $title = $this->firstMatch('#<title[^>]*>([\s\S]*?)</title>#i', $html);
    $description = $this->firstMatch('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html);
    preg_match_all('#<h1[^>]*>([\s\S]*?)</h1>#i', $html, $h1m);
    $h1Count = count($h1m[1] ?? []);
    $canonical = $this->firstMatch('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)#i', $html);
    $metaRobots = strtolower((string) $this->firstMatch('#<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)#i', $html));
    $noindex = (str_contains($metaRobots, 'noindex') || str_contains($xRobots, 'noindex'));

    // Structured data (JSON-LD @type collection).
    $schemaTypes = [];
    if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>([\s\S]*?)</script>#i', $html, $sm)) {
      foreach ($sm[1] as $block) {
        $data = json_decode(trim($block), TRUE);
        if (is_array($data)) {
          $this->collectTypes($data, $schemaTypes);
        }
      }
    }
    $schemaTypes = array_values(array_unique($schemaTypes));

    // Body text + word count.
    $text = preg_replace('#<(script|style)[\s\S]*?</\1>#i', ' ', $html);
    $text = trim(preg_replace('#\s+#', ' ', strip_tags((string) $text)));
    $wordCount = $text === '' ? 0 : count(explode(' ', $text));

    // --- GEO / AI-search signals ---
    $statHits = preg_match_all('#\d+(?:\.\d+)?\s?%|\$\s?\d|\b\d{2,}(?:[,\.]\d+)?\s?(?:percent|million|billion|users|customers|companies)\b#i', $text);
    $citationHits = preg_match_all('#according to|research (?:shows|found)|study|source:|<blockquote#i', $html);
    $hasPersonAuthor = (bool) preg_match('#"@type"\s*:\s*"Person"#i', $html) || str_contains($metaRobots, 'author');
    $hasDateModified = (bool) preg_match('#"dateModified"#i', $html) || (bool) preg_match('#last updated#i', $text);
    $jsRisk = ($wordCount < 200) && (bool) preg_match('#id=["\'](root|app)["\']|data-reactroot|__NEXT_DATA__|ng-version#i', $html);

    // --- Prescriptions (each: issue + plain-English fix + technical drill-down) ---
    $tech = [];
    $onpage = [];
    $geo = [];

    if ($status >= 400 || $status === 0) {
      $tech[] = $this->rx(
        "Page returns HTTP {$status}",
        'The page is not loading properly for visitors or search engines. Nothing else matters until this is fixed.',
        "HTTP {$status} at {$finalUrl}. Check the web server and Drupal logs (drush watchdog:show), confirm the route resolves and the node is published.",
      );
    }
    if ($finalUrl !== $url) {
      $tech[] = $this->rx(
        'The address forwards to a different one',
        "This link forwards to {$finalUrl}. It works, but forwarding wastes a little search-engine effort and can slightly dilute ranking.",
        "Redirect chain ends at {$finalUrl}. Point internal links at the final URL to save crawl budget; make sure it is a 301 (permanent), not 302.",
      );
    }
    if ($noindex) {
      $tech[] = $this->rx(
        'This page is hidden from search engines',
        'The page is currently telling Google "do not list me." If you want people to find it, this has to be turned off.',
        "meta robots / X-Robots-Tag contains 'noindex'. Remove it at /admin/config/search/metatag (or the page's meta tags) if this page should be indexable.",
      );
    }
    if ($canonical && $this->normalizeUrl($canonical) !== $this->normalizeUrl($finalUrl)) {
      $tech[] = $this->rx(
        'This page points search engines to a different page',
        "It tells Google the \"real\" version is {$canonical}, so this page may not rank on its own.",
        "rel=canonical -> {$canonical}, differs from the final URL. If this page should stand on its own, set canonical to self in Metatag (/admin/config/search/metatag).",
      );
    }

    if (!$title) {
      $onpage[] = $this->rx(
        'Missing page title',
        'This page has no title — that is the clickable blue headline in Google results. Add one, keyword first.',
        'No <title>. Add a 50–60 char title via the node title or the Metatag "Page title" token at /admin/config/search/metatag.',
      );
    }
    elseif (mb_strlen($title) > 65) {
      $onpage[] = $this->rx(
        'Title is too long (' . mb_strlen($title) . ' characters)',
        'Google will cut your title off in results. Trim it to about 55 characters with the most important words first.',
        '<title> is ' . mb_strlen($title) . ' chars — trim toward 50–60. Edit the node title or the Metatag title pattern.',
      );
    }
    if (!$description) {
      $onpage[] = $this->rx(
        'Missing meta description',
        'There is no summary line under your title in search results, so Google writes its own — often badly. Add a 1–2 sentence description.',
        'No meta description. Add 150–160 chars via Metatag (default or per-page) at /admin/config/search/metatag.',
      );
    }
    if ($h1Count === 0) {
      $onpage[] = $this->rx(
        'No main heading on the page',
        'The page has no main headline for readers or search engines to anchor on. Add one with your key phrase.',
        'Zero <h1>. Ensure the theme outputs exactly one <h1> (usually the page/node title) in the page/node template.',
      );
    }
    elseif ($h1Count > 1) {
      $onpage[] = $this->rx(
        "Too many main headings ({$h1Count})",
        'The page has several "main" headlines, which confuses search engines about what matters most. Use exactly one.',
        "{$h1Count} <h1> tags — demote the extras to <h2>. Usually a theme or block-markup issue; audit the regions/blocks.",
      );
    }

    if (empty($schemaTypes)) {
      $geo[] = $this->rx(
        'No structured data (schema)',
        'Google and AI assistants cannot tell what this page IS in a structured way, so you are less likely to appear in rich results and AI answers. Add "article" data with an author and date.',
        'No JSON-LD. Add: {"@context":"https://schema.org","@type":"Article","headline":"…","author":{"@type":"Person","name":"…"},"datePublished":"…"}. In Drupal, schema_metatag is installed — configure at /admin/config/search/metatag or `drush en schema_metatag_article -y`.',
      );
    }
    else {
      if (count($schemaTypes) < 3) {
        $geo[] = $this->rx(
          'Only ' . count($schemaTypes) . ' type of structured data',
          'You have some structured data (' . implode(', ', $schemaTypes) . '), but adding a couple more types makes AI engines ~13% more likely to cite you.',
          'Add BreadcrumbList, FAQPage, and Organization via the schema_metatag submodules at /admin/config/search/metatag.',
        );
      }
      if (!$hasPersonAuthor) {
        $geo[] = $this->rx(
          'No author identified',
          'AI engines trust content more when they know who wrote it. Add yourself or your business as the author.',
          'Add author as a Person entity in the Article JSON-LD: "author":{"@type":"Person","name":"…"} — not a plain string.',
        );
      }
    }
    if ($statHits < 1) {
      $geo[] = $this->rx(
        'Few or no statistics',
        'Pages with concrete numbers get cited more often by AI. Add a few real data points, each with a source.',
        'Add verifiable stats (%, $, counts) with citations. "Statistics Addition" is shown to lift AI visibility up to ~40%.',
      );
    }
    if ($citationHits < 1) {
      $geo[] = $this->rx(
        'No citations or quotes',
        'Quoting sources ("according to…") makes AI assistants more likely to trust and cite your page. Add a couple.',
        'Add "according to…" sourcing and authoritative <blockquote> quotes with attribution.',
      );
    }
    if (!$hasDateModified) {
      $geo[] = $this->rx(
        'No "last updated" date shown',
        'AI and search engines favor recent content. Show a visible "last updated" date and refresh the page now and then.',
        'Surface dateModified in the schema plus a visible "Last updated" line in the template. Refresh quarterly.',
      );
    }
    if ($jsRisk) {
      $geo[] = $this->rx(
        'Your content may be invisible to AI crawlers',
        'Your main content appears to load with JavaScript, which AI crawlers (ChatGPT, Claude, Perplexity) do not run — so they may see a blank page. Serve the important text as plain HTML.',
        'Low static word count + JS-framework markers detected. Server-render (SSR/prerender) the key content so GPTBot/ClaudeBot/PerplexityBot can read it.',
      );
    }

    // --- Format: plain English first, technical drill-down beneath ---
    $fmt = function (array $items): string {
      if (!$items) {
        return "\n  ✓ none — looks good.";
      }
      $out = '';
      foreach ($items as $f) {
        $out .= "\n  • " . $f['issue']
          . "\n      Fix (plain English): " . $f['plain']
          . "\n      Drill down (technical): " . $f['tech'];
      }
      return $out;
    };

    $out = "SEO audit of {$finalUrl}\n"
      . "HTTP {$status}" . ($finalUrl !== $url ? " (redirected from {$url})" : '') . "\n"
      . 'Title: ' . ($title ?: '(missing)') . "\n"
      . 'Meta description: ' . ($description ? 'present' : '(missing)') . "\n"
      . "H1 count: {$h1Count} | Words: {$wordCount} | Indexable: " . ($noindex ? 'NO (noindex)' : 'yes') . "\n"
      . 'Schema types: ' . ($schemaTypes ? implode(', ', $schemaTypes) : '(none)') . "\n\n"
      . "Each issue below has a plain-English fix for the owner and a technical drill-down for an admin.\n"
      . "\nTECHNICAL:" . $fmt($tech)
      . "\n\nON-PAGE:" . $fmt($onpage)
      . "\n\nAI-SEARCH (GEO):" . $fmt($geo);

    $this->setOutput($out);
  }

  /**
   * Build a two-tier prescription: issue + plain-English fix + technical fix.
   *
   * @return array{issue: string, plain: string, tech: string}
   *   The prescription: issue title, plain-English fix, technical fix.
   */
  protected function rx(string $issue, string $plain, string $tech): array {
    return ['issue' => $issue, 'plain' => $plain, 'tech' => $tech];
  }

  /**
   * First capture group of a regex against a haystack, or NULL.
   */
  protected function firstMatch(string $pattern, string $haystack): ?string {
    return preg_match($pattern, $haystack, $m) ? trim(html_entity_decode($m[1])) : NULL;
  }

  /**
   * Recursively collect schema.org @type values.
   */
  protected function collectTypes(mixed $node, array &$out): void {
    if (!is_array($node)) {
      return;
    }
    if (isset($node['@type'])) {
      foreach ((array) $node['@type'] as $t) {
        $out[] = (string) $t;
      }
    }
    if (isset($node['@graph'])) {
      $this->collectTypes($node['@graph'], $out);
    }
    foreach ($node as $v) {
      if (is_array($v)) {
        $this->collectTypes($v, $out);
      }
    }
  }

  /**
   * Normalize a URL for comparison (drop fragment + trailing slash).
   */
  protected function normalizeUrl(string $url): string {
    $url = preg_replace('/#.*$/', '', $url);
    return rtrim((string) $url, '/');
  }

}
