<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Utility service to handle AINEVA regions data, coordinates, names and so on.
 */
class AinevaRegions {

  /**
   * @var bool
   */
  private $pointOnVertex = TRUE;

  /**
   * @var string
   */
  private $baseDir;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
    $this->baseDir = __DIR__ . '/../../vendor/eaws/eaws-regions/public/';
  }

  public function getRegions() {
    $regions = [];

    $finder = new Finder();
    $name_files = $finder->in($this->baseDir . 'micro-regions_names')->name('it.json');

    foreach ($name_files as $name_file) {
      $regions = json_decode($name_file->getContents(), TRUE);
    }

    return $regions;
  }

  /**
   * Get the region name for the given region ID.
   *
   * @param string $region_id
   *
   * @return string
   */
  public function getRegionName(string $region_id): string {
    $regions = $this->getRegions();
    return $regions[$region_id] ?: $region_id;
  }

  /**
   * Get the micro-region for the given coordinates.
   *
   * @param $x
   * @param $y
   *
   * @return array
   */
  public function getCoordinatesMicroRegions($x, $y): array {
    $point = [
      'x' => $x,
      'y' => $y
    ];

    $regions = [];
    $finder = new Finder();
    $finder->in($this->baseDir . 'micro-regions');
    foreach ($finder->files()->name('*.json')->contains('FeatureCollection') as $item) {
      $item_data = json_decode($item->getContents());
      foreach ($item_data->features as $feature) {
        $feature_id = $feature->properties->id;

        if ($feature->geometry->type === 'Polygon') {
          $in_polygon = $this->pointInPolygon($point, $feature->geometry->coordinates);
          if ($in_polygon !== 'outside') {
            $regions[] = $feature_id;
          }
        }
        elseif ($feature->geometry->type === 'MultiPolygon') {
          foreach ($feature->geometry->coordinates as $multipolygon) {
            foreach ($multipolygon as $polygon) {
              $in_polygon = $this->pointInPolygon($point, $polygon);
              if ($in_polygon !== 'outside') {
                $regions[] = $feature_id;
              }
            }
          }
        }
      }
    }

    return $regions;
  }

  /**
   * Check if the given point is in a polygon.
   *
   * @see https://assemblysys.com/php-point-in-polygon-algorithm/
   *
   * @param $point
   * @param $polygon
   * @param bool $pointOnVertex
   *
   * @return string
   */
  private function pointInPolygon($point, $polygon, true $pointOnVertex = TRUE): string {
    $this->pointOnVertex = $pointOnVertex;

    // Create an array with explicit x/y values.
    $vertices = [];
    foreach ($polygon as $vertex) {
      if (!is_array($vertex)) {
        return "outside";
      }

      $vertices[] = [
        'x' => $vertex[0],
        'y' => $vertex[1]
      ];
    }

    // Check if the point sits exactly on a vertex
    if ($this->pointOnVertex == TRUE and $this->pointOnVertex($point, $vertices) == TRUE) {
      return "vertex";
    }

    // Check if the point is inside the polygon or on the boundary
    $intersections = 0;
    $vertices_count = count($vertices);

    for ($i=1; $i < $vertices_count; $i++) {
      $vertex1 = $vertices[$i-1];
      $vertex2 = $vertices[$i];
      // Check if point is on a horizontal polygon boundary
      if ($vertex1['y'] == $vertex2['y'] and $vertex1['y'] == $point['y'] and $point['x'] > min($vertex1['x'], $vertex2['x']) and $point['x'] < max($vertex1['x'], $vertex2['x'])) {
        return "boundary";
      }
      if ($point['y'] > min($vertex1['y'], $vertex2['y']) and $point['y'] <= max($vertex1['y'], $vertex2['y']) and $point['x'] <= max($vertex1['x'], $vertex2['x']) and $vertex1['y'] != $vertex2['y']) {
        $xinters = ($point['y'] - $vertex1['y']) * ($vertex2['x'] - $vertex1['x']) / ($vertex2['y'] - $vertex1['y']) + $vertex1['x'];
        // Check if point is on the polygon boundary (other than horizontal)
        if ($xinters == $point['x']) {
          return "boundary";
        }
        if ($vertex1['x'] == $vertex2['x'] || $point['x'] <= $xinters) {
          $intersections++;
        }
      }
    }

    // If the number of edges we passed through is odd, then it's in the polygon.
    if ($intersections % 2 != 0) {
      return "inside";
    } else {
      return "outside";
    }
  }

  /**
   * Check if the point sits exactly on a vertex.
   *
   * @param $point
   * @param $vertices
   *
   * @return bool
   */
  private function pointOnVertex($point, $vertices): bool {
    foreach($vertices as $vertex) {
      if ($point == $vertex) {
        return TRUE;
      }
    }

    return FALSE;
  }
}
