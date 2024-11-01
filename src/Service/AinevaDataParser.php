<?php

namespace App\Service;



use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AinevaDataParser {

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * @var \Symfony\Contracts\HttpClient\HttpClientInterface
   */
  private $client;

  /**
   * @var string|null
   */
  private $content;

  /**
   * @var \App\Service\AinevaRegions
   */
  private $ainevaRegions;

  /**
   * @var \App\Service\nvDataNormalizer
   */
  private $normalizer;

  /**
   * @var \App\Service\CrawlerUtilities
   */
  private $crawlerUtilities;

  public function __construct(LoggerInterface $logger, HttpClientInterface $httpClient, AinevaRegions $ainevaRegions, nvDataNormalizer $normalizer, CrawlerUtilities $crawlerUtilities) {
    $this->logger = $logger;
    $this->client = $httpClient;
    $this->ainevaRegions = $ainevaRegions;
    $this->normalizer = $normalizer;
    $this->crawlerUtilities = $crawlerUtilities;
    $this->content = NULL;
    $response = $this->client->request('GET', $_ENV['AINEVA_URL']);
    $statusCode = $response->getStatusCode();
    if ($statusCode === 200) {
      $this->content = $response->getContent();
    }
  }

  /**
   * Retrieve the Aineva Bulletins for all the regions.
   *
   * @param bool $load
   *
   * @return array
   */
  public function getBulletins(bool $load = FALSE): array {
    $bulletins = [];
    if (!$this->content) {
      return $bulletins;
    }

    $crawler = new Crawler($this->content);
    $locRef = $crawler->filter("default|locRef");
    if ($locRef->count()) {
      $bulletins_data = $locRef->each(function($loc, $i) use ($load) {
        $loc_id = $loc->getNode(0)->getAttribute('xlink:href');
        $bulletin = $loc->closest('default|Bulletin');
        return [
          $loc_id,
          $load ? $bulletin : $bulletin->getNode(0)->getAttribute('gml:id')
        ];
      });

      foreach ($bulletins_data as $bulletin_data) {
        $bulletins[$bulletin_data[0]] = $bulletin_data[1];
      }
    }

    return $bulletins;
  }

  /**
   * Get the region data.
   *
   * @param string $region
   *
   * @return array
   */
  public function getRegionData(string $region): array {
    $data = [];
    $bulletin = $this->getRegionBulletin($region);
    if (!$bulletin) {
      return $data;
    }

    $data['region_id'] = $region;
    $data['region_name'] = $this->ainevaRegions->getRegionName($region);
    $data['report_time'] = $this->crawlerUtilities->getText($bulletin, 'default|dateTimeReport');
    $data['start_time'] = $this->crawlerUtilities->getText($bulletin, 'default|TimePeriod default|beginPosition');
    $data['end_time'] = $this->crawlerUtilities->getText($bulletin, 'default|TimePeriod default|endPosition');

    // Locations of the bulletin.
    $locations = $bulletin->filter('default|locRef')->each(function ($locRef, $i) {
      $loc_id = $this->crawlerUtilities->getAttribute($locRef, 'xlink:href');
      if (!$loc_id) {
        return ['id' => NULL, 'name' => NULL];
      }
      $loc_name = $this->ainevaRegions->getRegionName($loc_id);
      return ['id' => $loc_id, 'name' => $loc_name];
    });

    // Filter out empty locations
    $data['locations'] = array_filter($locations, function($item) {
      return !empty($item['id']);
    });

    // Generic danger ratings.
    $data['ratings'] = $bulletin->filter('default|DangerRating')->each(function (Crawler $dangerRating) {
      $elevation_data = $this->getElevationData($dangerRating->filter('default|validElevation'));

      // The only visible text is the danger rating, so we can use the entire
      // node text as the value.
      $danger_value = $dangerRating->text();
      return ['elevation' => $elevation_data['elevation'], 'direction' => $elevation_data['direction'], 'value' => $danger_value];
    });

    // Danger patterns.
    // @see https://bollettini.aineva.it/education/danger-patterns
    $danger_patterns = $bulletin->filter('default|DangerPattern')->each(function (Crawler $dangerPattern) {
      $danger_pattern_code = $this->crawlerUtilities->getText($dangerPattern);
      if (!$danger_pattern_code) {
        return ['code' => NULL, 'description' => NULL];
      }
      $danger_pattern = $this->dangerPatterns($danger_pattern_code);
      return ['code' => $danger_pattern_code, 'description' => $danger_pattern];
    });

    $data['danger_patterns'] = array_filter($danger_patterns, function ($item) {
      return !empty($item['code']);
    });

    // Avalanche problems.
    $data['problems'] = $bulletin->filter('default|AvProblem')->each(function (Crawler $avProblem) {
      $problem_type = $this->crawlerUtilities->getText($avProblem);
      $elevation_data = $this->getElevationData($avProblem->filter('default|validElevation'));

      $orientations = $avProblem->filter('default|validAspect')->each(function(Crawler $validAspect) {
        $aspect_attribute = $this->crawlerUtilities->getAttribute($validAspect, 'xlink:href');
        return str_replace('AspectRange_', '', $aspect_attribute);
      });
      return ['type' => $problem_type, 'orientations' => $orientations, 'elevation' => $elevation_data['elevation'], 'direction' => $elevation_data['direction']];
    });

    // Textual descriptions.
    $data['highlight'] = $this->crawlerUtilities->getText($bulletin, 'default|avActivityHighlights');
    $data['description'] = $this->crawlerUtilities->getText($bulletin, 'default|avActivityComment');
    $data['snow_description'] = $this->crawlerUtilities->getText($bulletin, 'default|snowpackStructureComment');

    // Forecast / tendency
    $data['tendency']['type'] = $this->crawlerUtilities->getText($bulletin, 'default|tendency default|type');
    $data['tendency']['from'] = $this->crawlerUtilities->getText($bulletin, 'default|tendency default|beginPosition');
    $data['tendency']['to'] = $this->crawlerUtilities->getText($bulletin, 'default|tendency default|endPosition');
    $data['tendency']['tendencyComment'] = $this->crawlerUtilities->getText($bulletin, 'default|snowpackStructureComment');

    $data['credits'] = 'AINEVA - https://bollettini.aineva.it/more/open-data';

    return $this->normalizer->normalizeAineva($data);
  }

  /**
   * Elevation data is handled always in the same way, and we need to manipulate
   * a bit the attribute value we get, to extract elevation and direction.
   *
   * @param \Symfony\Component\DomCrawler\Crawler $crawler
   *
   * @return array
   */
  private function getElevationData(Crawler $crawler): array {
    $elevation_attribute = $this->crawlerUtilities->getAttribute($crawler, 'xlink:href');

    // Remove the prefix.
    $elevation_attribute = str_replace('ElevationRange_', '', $elevation_attribute);

    // Get the direction.
    $direction_suffix = substr($elevation_attribute, -2);
    $direction = $direction_suffix === 'Hi' ? '>' : '<';

    // The remaining part is the elevation.
    $elevation = substr($elevation_attribute, 0, -2);

    return ['elevation' => $elevation, 'direction' => $direction];
  }

  /**
   * @param string $code
   *
   * @return string|string[]
   */
  private function dangerPatterns(string $code = ''): array|string {
    $map = [
      'DP1' => 'Strato debole persistente basale',
      'DP2' => 'Valanga per scivolamento di neve',
      'DP3' => 'Pioggia',
      'DP4' => 'Freddo su caldo / caldo su freddo',
      'DP5' => 'Neve dopo un lungo periodo di freddo',
      'DP6' => 'Neve fresca fredda a debole coesione e vento',
      'DP7' => 'Passaggio da poca a molta neve',
      'DP8' => 'Brina di superficie sepolta',
      'DP9' => 'Neve pallottolare coperta da neve fresca',
      'DP10' => 'Situazione primaverile',
    ];

    return $map[$code] ?: $map;
  }

  /**
   * Get the bulletin for the given region.
   *
   * @param string $region
   *
   * @return \Symfony\Component\DomCrawler\Crawler|null
   */
  private function getRegionBulletin(string $region): ?Crawler {
    if (!$this->content) {
      return NULL;
    }

    $bulletins = $this->getBulletins(TRUE);
    return $bulletins[$region] ?? NULL;
  }

  /**
   * Returns an array with the tree structure of all the regions with bulletins
   * available.
   *
   * @return array
   */
  public function getRegionsTree(): array {
    $navigation = [];

    $regions = $this->ainevaRegions->getRegions();
    $bulletins = $this->getBulletins();
    $regions_with_data = [];
    foreach ($regions as $region_id => $region_name) {
      if (empty($bulletins[$region_id])) {
        continue;
      }

      $regions_with_data[$region_id] = $region_name;
    }

    foreach ($regions_with_data as $region_id => $region_name) {
      $id_parts = explode('-', $region_id);
      $region_parents = [];
      for ($i = 0; $i < count($id_parts); $i++) {
        $region_parent = [];
        for ($j = 0; $j <= $i; $j++) {
          $region_parent[] = $id_parts[$j];
        }

        $region_parent_string = implode('-', $region_parent);
        if (!empty($regions[$region_parent_string])) {
          $region_parents[] = $region_parent_string;
          if ($i < count($id_parts) -1) {
            $region_parents[] = 'regions';
          }
        }
      }

      $this->setNestedValue($navigation, $region_parents, $region_name);
    }

    $navigation = $this->setNavigationRegionsNames($navigation, $regions);
    return $navigation;
  }

  /**
   * Set the value in the array structure based on the given parents.
   */
  private function setNestedValue(array &$array, array $parents, $value): void {
    $current = &$array;
    foreach ($parents as $parent) {

      // To handle the original input, if an item is not an array,
      // replace it with an array with the value as the first item.
      if (!is_array($current)) {
        $current = [$current];
      }

      if (!array_key_exists($parent, $current)) {
        $current[$parent] = [];
      }
      $current = &$current[$parent];
    }

    $current = $value;
  }

  /**
   * Set the name of the regions in the navigation tree array.
   *
   * @param array $navigation
   * @param array $regions
   *
   * @return array
   */
  private function setNavigationRegionsNames(array $navigation, array $regions): array {
    foreach ($navigation as $key => $value) {
      if (!empty($regions[$key])) {
        if (is_array($navigation[$key])) {
          $navigation[$key]['name'] = $regions[$key];
        }
      }

      if (!empty($value['regions'])) {
        $navigation[$key]['regions'] = $this->setNavigationRegionsNames($value['regions'], $regions);
      }
    }

    return $navigation;
  }
}
