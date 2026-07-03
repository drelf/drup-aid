<?php

declare(strict_types=1);

namespace Drupal\drupaid_concierge\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executable tool: a cordial local status report — time, weather, and news.
 *
 * Deterministic (no LLM). Location is NOT hardcoded: the local timezone comes
 * from the site's own configuration (system.date, set at install), and the
 * weather city is derived from that timezone (America/Denver -> "Denver"), so a
 * fresh install is correct out of the box with zero extra setup. An explicit
 * "location" argument overrides the derived city. Every external call degrades
 * gracefully — a failed fetch reports "unavailable", never an error.
 */
#[FunctionCall(
  id: 'drupaid_concierge:local_status',
  function_name: 'drupaid_concierge_local_status',
  name: 'Local status report (time, weather, news)',
  description: 'Returns a friendly local status snapshot: the current local date and time (from the site timezone), current weather for the site\'s city, and the top news headlines. Use this whenever the owner greets you, says hi, or asks for a status / briefing / "what\'s going on".',
  group: 'information_tools',
  context_definitions: [
    'location' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Location'),
      description: new TranslatableMarkup('Optional city to report weather for, e.g. "Denver". Leave empty to use the site\'s configured location.'),
      required: FALSE,
    ),
  ],
)]
final class LocalStatus extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Fallback timezone when the site has none configured.
   */
  protected const FALLBACK_TIMEZONE = 'UTC';

  /**
   * Timezone leaf segments that are not usable cities for weather.
   */
  protected const NON_CITY_ZONES = ['UTC', 'GMT', 'Universal', 'Zulu'];

  /**
   * Top-headlines news feed (NPR top stories).
   */
  protected const NEWS_FEED = 'https://feeds.npr.org/1001/rss.xml';

  /**
   * How many headlines to include.
   */
  protected const HEADLINE_LIMIT = 3;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

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
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $timezone = $this->siteTimezone();
    $explicit = trim((string) $this->getContextValue('location'));
    // Explicit argument wins; otherwise derive the city from the site timezone
    // and disambiguate with the site's default country (e.g. "Denver,US").
    if ($explicit !== '') {
      $location = $explicit;
      $query = $explicit;
    }
    else {
      $location = $this->cityFromTimezone($timezone);
      $country = $this->siteCountry();
      $query = ($location !== '' && $country !== '') ? $location . ',' . $country : $location;
    }

    $time = $this->localTime($timezone);
    $weather = $query !== '' ? $this->weather($query) : 'set a site timezone to enable local weather';
    $headlines = $this->headlines();

    $label = $location !== '' ? $location : 'your site';
    $out = "Local status for {$label}:\n"
      . "- Time: {$time}\n"
      . "- Weather: {$weather}\n"
      . '- Top headlines:';
    if ($headlines) {
      foreach ($headlines as $headline) {
        $out .= "\n    - {$headline}";
      }
    }
    else {
      $out .= ' (news feed unavailable right now)';
    }

    $this->setOutput($out);
  }

  /**
   * The site's configured default timezone (from install), or a fallback.
   */
  protected function siteTimezone(): string {
    $tz = (string) $this->configFactory->get('system.date')->get('timezone.default');
    if ($tz === '') {
      $tz = date_default_timezone_get() ?: self::FALLBACK_TIMEZONE;
    }
    return $tz;
  }

  /**
   * The site's configured default country code (Regional settings), or ''.
   *
   * A two-letter code (e.g. "US") used to disambiguate the weather city.
   */
  protected function siteCountry(): string {
    return (string) $this->configFactory->get('system.date')->get('country.default');
  }

  /**
   * Derive a weather-usable city from a timezone id, or '' if not derivable.
   *
   * "America/Denver" -> "Denver", "Europe/Paris" -> "Paris",
   * "America/Argentina/Buenos_Aires" -> "Buenos Aires". Region-only or
   * non-city zones (UTC, GMT) return ''.
   */
  protected function cityFromTimezone(string $timezone): string {
    if (!str_contains($timezone, '/')) {
      return '';
    }
    $parts = explode('/', $timezone);
    $leaf = end($parts);
    if (in_array($leaf, self::NON_CITY_ZONES, TRUE)) {
      return '';
    }
    return str_replace('_', ' ', $leaf);
  }

  /**
   * Current local date and time as a friendly string.
   */
  protected function localTime(string $timezone): string {
    try {
      $tz = new \DateTimeZone($timezone);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone(self::FALLBACK_TIMEZONE);
    }
    return (new \DateTime('now', $tz))->format('l, F j, Y — g:i A T');
  }

  /**
   * Current conditions from wttr.in, or "unavailable" on any failure.
   *
   * The wttr.in service returns a plain-text one-liner only for a curl-like
   * User-Agent.
   */
  protected function weather(string $location): string {
    try {
      $res = $this->httpClient->request('GET', 'https://wttr.in/' . rawurlencode($location), [
        'query' => ['format' => '%C, %t (feels %f), wind %w, humidity %h'],
        'headers' => ['User-Agent' => 'curl/8.0'],
        'timeout' => 8,
        'http_errors' => FALSE,
      ]);
      if ($res->getStatusCode() === 200) {
        $line = trim((string) $res->getBody());
        // Guard against wttr.in returning an HTML/error body.
        if ($line !== '' && !str_contains($line, '<') && mb_strlen($line) < 200) {
          return $line;
        }
      }
    }
    catch (\Throwable $e) {
      // Fall through to unavailable.
    }
    return 'unavailable';
  }

  /**
   * Top news headlines from the RSS feed, newest first.
   *
   * @return string[]
   *   Up to HEADLINE_LIMIT headline strings (may be empty).
   */
  protected function headlines(): array {
    $headlines = [];
    try {
      $res = $this->httpClient->request('GET', self::NEWS_FEED, [
        'timeout' => 8,
        'http_errors' => FALSE,
      ]);
      if ($res->getStatusCode() === 200) {
        $xml = @simplexml_load_string((string) $res->getBody());
        if ($xml !== FALSE && isset($xml->channel->item)) {
          foreach ($xml->channel->item as $item) {
            $title = trim((string) $item->title);
            if ($title !== '') {
              $headlines[] = $title;
            }
            if (count($headlines) >= self::HEADLINE_LIMIT) {
              break;
            }
          }
        }
      }
    }
    catch (\Throwable $e) {
      // Return whatever we have (possibly none).
    }
    return $headlines;
  }

}
